<?php

use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Mailbox\Jobs\SendCampaignChunk;
use Modules\Mailbox\Models\Campaign;
use Modules\Mailbox\Models\CampaignRecipient;
use Modules\Mailbox\Models\Contact;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Models\Suppression;
use Modules\Mailbox\Services\Delivery\CampaignSender;
use Modules\Mailbox\Services\Delivery\Delivery;
use Modules\Mailbox\Services\Delivery\MessageBuilder;
use Modules\Mailbox\Services\Delivery\OutboundMessage;
use Modules\Mailbox\Services\Delivery\PreFlight;
use Modules\Mailbox\Services\Delivery\SendFailed;

/**
 * Campaign builder.
 *
 * Four steps, all held in Livewire state so the wizard can be walked in either
 * direction without losing anything. The step lives in the query string, which
 * makes a half-finished campaign a shareable link.
 *
 * The review step is the important one, and it is not decoration. A bulk send
 * that leaves without SPF, DKIM and an unsubscribe link is filtered by every
 * large mailbox provider, so `PreFlight` refuses it — and the same object
 * answers both the checklist on screen and the refusal in `send()`, so the two
 * can never drift apart. A checklist that says 'pass' while the send refuses
 * would be worse than no checklist at all.
 *
 * The audience is materialised into `campaign_recipients` when the campaign is
 * saved, not resolved at send time. That is what makes the send safe: the
 * unique index on (campaign_id, email) can only protect a row that exists, and
 * a query re-evaluated per chunk would quietly change under a campaign that
 * takes ten minutes to go out.
 */
