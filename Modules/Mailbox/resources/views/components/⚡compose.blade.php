<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Mailbox\Jobs\SendDirectMessage;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Models\Suppression;
use Modules\Mailbox\Services\Delivery\Delivery;
use Modules\Mailbox\Services\Delivery\OutboundMessage;
use Modules\Mailbox\Services\Delivery\Router;
use Modules\Mailbox\Services\Delivery\SendFailed;

/**
 * Compose window.
 *
 * Nested inside the inbox page, never routed on its own. The inbox Compose
 * button dispatches `open-compose`, which is the only way in — that keeps the
 * modal state on this component instead of leaking into the parent.
 *
 * A one-to-one reply goes out through the same router a campaign uses, but it
 * is deliberately *not* a campaign: there is no recipient row, no claim and no
 * unsubscribe link, because a reply to a client is not bulk mail and a
 * `List-Unsubscribe` header on it would be wrong. What it does share is the
 * suppression list — an address somebody asked never to be contacted at is
 * still an address Kargah will not write to, whoever is typing.
 *
 * Choose a provider on a transactional sending domain rather than the marketing
 * one wherever both exist: mixing the two lets a campaign's reputation drag down
 * mail a client is actually waiting for.
 */
new
class extends Component
{
    use InteractsWithToasts;
    use WithFileUploads;

    /** How much can be attached in total, in kilobytes — most mailbox providers refuse more. */
    private const MAX_ATTACHMENT_KB = 25_000;

    public bool $open = false;

    public ?int $providerId = null;

    /** @var list<string> */
    public array $to = [];

    /** @var list<string> */
    public array $cc = [];

    /** @var list<string> */
    public array $bcc = [];

    public string $toInput = '';

    public string $ccInput = '';

    public string $bccInput = '';

    public bool $showCopyFields = false;

    #[Validate('required|string|max:255')]
    public string $subject = '';

    #[Validate('required|string')]
    public string $body = '';

    /** @var list<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $files = [];

    public bool $showSchedule = false;

    public string $scheduleAt = '';

    /**
     * Restore whatever was left in the draft.
     *
     * Session-backed rather than a table, and that is the honest scope of it: a
     * draft here survives closing the window and navigating away, and does not
     * survive signing out. Anything stronger belongs in the mail store the IMAP
     * side owns, not in a second one this window invented.
     */
    public function mount(): void
    {
        $draft = session('mailbox.compose-draft');

        if (is_array($draft)) {
            $this->fill(array_intersect_key($draft, array_flip(['providerId', 'to', 'cc', 'bcc', 'subject', 'body'])));
        }
    }

    public function with(): array
    {
        $providers = DeliveryProvider::query()
            ->active()
            ->whereNotNull('from_email')
            ->orderBy('priority')
            ->get()
            ->filter(fn (DeliveryProvider $p): bool => $p->hasCredentials())
            ->values();

        return [
            'providers' => $providers,
            'blocked' => Suppression::among(array_merge($this->to, $this->cc, $this->bcc)),
            'attachedSize' => collect($this->files)->sum(fn ($file): int => (int) ($file->getSize() / 1024)),
            'maxKb' => self::MAX_ATTACHMENT_KB,
        ];
    }

    #[On('open-compose')]
    public function openCompose(): void
    {
        $this->open = true;
    }

    // Opening and closing the window are their own confirmation — the modal
    // appears or it does not — so neither says anything.

    public function close(): void
    {
        $this->rememberDraft();

        $this->open = false;
        $this->showSchedule = false;
    }

    /** Push whatever is in the raw input onto the matching chip list. */
    public function addRecipient(string $field): void
    {
        if (! in_array($field, ['to', 'cc', 'bcc'], true)) {
            return;
        }

        $input = $field.'Input';
        $added = [];
        $refused = [];

        // Comma and semicolon both, because a list pasted out of a mail client
        // uses whichever that client felt like.
        foreach (preg_split('/[,;]/', (string) $this->{$input}) ?: [] as $candidate) {
            $address = mb_strtolower(trim($candidate));

            if ($address === '') {
                continue;
            }

            if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $refused[] = $address;

                continue;
            }

            if (! in_array($address, $this->{$field}, true)) {
                $this->{$field}[] = $address;
                $added[] = $address;
            }
        }

