<?php

use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Compose window.
 *
 * Nested inside the inbox page, never routed on its own. The inbox Compose
 * button dispatches `open-compose`, which is the only way in — that keeps the
 * modal state on this component instead of leaking into the parent.
 *
 * A one-to-one reply goes out on the transactional stream, never on the
 * marketing subdomain: mixing the two lets a campaign's reputation drag down
 * mail a client is actually waiting for.
 */
new
class extends Component
{
    public bool $open = false;

    public string $from = 'nima@kargah.dev';

    /** @var list<array{name: string, email: string}> */
    public array $to = [
        ['name' => 'Sam Okafor', 'email' => 'sam@northwind.example'],
    ];

    /** @var list<array{name: string, email: string}> */
    public array $cc = [];

    /** @var list<array{name: string, email: string}> */
    public array $bcc = [];

    public string $toInput = '';

    public string $ccInput = '';

    public string $bccInput = '';

    public bool $showCopyFields = false;

    #[Validate('required|string|max:255')]
    public string $subject = 'Re: Invoice INV-0041';

    public string $body = '';

    public bool $showSchedule = false;

    public string $scheduleAt = '';

    public function with(): array
    {
        return [
            'accounts' => [
                ['address' => 'nima@kargah.dev',        'label' => 'Nima Fazlipour — personal', 'stream' => 'Transactional (Resend)'],
                ['address' => 'hello@kargah.dev',       'label' => 'Kargah — general enquiries', 'stream' => 'Transactional (Resend)'],
                ['address' => 'invoices@kargah.dev',    'label' => 'Kargah — billing',           'stream' => 'Transactional (Resend)'],
                ['address' => 'nima@northwind.example', 'label' => 'Northwind (IMAP)',           'stream' => 'IMAP relay (SMTP2GO)'],
            ],
            'attachments' => [
                ['name' => 'INV-0041.pdf',            'size' => '184 KB', 'icon' => 'ki-file-down'],
                ['name' => 'timesheet-july.csv',      'size' => '11 KB',  'icon' => 'ki-file-sheet'],
            ],
            'tools' => [
                ['icon' => 'ki-text-bold',       'label' => 'Bold'],
                ['icon' => 'ki-text-italic',     'label' => 'Italic'],
                ['icon' => 'ki-text-underline',  'label' => 'Underline'],
                ['icon' => 'ki-arrow-up-right',            'label' => 'Insert link'],
                ['icon' => 'ki-text-number',     'label' => 'Numbered list'],
                ['icon' => 'ki-picture',         'label' => 'Insert image'],
                ['icon' => 'ki-code',            'label' => 'Code block'],
            ],
        ];
    }

    #[On('open-compose')]
    public function openCompose(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->showSchedule = false;
    }

    /** Push whatever is in the raw input onto the matching chip list. */
    public function addRecipient(string $field): void
    {
        // Parsing and validation land here in the backend phase.
    }

    public function removeRecipient(string $field, int $index): void
    {
        // Removal is a list splice once recipients are persisted.
    }

    public function removeAttachment(string $name): void
    {
        // Detaches the upload from the draft.
    }

    public function send(): void
    {
        // Queues the message on the transactional stream.
    }

    public function schedule(): void
    {
        // Stores the draft with a send_at timestamp for the scheduler.
    }

    public function saveDraft(): void
    {
        // Writes the draft to the local mail store and syncs it to IMAP Drafts.
    }

    public function discard(): void
    {
        $this->close();
    }
};

?>