new
#[Title('New campaign — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** Local parts that belong to a shared mailbox rather than to a person. */
    private const ROLE_LOCAL_PARTS = ['info', 'admin', 'sales', 'support', 'contact', 'office', 'hello', 'billing', 'accounts', 'noreply', 'no-reply'];

    /** How recently is too recently to be sent to again, in days. */
    private const RECENT_DAYS = 14;

    public string $campaign = '';

    public ?int $campaignId = null;

    #[Url]
    public int $step = 1;

    // Step 1 — audience
    public string $list = '';

    public bool $excludeRecent = true;

    public bool $excludeUnsubscribed = true;

    public bool $excludeRoleAddresses = true;

    // Step 2 — content
    #[Validate('required|string|max:150')]
    public string $name = '';

    #[Validate('required|string|max:150')]
    public string $subject = '';

    public string $preheader = '';

    public string $body = '';

    public string $plainText = '';

    // Step 3 — delivery
    public ?int $providerId = null;

    public string $schedule = 'now';

    public string $sendAt = '';

    // Step 4 — review
    public string $testAddress = '';

    public function mount(string $campaign = ''): void
    {
        $this->campaign = $campaign;
        $this->sendAt = now()->addHour()->startOfHour()->format('Y-m-d\TH:i');
        $this->testAddress = (string) (auth()->user()?->email ?? '');

        $record = $campaign === '' ? null : Campaign::query()->find($campaign);

        if ($record === null) {
            $this->providerId = DeliveryProvider::query()->active()->orderBy('priority')->value('id');

            return;
        }

        $this->fill([
            'campaignId' => $record->id,
            'name' => (string) $record->name,
            'subject' => (string) $record->subject,
            'preheader' => (string) $record->preheader,
            'body' => (string) $record->body_html,
            'plainText' => (string) $record->body_text,
            'providerId' => $record->delivery_provider_id,
            'schedule' => $record->scheduled_for === null ? 'now' : 'later',
            'sendAt' => $record->scheduled_for?->format('Y-m-d\TH:i') ?? $this->sendAt,
        ]);
    }

    private function record(): ?Campaign
    {
        return $this->campaignId === null ? null : Campaign::query()->find($this->campaignId);
    }

    public function with(): array
    {
        $record = $this->record();
        $audience = $this->audience();

        return [
            'record' => $record,
            'steps' => [
                1 => ['label' => 'Audience', 'hint' => 'Who receives it', 'icon' => 'ki-people'],
                2 => ['label' => 'Content', 'hint' => 'What they read', 'icon' => 'ki-notepad-edit'],
                3 => ['label' => 'Delivery', 'hint' => 'How it goes out', 'icon' => 'ki-paper-plane'],
                4 => ['label' => 'Review', 'hint' => 'Pre-flight checks', 'icon' => 'ki-shield-tick'],
            ],
            'lists' => Contact::tagCounts(),
            'totalContacts' => Contact::query()->count(),
            'audience' => $audience,
            'excluded' => $this->exclusionCounts(),
            'providers' => DeliveryProvider::query()->active()->orderBy('priority')->get()
                ->each(fn (DeliveryProvider $p) => $p->rollQuotaWindow()),
            'tokens' => [
                ['token' => '{{first_name}}', 'sample' => 'Rita'],
                ['token' => '{{name}}', 'sample' => 'Rita Vance'],
                ['token' => '{{email}}', 'sample' => 'rita@studio-nord.example'],
                ['token' => Campaign::UNSUBSCRIBE_PLACEHOLDER, 'sample' => 'One-click opt-out link'],
            ],
            'checks' => $this->checks($audience->count()),
            'problems' => $record === null ? [] : app(PreFlight::class)->problems($record),
        ];
    }

    /* The audience ------------------------------------------------------------ */

    /**
     * Everyone this campaign would go to as things stand.
     *
     * The suppression list is applied here and is not a choice, unlike the rest
     * of the rules: a suppressed address is not a preference, it is an address
     * a provider has already reported as dead or unwelcome.
     *
     * @return \Illuminate\Support\Collection<int, Contact>
     */
    private function audience()
    {
        $contacts = Contact::query()
            ->when($this->list !== '', fn ($q) => $q->tagged($this->list))
            ->when($this->excludeUnsubscribed, fn ($q) => $q->subscribed())
            ->orderBy('id')
            ->get();

        $blocked = Suppression::among($contacts->pluck('email')->all());
        $recent = $this->recentlyContacted($contacts->pluck('email')->all());

        return $contacts
            ->reject(fn (Contact $c): bool => isset($blocked[Suppression::normalise((string) $c->email)]))
            ->reject(fn (Contact $c): bool => $this->excludeRoleAddresses && $this->isRoleAddress((string) $c->email))
            ->reject(fn (Contact $c): bool => $this->excludeRecent && isset($recent[Suppression::normalise((string) $c->email)]))
            ->values();
    }

    /**
     * How many each rule removes, for the panel beside them.
     *
     * @return array<string, int>
     */
    private function exclusionCounts(): array
    {
        $contacts = Contact::query()
            ->when($this->list !== '', fn ($q) => $q->tagged($this->list))
            ->get();

        $emails = $contacts->pluck('email')->all();
        $blocked = Suppression::among($emails);
        $recent = $this->recentlyContacted($emails);

        return [
            'suppressed' => $contacts->filter(fn (Contact $c): bool => isset($blocked[Suppression::normalise((string) $c->email)]))->count(),
            'unsubscribed' => $contacts->where('is_subscribed', false)->count(),
            'role' => $contacts->filter(fn (Contact $c): bool => $this->isRoleAddress((string) $c->email))->count(),
            'recent' => $contacts->filter(fn (Contact $c): bool => isset($recent[Suppression::normalise((string) $c->email)]))->count(),
        ];
    }

    private function isRoleAddress(string $email): bool
    {
        $local = mb_strtolower((string) strstr($email, '@', true));

        return in_array($local, self::ROLE_LOCAL_PARTS, true);
    }

    /**
     * Which of these addresses had a campaign message in the last fortnight.
     *
     * One query for the whole list rather than one per contact, because this is
     * asked twice on every keystroke of the audience step. Fourteen days
     * because two campaigns in one week is the commonest way a good list is
     * burnt.
     *
     * @param  list<string>  $emails
     * @return array<string, true>
     */
    private function recentlyContacted(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        return CampaignRecipient::query()
            ->whereIn('email', array_map(fn (string $e): string => Suppression::normalise($e), $emails))
            ->whereIn('status', CampaignRecipient::deliveredStatuses())
            ->where('sent_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->when($this->campaignId !== null, fn ($q) => $q->where('campaign_id', '!=', $this->campaignId))
            ->pluck('email')
            ->flip()
            ->map(fn (): bool => true)
            ->all();
    }

    /* The gate ---------------------------------------------------------------- */

    /**
     * The deliverability gate, as sentences rather than as a verdict.
     *
     * Every blocking row is derived from the same facts `PreFlight` refuses on,
     * so a row cannot read 'pass' while the send refuses. The advisory rows are
     * what a checklist is actually for: a quota smaller than the campaign and
     * an untested layout are both worth knowing about, and neither is a reason
     * to stop.
     *
     * @return list<array{label: string, detail: string, status: string, blocking: bool}>
     */
    private function checks(int $audienceSize): array
    {
        $provider = $this->providerId === null ? null : DeliveryProvider::query()->find($this->providerId);
        $remaining = $provider?->remainingQuota() ?? 0;
        $missing = $provider?->missingCredentials() ?? [];

        $hasUnsubscribe = str_contains($this->body, Campaign::UNSUBSCRIBE_PLACEHOLDER)
            || str_contains($this->plainText, Campaign::UNSUBSCRIBE_PLACEHOLDER);

        return [
            [
                'label' => 'SPF on the sending domain',
                'detail' => $provider === null
                    ? 'No provider is chosen, so there is no domain to check.'
                    : ($provider->spf_verified
                        ? $provider->sending_domain.' is signed off for SPF on the provider page.'
                        : 'SPF is not verified for '.($provider->sending_domain ?: $provider->label())
                          .'. Receivers cannot tell that this provider is allowed to send as the domain.'),
                'status' => $provider?->spf_verified ? 'pass' : 'fail',
                'blocking' => true,
            ],
            [
                'label' => 'DKIM signing key',
                'detail' => $provider === null
                    ? 'No provider is chosen, so there is no key to check.'
                    : ($provider->dkim_verified
                        ? 'The key at mail._domainkey.'.$provider->sending_domain.' is signed off on the provider page.'
                        : 'DKIM is not verified. Nothing in the message is signed, so a forwarded copy cannot be checked '
                          .'and DMARC has nothing to align against. Gmail and Yahoo have required it of bulk senders since 2024.'),
                'status' => $provider?->dkim_verified ? 'pass' : 'fail',
                'blocking' => true,
            ],
            [
                'label' => 'Unsubscribe link in the body',
                'detail' => $hasUnsubscribe
                    ? 'The body carries '.Campaign::UNSUBSCRIBE_PLACEHOLDER.', which becomes a one-click link unique to '
                      .'each recipient, alongside the List-Unsubscribe headers every message gets.'
                    : 'The body has no unsubscribe link. Put '.Campaign::UNSUBSCRIBE_PLACEHOLDER.' where it should appear '
                      .'— without it the only way out a recipient has is the spam button, which costs far more.',
                'status' => $hasUnsubscribe ? 'pass' : 'fail',
                'blocking' => true,
            ],
            [
                'label' => 'Provider credentials',
                'detail' => $provider === null
                    ? 'No provider is chosen for this campaign.'
                    : ($missing === []
                        ? $provider->label().' has everything it needs to send.'
                        : $provider->label().' is missing '.implode(' and ', $missing).'.'),
                'status' => $provider !== null && $missing === [] ? 'pass' : 'fail',
                'blocking' => true,
            ],
            [
                'label' => 'Recipients on the list',
                'detail' => $audienceSize === 0
                    ? 'Nothing is left after the rules on the audience step.'
                    : $audienceSize.' '.str('recipient')->plural($audienceSize).', written as one row each when the campaign is saved.',
                'status' => $audienceSize > 0 ? 'pass' : 'fail',
                'blocking' => true,
            ],
            [
                'label' => 'Suppression list applied',
                'detail' => $this->exclusionCounts()['suppressed'].' '.str('address')->plural($this->exclusionCounts()['suppressed'])
                    .' removed from this audience, and every remaining one is checked again at send time in case a bounce arrives in between.',
                'status' => 'pass',
                'blocking' => false,
            ],
            [
                'label' => 'Quota for the whole campaign',
                'detail' => $provider === null
                    ? 'No provider is chosen.'
                    : ($remaining === PHP_INT_MAX
                        ? $provider->label().' has no quota set, so nothing is throttled.'
                        : $audienceSize.' recipients against '.$remaining.' left on '.$provider->label()
                          .($remaining >= $audienceSize
                              ? '.'
                              : ' — the remainder moves to whichever provider still has some, and the report shows the split.')),
                'status' => $provider === null ? 'fail' : ($remaining >= $audienceSize ? 'pass' : 'warn'),
                'blocking' => false,
            ],
            [
                'label' => 'Seed test send',
                'detail' => 'Send one copy to yourself before the list gets it. It is the only way to see how the layout '
                    .'renders and where the message lands.',
                'status' => 'warn',
                'blocking' => false,
            ],
        ];
    }

    /* Navigation -------------------------------------------------------------- */

    // The stepper and the "Step n of 4" counter are both on screen while you
    // move, so navigation announces itself and needs no toast.

    public function goToStep(int $step): void
    {
        $this->step = max(1, min(4, $step));
    }

    public function next(): void
    {
        $this->goToStep($this->step + 1);
    }

    public function back(): void
    {
        $this->goToStep($this->step - 1);
    }

    /* Content ----------------------------------------------------------------- */

    /**
     * Put a token where the caret is not.
     *
     * Appended rather than inserted, and the toast says so. A textarea's caret
     * position is not something the server knows, and pretending otherwise
     * would drop the token in the wrong place often enough to be worse than
     * useless.
     */
    public function insertToken(string $token): void
    {
        $this->body = rtrim($this->body)."\n".$token;

        $this->toastSuccess('Added '.$token.' to the end of the body', 'Move it to where you want it.');
    }

    /** Flatten the HTML body into the text/plain alternative. */
    public function generatePlainText(): void
    {
        if (trim($this->body) === '') {
            $this->toastWarning('Nothing to flatten', 'Write the body first.');

            return;
        }

        // Block-closing tags become line breaks *before* the tags come out,
        // because stripping naively glues the last word of one paragraph to the
        // first word of the next.
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $this->body) ?? $this->body;
        $text = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);

        // The placeholder survives, because the pre-flight looks at both parts
        // and the plain-text copy is the half people read on a phone.
        if (! str_contains($text, Campaign::UNSUBSCRIBE_PLACEHOLDER)) {
            $text .= "\n\nUnsubscribe: ".Campaign::UNSUBSCRIBE_PLACEHOLDER;
        }

        $this->plainText = $text;

        $this->toastSuccess(
            'Plain-text part written',
            'A message with only an HTML part is one of the cheapest spam signals there is.',
        );
    }

    /* Saving ------------------------------------------------------------------ */

    /**
     * Save the campaign and rebuild its audience.
     *
     * Idempotent: recipients are written with `firstOrNew` on (campaign_id,
     * email), which is the unique index the migration added, so saving twice
     * adds nobody twice. Rows are dropped only while they are still `pending` —
     * a message that has gone out cannot be un-sent by editing a filter.
     */
    public function saveDraft(): bool
    {
        $this->validate();

        $campaign = $this->record() ?? new Campaign(['status' => Campaign::DRAFT]);

        if ($campaign->exists && ! $campaign->isEditable()) {
            $this->toastError(
                'This campaign cannot be edited',
                'It is '.mb_strtolower($campaign->statusLabel()).'. Pause it first, or make a new one.',
            );

            return false;
        }

        $campaign->fill([
            'name' => $this->name,
            'subject' => $this->subject,
            'preheader' => $this->preheader ?: null,
            'body_html' => $this->body ?: null,
            'body_text' => $this->plainText ?: null,
            'delivery_provider_id' => $this->providerId,
            'scheduled_for' => $this->schedule === 'later' && $this->sendAt !== '' ? Carbon::parse($this->sendAt) : null,
            'created_by' => $campaign->created_by ?? auth()->id(),
        ])->save();

        $this->campaignId = $campaign->id;

        [$added, $dropped] = $this->syncAudience($campaign);

        $campaign->syncCounters();

        $total = $campaign->recipients()->count();

        $this->toastSuccess(
            'Saved as a draft',
            $total.' '.str('recipient')->plural($total).' on the list'
            .($added > 0 ? ', '.$added.' added' : '')
            .($dropped > 0 ? ', '.$dropped.' dropped' : '').'.',
        );

        return true;
    }

    /**
     * Bring `campaign_recipients` into line with the chosen audience.
     *
     * @return array{0: int, 1: int} How many were added and how many dropped.
     */
    private function syncAudience(Campaign $campaign): array
    {
        $wanted = [];
        $added = 0;

        foreach ($this->audience() as $contact) {
            $email = Suppression::normalise((string) $contact->email);
            $wanted[] = $email;

            $recipient = CampaignRecipient::query()->firstOrNew([
                'campaign_id' => $campaign->id,
                'email' => $email,
            ]);

            if (! $recipient->exists) {
                $added++;
                $recipient->status = CampaignRecipient::PENDING;
            }

            $recipient->fill(['contact_id' => $contact->id, 'name' => $contact->name])->save();
        }

        // Only `pending` rows are dropped. Anything sent, failed or suppressed
        // is a record of something that happened, and editing a filter must not
        // erase it.
        $dropped = $campaign->recipients()
            ->where('status', CampaignRecipient::PENDING)
            ->when($wanted !== [], fn ($q) => $q->whereNotIn('email', $wanted))
            ->delete();

        return [$added, $dropped];
    }

    /* Sending ----------------------------------------------------------------- */

    /**
     * Send one copy to a seed address, through the real driver.
     *
     * Not a simulation: it goes out exactly as the campaign will, tokens
     * substituted and headers attached, because the only useful test send is
     * one identical to the real thing. It creates no lasting recipient row, so
     * the report cannot count it as a delivery.
     */
    public function sendTest(): void
    {
        $campaign = $this->record();

        if ($campaign === null) {
            $this->toastError(
                'Save it first',
                'A test send needs the campaign to exist so the body can be rendered exactly as it will go out.',
            );

            return;
        }

        $address = trim($this->testAddress);

        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $this->toastError('That is not an email address', 'Put the address the seed copy should go to.');

            return;
        }

        $provider = $campaign->provider;

        if ($provider === null) {
            $this->toastError('No provider', 'Choose a delivery provider on the delivery step first.');

            return;
        }

        $driver = app(Delivery::class)->driverFor($provider->driver);

        if ($driver === null || ($reason = $driver->unavailableReason($provider)) !== null) {
            $this->toastError(
                $provider->label().' cannot send',
                $reason ?? 'Kargah has no driver for '.$provider->driver.'.',
            );

            return;
        }

        // A throwaway row so the message builder can mint the same tokens and
        // headers a real recipient gets, deleted before anything is sent. Its
        // address is on the sending domain rather than the seed's, because the
        // row exists only to be an id.
        $seed = CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'email' => 'seed-'.uniqid().'@'.($provider->sending_domain ?: 'kargah.local'),
            'name' => 'Seed test',
            'status' => CampaignRecipient::PENDING,
        ]);

        $rendered = app(MessageBuilder::class)->build($campaign, $seed, $provider);

        $seed->delete();

        try {
            $driver->send($provider, new OutboundMessage(
                toEmail: $address,
                toName: 'Seed test',
                fromEmail: $rendered->fromEmail,
                fromName: $rendered->fromName,
                replyTo: $rendered->replyTo,
                subject: '[Test] '.$rendered->subject,
                html: $rendered->html,
                text: $rendered->text,
                messageId: $rendered->messageId,
                headers: $rendered->headers,
            ));
        } catch (SendFailed $e) {
            $this->toastError('The test send failed', $e->getMessage());

            return;
        }

        $provider->recordSend();

        $this->toastSuccess(
            'Test sent to '.$address,
            'It carries the same headers and the same substituted body the list will get, through '.$provider->label().'.',
        );
    }

    /**
     * Start the campaign, or refuse and say exactly what stops it.
     *
     * The refusal comes from `PreFlight` rather than from the checklist, so
     * what is enforced and what is displayed cannot drift apart — and pressing
     * this twice cannot get past a check that is still failing.
     */
    public function send(): void
    {
        if (! $this->saveDraft()) {
            return;
        }

        $campaign = $this->record();

        if ($campaign === null) {
            return;
        }

        $problems = app(CampaignSender::class)->start($campaign);

        if ($problems !== []) {
            $this->toastError('This campaign cannot go out yet', app(PreFlight::class)->refusal($problems));

            return;
        }

        $outstanding = $campaign->outstandingCount();

        SendCampaignChunk::dispatch($campaign->id);

        $this->flashToast(
            'success',
            'Sending has begun',
            $outstanding.' '.str('recipient')->plural($outstanding).' to go, in chunks, as the queue runs.',
        );

        $this->redirect(route('mail.campaign-show', $campaign->id), navigate: true);
    }
};