        $this->{$input} = '';

        if ($refused !== []) {
            $this->toastError(
                count($refused) === 1 ? 'That is not an email address' : count($refused).' were not email addresses',
                implode(', ', $refused).' — nothing was added for '.(count($refused) === 1 ? 'it' : 'them').'.',
            );

            return;
        }

        foreach ($added as $address) {
            if (Suppression::blocks($address)) {
                $this->toastWarning(
                    $address.' is on the suppression list',
                    'It stays in the field so you can see it, but the message will not be sent to it.',
                );
            }
        }
    }

    public function removeRecipient(string $field, int $index): void
    {
        if (! in_array($field, ['to', 'cc', 'bcc'], true)) {
            return;
        }

        $list = $this->{$field};

        unset($list[$index]);

        $this->{$field} = array_values($list);
    }

    public function removeAttachment(int $index): void
    {
        $files = $this->files;

        unset($files[$index]);

        $this->files = array_values($files);
    }

    /**
     * Send now, through whichever provider the router picks.
     *
     * Cc and Bcc become separate messages rather than one with several
     * recipients. That is not a simplification: a per-recipient message is what
     * lets one suppressed address be skipped without dropping the rest, and it
     * is the only way a Bcc stays hidden when a provider's API has no bcc
     * field.
     */
    public function send(): void
    {
        $this->validate();

        [$provider, $driver] = $this->resolveProvider();

        if ($provider === null) {
            return;
        }

        $recipients = $this->recipients();

        if ($recipients === []) {
            $this->toastError('Nobody to send to', 'Add at least one address in the To field.');

            return;
        }

        $sent = 0;
        $skipped = [];
        $failure = null;

        foreach ($recipients as $address) {
            if (Suppression::blocks($address)) {
                $skipped[] = $address;

                continue;
            }

            try {
                $driver->send($provider, $this->message($provider, $address));
            } catch (SendFailed $e) {
                $failure ??= $e->getMessage();

                continue;
            }

            $provider->recordSend();
            $sent++;
        }

        if ($sent === 0) {
            $this->toastError(
                'Nothing was sent',
                $failure ?? 'Every address was on the suppression list: '.implode(', ', $skipped).'.',
            );

            return;
        }

        $this->clearDraft();
        $this->open = false;

        $this->toastSuccess(
            'Sent to '.$sent.' '.str('address')->plural($sent),
            'Carried by '.$provider->label().'.'
            .($skipped === [] ? '' : ' '.implode(', ', $skipped).' was skipped as suppressed.')
            .($failure === null ? '' : ' One address failed: '.$failure),
        );
    }

    /**
     * Hand the message to the scheduler instead of the wire.
     *
     * The message is built now and travels with the job, so what is sent at
     * nine is what was written at three — not whatever the window looks like by
     * then. The suppression list is checked again at send time, because an
     * address can be blocked in between.
     */
    public function schedule(): void
    {
        $this->validate();

        $when = $this->scheduleAt === '' ? null : Carbon::parse($this->scheduleAt);

        if ($when === null || $when->isPast()) {
            $this->toastError('That time has already passed', 'Pick a moment in the future, or press send.');

            return;
        }

        [$provider, ] = $this->resolveProvider();

        if ($provider === null) {
            return;
        }

        $recipients = array_values(array_filter($this->recipients(), fn (string $a): bool => ! Suppression::blocks($a)));

        if ($recipients === []) {
            $this->toastError('Nobody to send to', 'Add at least one address that is not on the suppression list.');

            return;
        }

        foreach ($recipients as $address) {
            SendDirectMessage::dispatch($this->message($provider, $address), $provider->id)->delay($when);
        }

        $this->clearDraft();
        $this->open = false;
        $this->showSchedule = false;

        $this->toastSuccess(
            'Scheduled for '.$when->format('j M Y, H:i'),
            count($recipients).' '.str('message')->plural(count($recipients))
            .' queued. The scheduler picks them up on the tick after that time.',
        );
    }

    /** Keep what was typed, without sending it. */
    public function saveDraft(): void
    {
        $this->rememberDraft();

        $this->open = false;

        $this->toastSuccess(
            'Draft kept',
            'It is here when you open compose again. Signing out clears it — nothing was written to the mail store.',
        );
    }

    public function discard(): void
    {
        $this->clearDraft();
        $this->reset('to', 'cc', 'bcc', 'subject', 'body', 'files', 'toInput', 'ccInput', 'bccInput');

        $this->open = false;
        $this->showSchedule = false;

        $this->toastSuccess('Draft discarded', 'Nothing was sent.');
    }

    /**
     * The provider and its driver, or a toast saying why there is neither.
     *
     * @return array{0: DeliveryProvider|null, 1: \Modules\Mailbox\Services\Delivery\Mailer|null}
     */
    private function resolveProvider(): array
    {
        $router = app(Router::class);

        $preferred = $this->providerId === null ? null : DeliveryProvider::query()->find($this->providerId);

        $provider = $router->pick($preferred);

        if ($provider === null) {
            $this->toastError('There is nothing to send through', $router->refusalReason());

            return [null, null];
        }

        $driver = app(Delivery::class)->driverFor($provider->driver);

        if ($driver === null) {
            $this->toastError('No driver', 'Kargah has no implementation for '.$provider->driver.'.');

            return [null, null];
        }

        if ($reason = $driver->unavailableReason($provider)) {
            $this->toastError($provider->label().' cannot send', $reason);

            return [null, null];
        }

        return [$provider, $driver];
    }

    /** @return list<string> */
    private function recipients(): array
    {
        return array_values(array_unique(array_merge($this->to, $this->cc, $this->bcc)));
    }

    /**
     * One message for one address.
     *
     * No `List-Unsubscribe`: this is a reply, not bulk mail, and offering to
     * unsubscribe somebody from a conversation they are part of is nonsense.
     * `Reply-To` is the plain from address for the same reason — there is no
     * campaign for a reply to thread back to.
     */
    private function message(DeliveryProvider $provider, string $address): OutboundMessage
    {
        $body = trim($this->body);

        return new OutboundMessage(
            toEmail: $address,
            toName: null,
            fromEmail: (string) $provider->from_email,
            fromName: $provider->from_name,
            replyTo: null,
            subject: $this->subject,
            html: nl2br(e($body)),
            text: $body,
            messageId: '<direct-'.Str::uuid()->toString().'@'.($provider->sending_domain ?: 'kargah.local').'>',
            headers: [],
            attachments: $this->attachmentPaths(),
        );
    }

    /**
     * The uploads, as paths a mailer can read.
     *
     * Livewire's temporary files are on disk already, so nothing here writes
     * anything — which matters, because `AttachmentService` is the only thing
     * in Kargah allowed to put a file somewhere permanent and a compose window
     * must not become a second one.
     *
     * @return list<array{path: string, name: string, mime: string|null}>
     */
    private function attachmentPaths(): array
    {
        $out = [];

        foreach ($this->files as $file) {
            $out[] = [
                'path' => $file->getRealPath(),
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
            ];
        }

        return $out;
    }

    private function rememberDraft(): void
    {
        session()->put('mailbox.compose-draft', [
            'providerId' => $this->providerId,
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'subject' => $this->subject,
            'body' => $this->body,
        ]);
    }

    private function clearDraft(): void
    {
        session()->forget('mailbox.compose-draft');
    }
};