<div class="kt-modal kt-modal-center z-50 {{ $open ? 'open' : '' }}" role="dialog" aria-modal="true" aria-label="Compose message">

    <div class="kt-modal-backdrop" wire:click="close"></div>

    <div class="kt-modal-content max-w-[860px] w-full max-h-[90vh]">

        <div class="kt-modal-header">
            <h3 class="kt-modal-title">New message</h3>
            <div class="flex items-center gap-1">
                <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Minimise">
                    <i class="ki-filled ki-minus text-base"></i>
                </button>
                <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Close" wire:click="close">
                    <i class="ki-filled ki-cross text-base"></i>
                </button>
            </div>
        </div>

        <div class="kt-modal-body flex flex-col gap-0 max-h-[calc(90vh-8rem)] kt-scrollable-y">

            {{-- From --}}
            <div class="flex items-center gap-3 py-2.5 border-b border-border">
                <label class="kt-form-label w-14 shrink-0 text-xs text-muted-foreground" for="compose-from">From</label>
                <select class="kt-select border-0 shadow-none focus:ring-0 px-0" id="compose-from" wire:model="from">
                    @foreach ($accounts as $a)
                        <option value="{{ $a['address'] }}">{{ $a['label'] }} — {{ $a['address'] }}</option>
                    @endforeach
                </select>
            </div>

            {{-- To --}}
            <div class="flex items-start gap-3 py-2.5 border-b border-border">
                <label class="kt-form-label w-14 shrink-0 text-xs text-muted-foreground mt-2" for="compose-to">To</label>
                <div class="flex flex-wrap items-center gap-1.5 grow min-w-0">
                    @foreach ($to as $i => $r)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-accent/60 border border-border ps-2.5 pe-1 py-1 text-xs text-mono max-w-full">
                            <span class="truncate">{{ $r['email'] }}</span>
                            <button wire:click="removeRecipient('to', {{ $i }})"
                                    class="kt-btn kt-btn-icon kt-btn-ghost size-4 rounded-full shrink-0"
                                    title="Remove {{ $r['email'] }}" aria-label="Remove {{ $r['email'] }}">
                                <i class="ki-filled ki-cross text-[9px]"></i>
                            </button>
                        </span>
                    @endforeach
                    <input type="text" id="compose-to" wire:model="toInput" wire:keydown.enter="addRecipient('to')"
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
                        @foreach ($cc as $i => $r)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-accent/60 border border-border ps-2.5 pe-1 py-1 text-xs text-mono max-w-full">
                                <span class="truncate">{{ $r['email'] }}</span>
                                <button wire:click="removeRecipient('cc', {{ $i }})"
                                        class="kt-btn kt-btn-icon kt-btn-ghost size-4 rounded-full shrink-0"
                                        title="Remove {{ $r['email'] }}" aria-label="Remove {{ $r['email'] }}">
                                    <i class="ki-filled ki-cross text-[9px]"></i>
                                </button>
                            </span>
                        @endforeach
                        <input type="text" id="compose-cc" wire:model="ccInput" wire:keydown.enter="addRecipient('cc')"
                               class="grow min-w-[160px] bg-transparent border-0 outline-none text-sm py-1"
                               placeholder="Everyone here sees each other">
                    </div>
                </div>

                {{-- Bcc --}}
                <div class="flex items-start gap-3 py-2.5 border-b border-border">
                    <label class="kt-form-label w-14 shrink-0 text-xs text-muted-foreground mt-2" for="compose-bcc">Bcc</label>
                    <div class="flex flex-wrap items-center gap-1.5 grow min-w-0">
                        @foreach ($bcc as $i => $r)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-accent/60 border border-border ps-2.5 pe-1 py-1 text-xs text-mono max-w-full">
                                <span class="truncate">{{ $r['email'] }}</span>
                                <button wire:click="removeRecipient('bcc', {{ $i }})"
                                        class="kt-btn kt-btn-icon kt-btn-ghost size-4 rounded-full shrink-0"
                                        title="Remove {{ $r['email'] }}" aria-label="Remove {{ $r['email'] }}">
                                    <i class="ki-filled ki-cross text-[9px]"></i>
                                </button>
                            </span>
                        @endforeach
                        <input type="text" id="compose-bcc" wire:model="bccInput" wire:keydown.enter="addRecipient('bcc')"
                               class="grow min-w-[160px] bg-transparent border-0 outline-none text-sm py-1"
                               placeholder="Hidden from the other recipients">
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
                <div class="flex flex-wrap items-center gap-0.5 pb-2 border-b border-border">
                    @foreach ($tools as $t)
                        <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="{{ $t['label'] }}" aria-label="{{ $t['label'] }}">
                            <i class="ki-filled {{ $t['icon'] }} text-sm"></i>
                        </button>
                    @endforeach
                    <span class="w-px h-5 bg-border mx-1"></span>
                    <button class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5 text-xs" title="Insert a saved reply">
                        <i class="ki-filled ki-notepad-edit text-sm"></i> Saved reply
                    </button>
                    <button class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5 text-xs" title="Insert signature">
                        <i class="ki-filled ki-user-square text-sm"></i> Signature
                    </button>
                </div>

                <textarea wire:model="body"
                          class="kt-textarea border-0 shadow-none focus:ring-0 min-h-[200px] resize-y w-full text-sm leading-relaxed"
                          placeholder="Write your message…"></textarea>
            </div>

            {{-- Attachments --}}
            <div class="pt-2 pb-1">
                <label for="compose-files"
                       class="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border hover:border-primary/50 bg-muted/30 py-5 px-4 text-center cursor-pointer transition-colors">
                    <i class="ki-filled ki-file-up text-xl text-muted-foreground"></i>
                    <span class="text-sm text-secondary-foreground">Drop files here, or <span class="text-primary font-medium">browse</span></span>
                    <span class="text-xs text-muted-foreground">Up to 25 MB in total — larger files are sent as a share link instead</span>
                    <input type="file" id="compose-files" class="hidden" multiple>
                </label>

                @if (count($attachments))
                    <div class="flex flex-col gap-1.5 mt-3">
                        @foreach ($attachments as $f)
                            <div class="flex items-center gap-3 rounded-lg border border-border px-3 py-2">
                                <i class="ki-filled {{ $f['icon'] }} text-base text-muted-foreground shrink-0"></i>
                                <span class="text-sm text-mono truncate grow">{{ $f['name'] }}</span>
                                <span class="text-xs text-muted-foreground shrink-0">{{ $f['size'] }}</span>
                                <button wire:click="removeAttachment('{{ $f['name'] }}')"
                                        class="kt-btn kt-btn-icon kt-btn-ghost size-6 shrink-0"
                                        title="Remove {{ $f['name'] }}" aria-label="Remove {{ $f['name'] }}">
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
                            Times are in Europe/London. The scheduler picks the message up on the next minute tick.
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
                    Routed via Resend on tx.kargah.dev
                </span>
                <button class="kt-btn kt-btn-ghost text-destructive gap-2" wire:click="discard">
                    <i class="ki-filled ki-trash"></i> Discard
                </button>
            </div>
        </div>

    </div>
</div>