?>

<div class="flex flex-col gap-5">

    @php
        $tone = [
            'pass' => ['icon' => 'ki-check-circle',  'text' => 'text-success',     'badge' => 'kt-badge-success',     'label' => 'Pass'],
            'warn' => ['icon' => 'ki-information-2', 'text' => 'text-warning',     'badge' => 'kt-badge-warning',     'label' => 'Warning'],
            'fail' => ['icon' => 'ki-cross-circle',  'text' => 'text-destructive', 'badge' => 'kt-badge-destructive', 'label' => 'Fail'],
        ];
        $blockingFailures = collect($checks)->where('status', 'fail')->where('blocking', true)->count();
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">{{ $record ? 'Edit campaign' : 'New campaign' }}</h1>
            <p class="text-sm text-secondary-foreground mt-1">Set the audience, write it, then check it clears the deliverability gates.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('mail.campaigns') }}" class="kt-btn kt-btn-ghost gap-2">
                <i class="ki-filled ki-arrow-left"></i> Campaigns
            </a>
            <button class="kt-btn kt-btn-outline gap-2" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft">
                <span wire:loading.remove wire:target="saveDraft">Save draft</span>
                <span wire:loading wire:target="saveDraft" class="inline-flex items-center gap-1.5">
                    <i class="ki-filled ki-loading animate-spin"></i> Saving…
                </span>
            </button>
        </div>
    </div>

    {{-- Step indicator --}}
    <div class="kt-card">
        <div class="kt-card-content p-0">
            <ol class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 divide-y lg:divide-y-0 lg:divide-x sm:divide-x divide-border">
                @foreach ($steps as $n => $s)
                    <li>
                        <button wire:click="goToStep({{ $n }})"
                                aria-current="{{ $step === $n ? 'step' : 'false' }}"
                                class="w-full flex items-center gap-3 px-5 py-4 text-start transition-colors hover:bg-accent/40 {{ $step === $n ? 'bg-accent/60' : '' }}">
                            <span class="inline-flex items-center justify-center size-9 rounded-full shrink-0 text-sm font-semibold
                                {{ $step > $n ? 'bg-success/15 text-success' : ($step === $n ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground') }}">
                                @if ($step > $n)
                                    <i class="ki-filled ki-check text-base"></i>
                                @else
                                    {{ $n }}
                                @endif
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium {{ $step === $n ? 'text-mono' : 'text-secondary-foreground' }}">{{ $s['label'] }}</span>
                                <span class="block text-xs text-muted-foreground truncate">{{ $s['hint'] }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>

    {{-- Step 1 — Audience --}}
    @if ($step === 1)
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">

            <div class="xl:col-span-2 flex flex-col gap-5">
                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">Pick a list</h3>
                        <a href="{{ route('mail.contact-import') }}" class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                            <i class="ki-filled ki-file-up text-sm"></i> Import contacts
                        </a>
                    </div>
                    <div class="kt-card-content p-4 flex flex-col gap-3">
                        <label class="flex items-start gap-3 rounded-lg border p-4 cursor-pointer transition-colors
                            {{ $list === '' ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40' }}">
                            <input type="radio" class="kt-radio mt-0.5" name="campaign-list" value="" wire:model.live="list">
                            <span class="grow min-w-0">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium text-mono">Everyone</span>
                                    <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $totalContacts }} {{ str('contact')->plural($totalContacts) }}</span>
                                </span>
                                <span class="block text-xs text-muted-foreground mt-1">Every contact in the table, before the rules below.</span>
                            </span>
                        </label>

                        @forelse ($lists as $tag => $count)
                            <label wire:key="list-{{ $tag }}"
                                   class="flex items-start gap-3 rounded-lg border p-4 cursor-pointer transition-colors
                                   {{ $list === $tag ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40' }}">
                                <input type="radio" class="kt-radio mt-0.5" name="campaign-list" value="{{ $tag }}" wire:model.live="list">
                                <span class="grow min-w-0">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-medium text-mono">{{ $tag }}</span>
                                        <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $count }} {{ str('contact')->plural($count) }}</span>
                                    </span>
                                </span>
                            </label>
                        @empty
                            <div class="flex flex-col items-center justify-center text-center py-10">
                                <i class="ki-filled ki-people text-4xl text-muted-foreground mb-3"></i>
                                <p class="text-sm text-secondary-foreground">No lists yet. Import a CSV to build your first one.</p>
                                <a href="{{ route('mail.contact-import') }}" class="kt-btn kt-btn-primary gap-2 mt-4">
                                    <i class="ki-filled ki-file-up"></i> Import contacts
                                </a>
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Exclusion rules</h3></div>
                    <div class="kt-card-content p-4 flex flex-col gap-1">

                        <label class="flex items-start gap-3 rounded-lg px-3 py-3 hover:bg-accent/40 cursor-pointer transition-colors">
                            <input type="checkbox" class="kt-checkbox mt-0.5" wire:model.live="excludeUnsubscribed">
                            <span class="grow min-w-0">
                                <span class="block text-sm text-mono">Unsubscribed contacts</span>
                                <span class="block text-xs text-muted-foreground mt-0.5">
                                    People who asked to stop hearing from you. Leaving them in breaches PECR whatever the list says.
                                </span>
                            </span>
                            <span class="kt-badge kt-badge-sm kt-badge-outline shrink-0">−{{ $excluded['unsubscribed'] }}</span>
                        </label>

                        <label class="flex items-start gap-3 rounded-lg px-3 py-3 hover:bg-accent/40 cursor-pointer transition-colors">
                            <input type="checkbox" class="kt-checkbox mt-0.5" wire:model.live="excludeRoleAddresses">
                            <span class="grow min-w-0">
                                <span class="block text-sm text-mono">Role addresses</span>
                                <span class="block text-xs text-muted-foreground mt-0.5">
                                    info@, admin@, sales@ and the rest. Shared mailboxes complain far more often than personal ones.
                                </span>
                            </span>
                            <span class="kt-badge kt-badge-sm kt-badge-outline shrink-0">−{{ $excluded['role'] }}</span>
                        </label>

                        <label class="flex items-start gap-3 rounded-lg px-3 py-3 hover:bg-accent/40 cursor-pointer transition-colors">
                            <input type="checkbox" class="kt-checkbox mt-0.5" wire:model.live="excludeRecent">
                            <span class="grow min-w-0">
                                <span class="block text-sm text-mono">Contacted in the last 14 days</span>
                                <span class="block text-xs text-muted-foreground mt-0.5">
                                    Two campaigns in a fortnight is the commonest way a good list is burnt.
                                </span>
                            </span>
                            <span class="kt-badge kt-badge-sm kt-badge-outline shrink-0">−{{ $excluded['recent'] }}</span>
                        </label>

                        <div class="flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-3 mt-2">
                            <i class="ki-filled ki-shield-cross text-destructive mt-0.5 shrink-0"></i>
                            <span class="grow min-w-0">
                                <span class="block text-sm text-mono">Suppressed addresses</span>
                                <span class="block text-xs text-muted-foreground mt-0.5">
                                    Always, and not a choice. Checked again at send time in case a bounce arrives in between.
                                </span>
                            </span>
                            <span class="kt-badge kt-badge-sm kt-badge-destructive shrink-0">−{{ $excluded['suppressed'] }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="kt-card xl:sticky xl:top-24">
                <div class="kt-card-header"><h3 class="kt-card-title">Who will receive it</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4">
                    <div>
                        <div class="text-3xl font-semibold text-mono">{{ number_format($audience->count()) }}</div>
                        <div class="text-xs text-muted-foreground mt-1">recipients after suppression and exclusions</div>
                    </div>

                    <dl class="flex flex-col gap-2 text-sm border-t border-border pt-4">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-secondary-foreground">Suppressed</dt>
                            <dd class="text-destructive">−{{ $excluded['suppressed'] }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-secondary-foreground">Unsubscribed</dt>
                            <dd class="{{ $excludeUnsubscribed ? 'text-destructive' : 'text-muted-foreground' }}">
                                {{ $excludeUnsubscribed ? '−'.$excluded['unsubscribed'] : 'kept' }}
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-secondary-foreground">Role addresses</dt>
                            <dd class="{{ $excludeRoleAddresses ? 'text-warning' : 'text-muted-foreground' }}">
                                {{ $excludeRoleAddresses ? '−'.$excluded['role'] : 'kept' }}
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-secondary-foreground">Contacted recently</dt>
                            <dd class="{{ $excludeRecent ? 'text-warning' : 'text-muted-foreground' }}">
                                {{ $excludeRecent ? '−'.$excluded['recent'] : 'kept' }}
                            </dd>
                        </div>
                    </dl>

                    <p class="text-xs text-muted-foreground border-t border-border pt-4 leading-relaxed">
                        The audience is written into the campaign when you save, one row per person. That row is what
                        stops anybody being sent to twice — a query re-run per chunk would change under a send that
                        takes ten minutes.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Step 2 — Content --}}
    @if ($step === 2)
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">

            <div class="xl:col-span-2 flex flex-col gap-5">
                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Headers</h3></div>
                    <div class="kt-card-content p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2 flex flex-col">
                            <label class="kt-form-label" for="campaign-name">Campaign name</label>
                            <input type="text" id="campaign-name" wire:model="name"
                                   class="kt-input @error('name') border-destructive @enderror"
                                   placeholder="Resume — design agencies UK">
                            @error('name')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            <span class="text-xs text-muted-foreground mt-1">Only you see this.</span>
                        </div>

                        <div class="md:col-span-2 flex flex-col">
                            <label class="kt-form-label" for="campaign-subject">Subject</label>
                            <input type="text" id="campaign-subject" wire:model.live.debounce.300ms="subject"
                                   class="kt-input @error('subject') border-destructive @enderror"
                                   placeholder="Say the useful thing first">
                            @error('subject')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            <span class="text-xs text-muted-foreground mt-1">
                                {{ mb_strlen($subject) }} characters — most mobile clients cut the subject at about 40.
                            </span>
                        </div>

                        <div class="md:col-span-2 flex flex-col">
                            <label class="kt-form-label" for="campaign-preheader">Preheader</label>
                            <input type="text" id="campaign-preheader" wire:model.live.debounce.300ms="preheader"
                                   class="kt-input" placeholder="The line shown after the subject in the list view">
                            <span class="text-xs text-muted-foreground mt-1">Hidden in the body, shown in the inbox preview.</span>
                        </div>
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">Body</h3>
                        <span class="text-xs text-muted-foreground">HTML, written as it will be sent</span>
                    </div>
                    <div class="kt-card-content p-5">
                        <textarea wire:model.live.debounce.700ms="body"
                                  class="kt-textarea min-h-[280px] w-full font-mono text-xs leading-relaxed"
                                  placeholder="&lt;p&gt;Hello …&lt;/p&gt;"></textarea>
                        <p class="text-xs text-muted-foreground mt-2">
                            It must carry <code class="text-xs">{{ \Modules\Mailbox\Models\Campaign::UNSUBSCRIBE_PLACEHOLDER }}</code>
                            somewhere. Kargah replaces it with a signed one-click link unique to each recipient, and the
                            pre-flight refuses a body without it.
                        </p>
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">Plain-text fallback</h3>
                        <button class="kt-btn kt-btn-sm kt-btn-outline gap-1.5" wire:click="generatePlainText"
                                wire:loading.attr="disabled" wire:target="generatePlainText">
                            <span wire:loading.remove wire:target="generatePlainText" class="inline-flex items-center gap-1.5">
                                <i class="ki-filled ki-arrows-circle text-sm"></i> Generate from body
                            </span>
                            <span wire:loading wire:target="generatePlainText" class="inline-flex items-center gap-1.5">
                                <i class="ki-filled ki-loading animate-spin"></i> Generating…
                            </span>
                        </button>
                    </div>
                    <div class="kt-card-content p-5">
                        <textarea wire:model="plainText" class="kt-textarea min-h-[140px] w-full font-mono text-xs leading-relaxed"
                                  placeholder="Hello …"></textarea>
                        <p class="text-xs text-muted-foreground mt-2">
                            An HTML-only message scores worse with spam filters. Every message goes out multipart, and
                            the text part is what a screen reader and a plain client use.
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-5 xl:sticky xl:top-24">
                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Personalisation</h3></div>
                    <div class="kt-card-content p-4 flex flex-col gap-1.5">
                        @foreach ($tokens as $t)
                            <button wire:click="insertToken('{{ $t['token'] }}')" wire:key="token-{{ $loop->index }}"
                                    class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 hover:bg-accent/40 text-start transition-colors">
                                <code class="text-xs text-primary">{{ $t['token'] }}</code>
                                <span class="text-xs text-muted-foreground truncate">{{ $t['sample'] }}</span>
                            </button>
                        @endforeach
                        <p class="text-xs text-muted-foreground px-3 pt-2 border-t border-border mt-1 leading-relaxed">
                            A token with nothing behind it falls back to the address rather than to an empty string —
                            'Hello ,' is the single most recognisable mark of a bad mail merge.
                        </p>
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Inbox preview</h3></div>
                    <div class="kt-card-content p-4">
                        <div class="rounded-lg border border-border p-3">
                            <div class="text-sm font-medium text-mono truncate">
                                {{ $providers->firstWhere('id', $providerId)?->from_name ?: '—' }}
                            </div>
                            <div class="text-sm text-mono truncate mt-0.5">{{ $subject ?: '—' }}</div>
                            <div class="text-xs text-muted-foreground truncate mt-0.5">{{ $preheader ?: '—' }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Step 3 — Delivery --}}
    @if ($step === 3)
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">

            <div class="xl:col-span-2 flex flex-col gap-5">
                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Provider</h3></div>
                    <div class="kt-card-content p-5 flex flex-col gap-3">

                        @forelse ($providers as $p)
                            <label wire:key="provider-{{ $p->id }}"
                                   class="flex items-start gap-3 rounded-lg border p-4 cursor-pointer transition-colors
                                   {{ $providerId === $p->id ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40' }}">
                                <input type="radio" class="kt-radio mt-0.5" name="provider" value="{{ $p->id }}" wire:model.live="providerId">
                                <span class="min-w-0 grow">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-medium text-mono">{{ $p->label() }}</span>
                                        <span class="kt-badge kt-badge-sm {{ $p->isAuthenticated() ? 'kt-badge-success' : 'kt-badge-destructive' }}">
                                            {{ $p->isAuthenticated() ? 'SPF and DKIM in place' : 'DNS not signed off' }}
                                        </span>
                                    </span>
                                    <span class="block text-xs text-muted-foreground mt-1">
                                        {{ $p->sending_domain ?: 'no sending domain' }} ·
                                        {{ $p->remainingQuota() === PHP_INT_MAX ? 'unmetered' : $p->remainingQuota().' left today' }} ·
                                        health {{ $p->health_score }}%
                                    </span>
                                </span>
                            </label>
                        @empty
                            <div class="flex flex-col items-center justify-center text-center py-10">
                                <i class="ki-filled ki-router text-4xl text-muted-foreground mb-3"></i>
                                <p class="text-sm text-secondary-foreground">No delivery provider is switched on.</p>
                                <a href="{{ route('mail.providers') }}" class="kt-btn kt-btn-primary gap-2 mt-4">
                                    <i class="ki-filled ki-plus"></i> Add one
                                </a>
                            </div>
                        @endforelse

                        <p class="text-xs text-muted-foreground border-t border-border pt-4 leading-relaxed">
                            The router uses this one while it has quota. When it runs out the remainder moves to
                            whichever provider still has some, ordered by health and then by priority, and the campaign
                            report shows which carried what.
                        </p>
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Schedule</h3></div>
                    <div class="kt-card-content p-5 flex flex-col gap-3">
                        <label class="flex items-start gap-3 rounded-lg border p-4 cursor-pointer transition-colors
                            {{ $schedule === 'now' ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40' }}">
                            <input type="radio" class="kt-radio mt-0.5" name="schedule" value="now" wire:model.live="schedule">
                            <span>
                                <span class="block text-sm font-medium text-mono">Start as soon as it is approved</span>
                                <span class="block text-xs text-muted-foreground mt-1">The first chunk leaves on the next queue run.</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 rounded-lg border p-4 cursor-pointer transition-colors
                            {{ $schedule === 'later' ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40' }}">
                            <input type="radio" class="kt-radio mt-0.5" name="schedule" value="later" wire:model.live="schedule">
                            <span class="grow">
                                <span class="block text-sm font-medium text-mono">Schedule for later</span>
                                <span class="block text-xs text-muted-foreground mt-1">
                                    The scheduler picks it up within a minute of that moment. Recipient time zones are
                                    ignored; everything runs on the application's own clock.
                                </span>
                                @if ($schedule === 'later')
                                    <input type="datetime-local" class="kt-input mt-3 max-w-xs" wire:model="sendAt" aria-label="Send at">
                                @endif
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="kt-card xl:sticky xl:top-24">
                <div class="kt-card-header"><h3 class="kt-card-title">Throughput</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-secondary-foreground">Recipients</span>
                        <span class="text-mono font-medium">{{ number_format($audience->count()) }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-secondary-foreground">Chunk size</span>
                        <span class="text-mono font-medium">{{ config('mailbox.sending.chunk_size', 50) }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-secondary-foreground">Roughly</span>
                        <span class="text-mono font-medium">
                            {{ max(1, (int) ceil($audience->count() / max(1, (int) config('mailbox.sending.chunk_size', 50)))) }} minutes
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground border-t border-border pt-4 leading-relaxed">
                        One chunk a minute is what a host without a daemon can do: the queue runs from cron with
                        <code class="text-xs">--stop-when-empty</code>. Anything over a provider's cap is held for the
                        next window rather than dropped.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Step 4 — Review --}}
    @if ($step === 4)
        <div class="flex flex-col gap-5">

            @if ($problems !== [])
                <div class="kt-card bg-destructive/5 border-destructive/30">
                    <div class="kt-card-content flex items-start gap-3 p-4">
                        <i class="ki-filled ki-shield-cross text-destructive text-lg mt-0.5 shrink-0"></i>
                        <div class="text-sm text-secondary-foreground">
                            <strong class="text-mono">The pre-flight refuses the saved campaign as it stands.</strong>
                            <ul class="list-disc ps-5 mt-2 flex flex-col gap-1">
                                @foreach ($problems as $problem)
                                    <li>{{ $problem }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">

                <div class="xl:col-span-2 kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">Pre-flight checks</h3>
                        <span class="kt-badge kt-badge-sm {{ $blockingFailures ? 'kt-badge-destructive' : 'kt-badge-success' }}">
                            {{ collect($checks)->where('status', 'pass')->count() }} of {{ count($checks) }} passing
                        </span>
                    </div>
                    <div class="kt-card-content p-0 divide-y divide-border">
                        @foreach ($checks as $c)
                            <div class="flex items-start gap-3 px-5 py-4" wire:key="check-{{ $loop->index }}">
                                <i class="ki-filled {{ $tone[$c['status']]['icon'] }} text-lg {{ $tone[$c['status']]['text'] }} mt-0.5 shrink-0"></i>
                                <div class="grow min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-medium text-mono">{{ $c['label'] }}</span>
                                        <span class="kt-badge kt-badge-sm {{ $tone[$c['status']]['badge'] }}">
                                            {{ $tone[$c['status']]['label'] }}
                                        </span>
                                        @unless ($c['blocking'])
                                            <span class="kt-badge kt-badge-sm kt-badge-outline">Advisory</span>
                                        @endunless
                                    </div>
                                    <p class="text-xs text-muted-foreground mt-1 leading-relaxed">{{ $c['detail'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-col gap-5 xl:sticky xl:top-24">

                    <div class="kt-card">
                        <div class="kt-card-header"><h3 class="kt-card-title">Seed test</h3></div>
                        <div class="kt-card-content p-5 flex flex-col gap-3">
                            <input type="email" class="kt-input" wire:model="testAddress" aria-label="Seed address"
                                   placeholder="you@example.com">
                            <button class="kt-btn kt-btn-outline w-full justify-center gap-2"
                                    wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">
                                <span wire:loading.remove wire:target="sendTest" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-paper-plane"></i> Send one copy
                                </span>
                                <span wire:loading wire:target="sendTest" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-loading animate-spin"></i> Sending…
                                </span>
                            </button>
                            <p class="text-xs text-muted-foreground leading-relaxed">
                                It goes out through the real provider with the same headers and the same substituted
                                body, and leaves no recipient row — so the report cannot count it as a delivery.
                            </p>
                        </div>
                    </div>

                    <div class="kt-card">
                        <div class="kt-card-header"><h3 class="kt-card-title">Summary</h3></div>
                        <div class="kt-card-content p-5 flex flex-col gap-3 text-sm">
                            <div class="flex items-start justify-between gap-3">
                                <span class="text-secondary-foreground shrink-0">List</span>
                                <span class="text-mono text-end truncate">{{ $list === '' ? 'Everyone' : $list }}</span>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <span class="text-secondary-foreground shrink-0">Recipients</span>
                                <span class="text-mono text-end">{{ number_format($audience->count()) }}</span>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <span class="text-secondary-foreground shrink-0">Subject</span>
                                <span class="text-mono text-end truncate">{{ $subject ?: '—' }}</span>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <span class="text-secondary-foreground shrink-0">From</span>
                                <span class="text-mono text-end truncate">
                                    {{ $providers->firstWhere('id', $providerId)?->from_email ?? '—' }}
                                </span>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <span class="text-secondary-foreground shrink-0">Starts</span>
                                <span class="text-mono text-end">
                                    {{ $schedule === 'now' ? 'Immediately' : ($sendAt ? str_replace('T', ' ', $sendAt) : '—') }}
                                </span>
                            </div>

                            <button class="kt-btn kt-btn-primary w-full justify-center gap-2 mt-2"
                                    wire:click="send" wire:loading.attr="disabled" wire:target="send"
                                    @disabled($blockingFailures > 0)>
                                <span wire:loading.remove wire:target="send" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-paper-plane"></i>
                                    {{ $schedule === 'now' ? 'Send campaign' : 'Schedule campaign' }}
                                </span>
                                <span wire:loading wire:target="send" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-loading animate-spin"></i> Queueing…
                                </span>
                            </button>

                            @if ($blockingFailures > 0)
                                <p class="text-xs text-destructive">
                                    {{ $blockingFailures }} blocking {{ str('check')->plural($blockingFailures) }} must pass before this can go out.
                                </p>
                            @else
                                <p class="text-xs text-muted-foreground leading-relaxed">
                                    The pre-flight runs again on the way through, so pressing this cannot get past a
                                    check that is still failing. Once queued the campaign can be paused, but messages
                                    already handed to a provider cannot be recalled.
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Wizard navigation --}}
    <div class="flex items-center justify-between gap-3">
        <button class="kt-btn kt-btn-outline gap-2" wire:click="back" @disabled($step === 1)>
            <i class="ki-filled ki-arrow-left"></i> Back
        </button>
        <span class="text-xs text-muted-foreground">Step {{ $step }} of {{ count($steps) }}</span>
        <button class="kt-btn kt-btn-primary gap-2" wire:click="next" @disabled($step === 4)>
            Next <i class="ki-filled ki-arrow-right"></i>
        </button>
    </div>
</div>