?>

<div class="kt-modal kt-modal-center z-50 {{ $open ? 'open' : '' }}" role="dialog" aria-modal="true" aria-label="Compose message">

    <div class="kt-modal-backdrop" wire:click="close"></div>

    <div class="kt-modal-content max-w-[860px] w-full max-h-[90vh]">

        <div class="kt-modal-header">
            <h3 class="kt-modal-title">New message</h3>
            <div class="flex items-center gap-1">
                <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Close and keep the draft"
                        aria-label="Close and keep the draft" wire:click="close">
                    <i class="ki-filled ki-cross text-base"></i>
                </button>
            </div>
        </div>

        <div class="kt-modal-body flex flex-col gap-0 max-h-[calc(90vh-8rem)] kt-scrollable-y">

            @if ($providers->isEmpty())
                <div class="rounded-lg border border-warning/30 bg-warning/5 p-4 mb-3 text-sm text-secondary-foreground">
                    <strong class="text-mono">There is nothing to send through yet.</strong>
                    Add a delivery provider and fill in its credentials before composing —
                    <a href="{{ route('mail.providers') }}" class="text-primary">delivery providers</a>.
                </div>
            @endif

            {{-- From --}}
            <div class="flex items-center gap-3 py-2.5 border-b border-border">
                <label class="kt-form-label w-14 shrink-0 text-xs text-muted-foreground" for="compose-from">From</label>
                <select class="kt-select border-0 shadow-none focus:ring-0 px-0" id="compose-from" wire:model="providerId">
                    <option value="">Whichever provider has quota</option>
                    @foreach ($providers as $p)
                        <option value="{{ $p->id }}">{{ $p->from_email }} — {{ $p->label() }}</option>
                    @endforeach
                </select>
            </div>

            {{-- To --}}
            <div class="flex items-start gap-3 py-2.5 border-b border-border">
                <label class="kt-form-label w-14 shrink-0 text-xs text-muted-foreground mt-2" for="compose-to">To</label>
                <div class="flex flex-wrap items-center gap-1.5 grow min-w-0">
                    @foreach ($to as $i => $address)
                        <span wire:key="to-{{ $i }}-{{ $address }}"
                              class="inline-flex items-center gap-1.5 rounded-full border ps-2.5 pe-1 py-1 text-xs text-mono max-w-full
                                     {{ isset($blocked[$address]) ? 'bg-destructive/10 border-destructive/40' : 'bg-accent/60 border-border' }}">
                            <span class="truncate" @if (isset($blocked[$address])) title="Suppressed: {{ $blocked[$address]->reasonLabel() }}" @endif>{{ $address }}</span>
                            <button wire:click="removeRecipient('to', {{ $i }})"
                                    class="kt-btn kt-btn-icon kt-btn-ghost size-4 rounded-full shrink-0"
                                    title="Remove {{ $address }}" aria-label="Remove {{ $address }}">
                                <i class="ki-filled ki-cross text-[9px]"></i>
                            </button>
                        </span>
                    @endforeach
                    <input type="text" id="compose-to" wire:model="toInput" wire:keydown.enter="addRecipient('to')"
                           wire:blur="addRecipient('to')"
                           class="grow min-w-[160px] bg-transparent border-0 outline-none text-sm py-1"
                           placeholder="{{ count($to) ? 'Add another…' : 'name@example.com' }}">
                </div>
                @unless ($showCopyFields)
                    <button wire:click="$set('showCopyFields', true)" class="kt-btn kt-btn-sm kt-btn-ghost shrink-0 text-xs">
                        Cc / Bcc
                    </button>
                @endunless
            </div>

            @if ($showCopyFields)
                {{-- Cc --}}
                <div class="flex items-start gap-3 py-2.5 border-b border-border">
                    <label class="kt-form-label w-14 shrink-0 text-xs text-muted-foreground mt-2" for="compose-cc">Cc</label>
                    <div class="flex flex-wrap items-center gap-1.5 grow min-w-0">
                        @foreach ($cc as $i => $address)
                            <span wire:key="cc-{{ $i }}-{{ $address }}" class="inline-flex items-center gap-1.5 rounded-full bg-accent/60 border border-border ps-2.5 pe-1 py-1 text-xs text-mono max-w-full">
                                <span class="truncate">{{ $address }}</span>
                                <button wire:click="removeRecipient('cc', {{ $i }})"
                                        class="kt-btn kt-btn-icon kt-btn-ghost size-4 rounded-full shrink-0"
                                        title="Remove {{ $address }}" aria-label="Remove {{ $address }}">
                                    <i class="ki-filled ki-cross text-[9px]"></i>
                                </button>
                            </span>
                        @endforeach
                        <input type="text" id="compose-cc" wire:model="ccInput" wire:keydown.enter="addRecipient('cc')"
                               wire:blur="addRecipient('cc')"
                               class="grow min-w-[160px] bg-transparent border-0 outline-none text-sm py-1"
                               placeholder="Everyone here sees each other">
                    </div>
                </div>

                {{-- Bcc --}}
                <div class="flex items-start gap-3 py-2.5 border-b border-border">
                    <label class="kt-form-label w-14 shrink-0 text-xs text-muted-foreground mt-2" for="compose-bcc">Bcc</label>
                    <div class="flex flex-wrap items-center gap-1.5 grow min-w-0">
                        @foreach ($bcc as $i => $address)
                            <span wire:key="bcc-{{ $i }}-{{ $address }}" class="inline-flex items-center gap-1.5 rounded-full bg-accent/60 border border-border ps-2.5 pe-1 py-1 text-xs text-mono max-w-full">
                                <span class="truncate">{{ $address }}</span>
                                <button wire:click="removeRecipient('bcc', {{ $i }})"
                                        class="kt-btn kt-btn-icon kt-btn-ghost size-4 rounded-full shrink-0"
                                        title="Remove {{ $address }}" aria-label="Remove {{ $address }}">
                                    <i class="ki-filled ki-cross text-[9px]"></i>
                                </button>
                            </span>
                        @endforeach
                        <input type="text" id="compose-bcc" wire:model="bccInput" wire:keydown.enter="addRecipient('bcc')"
                               wire:blur="addRecipient('bcc')"
                               class="grow min-w-[160px] bg-transparent border-0 outline-none text-sm py-1"
                               placeholder="Each of these gets its own copy">
                    </div>
                </div>
            @endif

            {{-- Subject --}}
            <div class="flex items-center gap-3 py-2.5 border-b border-border">
                <label class="kt-form-label w-14 shrink-0 text-xs text-muted-foreground" for="compose-subject">Subject</label>
                <input type="text" id="compose-subject" wire:model="subject"
                       class="grow bg-transparent border-0 outline-none text-sm font-medium text-mono py-1 @error('subject') text-destructive @enderror"
                       placeholder="What is this about?">
            </div>
            @error('subject')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror

            {{-- Body --}}
            <div class="pt-3">
                <textarea wire:model="body"
                          class="kt-textarea border-0 shadow-none focus:ring-0 min-h-[200px] resize-y w-full text-sm leading-relaxed @error('body') border-destructive @enderror"
                          placeholder="Write your message…"></textarea>
                @error('body')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                <p class="text-xs text-muted-foreground mt-1">
                    Sent as plain text with an HTML copy alongside it. A message with only an HTML part is one of the
                    cheapest spam signals there is.
                </p>
            </div>

            {{-- Attachments --}}
            <div class="pt-2 pb-1">
                <label for="compose-files"
                       class="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border hover:border-primary/50 bg-muted/30 py-5 px-4 text-center cursor-pointer transition-colors">
                    <i class="ki-filled ki-file-up text-xl text-muted-foreground"></i>
                    <span class="text-sm text-secondary-foreground">Drop files here, or <span class="text-primary font-medium">browse</span></span>
                    <span class="text-xs text-muted-foreground">
                        {{ number_format($attachedSize / 1024, 1) }} MB of {{ number_format($maxKb / 1024) }} MB used —
                        larger files are better sent as a link
                    </span>
                    <input type="file" id="compose-files" class="hidden" multiple wire:model="files">
                </label>

                <div wire:loading wire:target="files" class="text-xs text-muted-foreground mt-2 inline-flex items-center gap-1.5">
                    <i class="ki-filled ki-loading animate-spin"></i> Uploading…
                </div>

                @if (count($files))
                    <div class="flex flex-col gap-1.5 mt-3">
                        @foreach ($files as $i => $file)
                            <div class="flex items-center gap-3 rounded-lg border border-border px-3 py-2" wire:key="file-{{ $i }}">
                                <i class="ki-filled ki-document text-base text-muted-foreground shrink-0"></i>
                                <span class="text-sm text-mono truncate grow">{{ $file->getClientOriginalName() }}</span>
                                <span class="text-xs text-muted-foreground shrink-0">{{ number_format($file->getSize() / 1024) }} KB</span>
                                <button wire:click="removeAttachment({{ $i }})"
                                        class="kt-btn kt-btn-icon kt-btn-ghost size-6 shrink-0"
                                        title="Remove {{ $file->getClientOriginalName() }}"
                                        aria-label="Remove {{ $file->getClientOriginalName() }}">
                                    <i class="ki-filled ki-cross text-xs"></i>
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($showSchedule)
                <div class="mt-3 rounded-lg border border-info/30 bg-info/5 p-4 flex flex-col sm:flex-row sm:items-end gap-3">
                    <div class="grow">
                        <label class="kt-form-label text-xs" for="compose-schedule">Send at</label>
                        <input type="datetime-local" id="compose-schedule" class="kt-input w-full" wire:model="scheduleAt">
                        <p class="text-xs text-muted-foreground mt-1.5">
                            The scheduler picks the message up on the next minute tick. What is written now is what goes
                            out then, whatever this window looks like by that point.
                        </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button class="kt-btn kt-btn-sm kt-btn-ghost" wire:click="$set('showSchedule', false)">Cancel</button>
                        <button class="kt-btn kt-btn-sm kt-btn-primary gap-2" wire:click="schedule" wire:loading.attr="disabled" wire:target="schedule">
                            <span wire:loading.remove wire:target="schedule">Confirm schedule</span>
                            <span wire:loading wire:target="schedule" class="inline-flex items-center gap-1.5">
                                <i class="ki-filled ki-loading animate-spin"></i> Scheduling…
                            </span>
                        </button>
                    </div>
                </div>
            @endif

        </div>

        <div class="kt-modal-footer flex-wrap gap-3">
            <div class="flex items-center gap-2">
                <button class="kt-btn kt-btn-primary gap-2" wire:click="send" wire:loading.attr="disabled" wire:target="send">
                    <span wire:loading.remove wire:target="send" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-paper-plane"></i> Send
                    </span>
                    <span wire:loading wire:target="send" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Sending…
                    </span>
                </button>
                <button class="kt-btn kt-btn-outline gap-2" wire:click="$toggle('showSchedule')">
                    <i class="ki-filled ki-time"></i> Schedule
                </button>
                <button class="kt-btn kt-btn-ghost gap-2" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft">
                    <span wire:loading.remove wire:target="saveDraft">Save draft</span>
                    <span wire:loading wire:target="saveDraft" class="inline-flex items-center gap-1.5">
                        <i class="ki-filled ki-loading animate-spin"></i> Saving…
                    </span>
                </button>
            </div>

            <div class="flex items-center gap-3">
                <span class="hidden sm:inline text-xs text-muted-foreground">
                    Suppressed addresses are skipped, whoever is typing
                </span>
                <button class="kt-btn kt-btn-ghost text-destructive gap-2" wire:click="discard">
                    <i class="ki-filled ki-trash"></i> Discard
                </button>
            </div>
        </div>

    </div>
</div>
