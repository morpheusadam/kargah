<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * Campaign builder.
 *
 * Four steps, all held in Livewire state so the wizard can be walked in either
 * direction without losing anything. The step lives in the query string, which
 * makes a half-finished campaign a shareable link.
 *
 * The review step is the important one: a bulk send that leaves without SPF,
 * DKIM and a List-Unsubscribe header will be filtered by every large mailbox
 * provider, so the checklist runs before the send button unlocks.
 */
new
#[Title('New campaign — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url]
    public int $step = 1;

    // Step 1 — audience
    public string $list = 'agencies-uk';

    public bool $excludeRecent = true;

    public bool $excludeNonOpeners = false;

    public bool $excludeRoleAddresses = true;

    public bool $excludeSoftBounced = true;

    // Step 2 — content
    #[Validate('required|string|max:150')]
    public string $subject = 'Freelance front-end capacity from mid-August';

    public string $preheader = 'Two weeks free from 18 August — Laravel and Livewire work.';

    public string $fromName = 'Nima Fazlipour';

    public string $fromAddress = 'nima@news.kargah.dev';

    public string $body = '';

    public string $plainText = '';

    // Step 3 — delivery
    public string $routing = 'auto';

    public string $pinnedProvider = 'brevo';

    public int $sendRate = 120;

    public string $sendingDomain = 'news.kargah.dev';

    public string $schedule = 'later';

    public string $sendAt = '2026-08-05T09:00';

    public function with(): array
    {
        return [
            'steps' => [
                1 => ['label' => 'Audience', 'hint' => 'Who receives it',   'icon' => 'ki-people'],
                2 => ['label' => 'Content',  'hint' => 'What they read',    'icon' => 'ki-notepad-edit'],
                3 => ['label' => 'Delivery', 'hint' => 'How it goes out',   'icon' => 'ki-paper-plane'],
                4 => ['label' => 'Review',   'hint' => 'Pre-flight checks', 'icon' => 'ki-shield-tick'],
            ],
            'lists' => [
                ['key' => 'agencies-uk', 'name' => 'Agencies UK',    'total' => 240, 'suppressed' => 6, 'unsubscribed' => 4, 'net' => 230],
                ['key' => 'startups-de', 'name' => 'Startups DE',    'total' => 310, 'suppressed' => 2, 'unsubscribed' => 1, 'net' => 307],
                ['key' => 'leads-raw',   'name' => 'Crawler leads',  'total' => 0,   'suppressed' => 0, 'unsubscribed' => 0, 'net' => 0],
            ],
            'exclusions' => [
                ['model' => 'excludeRecent',        'label' => 'Contacted in the last 14 days',   'note' => 'Stops the same address getting two campaigns in a fortnight.', 'removes' => 12],
                ['model' => 'excludeNonOpeners',    'label' => 'Never opened the last 3 sends',   'note' => 'Cold addresses drag down engagement and hurt inbox placement.', 'removes' => 31],
                ['model' => 'excludeRoleAddresses', 'label' => 'Role addresses (info@, admin@)',  'note' => 'Shared mailboxes complain more often than personal ones.',    'removes' => 18],
                ['model' => 'excludeSoftBounced',   'label' => 'Three or more soft bounces',      'note' => 'A mailbox that keeps deferring is usually full or gone.',      'removes' => 5],
            ],
            'senders' => [
                'nima@news.kargah.dev'   => 'nima@news.kargah.dev — marketing subdomain',
                'hello@news.kargah.dev'  => 'hello@news.kargah.dev — marketing subdomain',
            ],
            'tokens' => [
                ['token' => '{first_name}',  'sample' => 'Rita'],
                ['token' => '{last_name}',   'sample' => 'Vance'],
                ['token' => '{company}',     'sample' => 'Acme Studio'],
                ['token' => '{city}',        'sample' => 'Manchester'],
                ['token' => '{unsubscribe}', 'sample' => 'One-click opt-out link'],
            ],
            'providers' => [
                ['key' => 'brevo',   'name' => 'Brevo',   'stream' => 'Marketing',      'remaining' => 300, 'health' => 98, 'domain' => 'news.kargah.dev'],
                ['key' => 'ses',     'name' => 'Amazon SES', 'stream' => 'Marketing',   'remaining' => 200, 'health' => 96, 'domain' => 'news.kargah.dev'],
                ['key' => 'mailgun', 'name' => 'Mailgun', 'stream' => 'Failover',       'remaining' => 100, 'health' => 91, 'domain' => 'news.kargah.dev'],
                ['key' => 'resend',  'name' => 'Resend',  'stream' => 'Transactional',  'remaining' => 100, 'health' => 99, 'domain' => 'tx.kargah.dev'],
                ['key' => 'smtp2go', 'name' => 'SMTP2GO', 'stream' => 'Failover',       'remaining' => 33,  'health' => 100, 'domain' => 'tx.kargah.dev'],
            ],
            'domains' => [
                'news.kargah.dev' => 'news.kargah.dev — marketing',
                'tx.kargah.dev'   => 'tx.kargah.dev — transactional',
            ],
            'checks' => $this->checks(),
        ];
    }

    /**
     * The deliverability gate. A blocking check that fails stops the send —
     * naming which one is the whole point, so it lives in its own method and
     * both the review step and send() read the same list.
     *
     * @return list<array{label: string, detail: string, status: string, blocking: bool}>
     */
    private function checks(): array
    {
        return [
            [
                'label'    => 'SPF record on news.kargah.dev',
                'detail'   => 'v=spf1 include:spf.brevo.com include:amazonses.com include:mailgun.org -all — 7 of the 10 permitted DNS lookups used.',
                'status'   => 'pass',
                'blocking' => true,
            ],
            [
                'label'    => 'DKIM signing key',
                'detail'   => '2048-bit key at mail._domainkey.news.kargah.dev resolves and matches the key held by Brevo.',
                'status'   => 'pass',
                'blocking' => true,
            ],
            [
                'label'    => 'DMARC policy',
                'detail'   => 'Published as p=none, so failures are only reported, never rejected. Move to p=quarantine once a fortnight of reports looks clean.',
                'status'   => 'warn',
                'blocking' => false,
            ],
            [
                'label'    => 'List-Unsubscribe header',
                'detail'   => 'List-Unsubscribe and List-Unsubscribe-Post: List-Unsubscribe=One-Click are both set. Required by Gmail and Yahoo for anyone sending in bulk.',
                'status'   => 'pass',
                'blocking' => true,
            ],
            [
                'label'    => 'Suppression list applied',
                'detail'   => '6 addresses removed — 4 hard bounces and 2 complaints, all permanent across every provider.',
                'status'   => 'pass',
                'blocking' => true,
            ],
            [
                'label'    => 'Daily quota sufficient',
                'detail'   => '164 recipients against 600 marketing sends left today across Brevo, SES and Mailgun.',
                'status'   => 'pass',
                'blocking' => true,
            ],
            [
                'label'    => 'Seed test send',
                'detail'   => 'No test send recorded. Sending one to a Gmail, Outlook and Yahoo seed address shows how the message renders and where it lands.',
                'status'   => 'fail',
                'blocking' => false,
            ],
        ];
    }

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

    public function insertToken(string $token): void
    {
        // Inserts at the caret once the editor is wired up.
        $this->toastInfo('Not connected yet', 'Tokens are inserted once the editor is wired up.');
    }

    public function generatePlainText(): void
    {
        // Strips the HTML body down to a text/plain alternative part.
        $this->toastInfo('Not connected yet', 'The plain-text part is generated in the backend phase.');
    }

    public function sendTest(): void
    {
        // Sends one copy to each seed address.
        $this->toastInfo('Not connected yet', 'Seed sends need a live provider.');
    }

    public function saveDraft(): void
    {
        // Persists the campaign without queueing it.
        $this->toastInfo('Not connected yet', 'Campaigns are not persisted until the backend phase.');
    }

    public function send(): void
    {
        // A bulk send that fails a blocking check would be filtered on arrival,
        // so say which gate stopped it rather than reporting a generic refusal.
        $failed = collect($this->checks())
            ->where('status', 'fail')
            ->where('blocking', true)
            ->pluck('label');

        if ($failed->isNotEmpty()) {
            $this->toastError(
                'Pre-flight check failed',
                $failed->implode(' · ').' — fix this before the campaign can go out.'
            );

            return;
        }

        // Hands the campaign to the router, which fans it out across providers.
        $this->toastInfo('Not connected yet', 'The send router lands with the backend phase.');
    }
};

?>

<div class="flex flex-col gap-5">

    @php
        $selectedList = collect($lists)->firstWhere('key', $list) ?? $lists[0];
        $removedByRules = collect($exclusions)
            ->filter(fn ($e) => $this->{$e['model']})
            ->sum('removes');
        $sendable = max(0, $selectedList['net'] - $removedByRules);
        $blockingFailures = collect($checks)->where('status', 'fail')->where('blocking', true)->count();
        $tone = [
            'pass' => ['icon' => 'ki-check-circle',  'text' => 'text-success',     'badge' => 'kt-badge-success',     'label' => 'Pass'],
            'warn' => ['icon' => 'ki-information-2', 'text' => 'text-warning',     'badge' => 'kt-badge-warning',     'label' => 'Warning'],
            'fail' => ['icon' => 'ki-cross-circle',  'text' => 'text-destructive', 'badge' => 'kt-badge-destructive', 'label' => 'Fail'],
        ];
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">New campaign</h1>
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
                        @forelse ($lists as $l)
                            <label class="flex items-start gap-3 rounded-lg border p-4 cursor-pointer transition-colors
                                {{ $list === $l['key'] ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40' }}">
                                <input type="radio" class="kt-radio mt-0.5" name="campaign-list" value="{{ $l['key'] }}" wire:model.live="list">
                                <span class="grow min-w-0">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-medium text-mono">{{ $l['name'] }}</span>
                                        <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $l['total'] }} contacts</span>
                                    </span>
                                    <span class="block text-xs text-muted-foreground mt-1">
                                        {{ $l['suppressed'] }} suppressed · {{ $l['unsubscribed'] }} unsubscribed · {{ $l['net'] }} mailable
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
                        @foreach ($exclusions as $e)
                            <label class="flex items-start gap-3 rounded-lg px-3 py-3 hover:bg-accent/40 cursor-pointer transition-colors">
                                <input type="checkbox" class="kt-checkbox mt-0.5" wire:model.live="{{ $e['model'] }}">
                                <span class="grow min-w-0">
                                    <span class="block text-sm text-mono">{{ $e['label'] }}</span>
                                    <span class="block text-xs text-muted-foreground mt-0.5">{{ $e['note'] }}</span>
                                </span>
                                <span class="kt-badge kt-badge-sm kt-badge-outline shrink-0">−{{ $e['removes'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="kt-card xl:sticky xl:top-24">
                <div class="kt-card-header"><h3 class="kt-card-title">Who will receive it</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4">
                    <div>
                        <div class="text-3xl font-semibold text-mono">{{ number_format($sendable) }}</div>
                        <div class="text-xs text-muted-foreground mt-1">recipients after suppression and exclusions</div>
                    </div>

                    <dl class="flex flex-col gap-2 text-sm border-t border-border pt-4">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-secondary-foreground">On the list</dt>
                            <dd class="text-mono">{{ number_format($selectedList['total']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-secondary-foreground">Suppressed</dt>
                            <dd class="text-destructive">−{{ $selectedList['suppressed'] }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-secondary-foreground">Unsubscribed</dt>
                            <dd class="text-destructive">−{{ $selectedList['unsubscribed'] }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-secondary-foreground">Removed by rules</dt>
                            <dd class="text-warning">−{{ $removedByRules }}</dd>
                        </div>
                    </dl>

                    <p class="text-xs text-muted-foreground border-t border-border pt-4">
                        Suppressed addresses are blocked at the router, not the list. A hard bounce recorded by any one
                        provider stops the address on all of them, permanently.
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

                        <div class="flex flex-col">
                            <label class="kt-form-label" for="campaign-from-name">From name</label>
                            <input type="text" id="campaign-from-name" wire:model="fromName" class="kt-input">
                        </div>

                        <div class="flex flex-col">
                            <label class="kt-form-label" for="campaign-from-address">From address</label>
                            <select id="campaign-from-address" class="kt-select" wire:model="fromAddress">
                                @foreach ($senders as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">Body</h3>
                        <div class="flex items-center gap-1">
                            <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Bold" aria-label="Bold"><i class="ki-filled ki-text-bold text-sm"></i></button>
                            <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Italic" aria-label="Italic"><i class="ki-filled ki-text-italic text-sm"></i></button>
                            <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Insert link" aria-label="Insert link"><i class="ki-filled ki-arrow-up-right text-sm"></i></button>
                            <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Insert image" aria-label="Insert image"><i class="ki-filled ki-picture text-sm"></i></button>
                            <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Insert button" aria-label="Insert button"><i class="ki-filled ki-click text-sm"></i></button>
                        </div>
                    </div>
                    <div class="kt-card-content p-5">
                        <textarea wire:model="body" class="kt-textarea min-h-[280px] w-full text-sm leading-relaxed"
                                  placeholder="Hello {first_name}, …"></textarea>
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
                                  placeholder="Hello {first_name}, …"></textarea>
                        <p class="text-xs text-muted-foreground mt-2">
                            An HTML-only message scores worse with spam filters. Send multipart/alternative and the
                            text part is what a screen reader and a plain client will use.
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-5 xl:sticky xl:top-24">
                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Personalisation</h3></div>
                    <div class="kt-card-content p-4 flex flex-col gap-1.5">
                        @foreach ($tokens as $t)
                            <button wire:click="insertToken('{{ $t['token'] }}')"
                                    class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 hover:bg-accent/40 text-start transition-colors">
                                <code class="text-xs text-primary">{{ $t['token'] }}</code>
                                <span class="text-xs text-muted-foreground truncate">{{ $t['sample'] }}</span>
                            </button>
                        @endforeach
                        <p class="text-xs text-muted-foreground px-3 pt-2 border-t border-border mt-1">
                            A token with no value falls back to an empty string, so never write “Hi {first_name},” without
                            checking the list has names.
                        </p>
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Inbox preview</h3></div>
                    <div class="kt-card-content p-4">
                        <div class="rounded-lg border border-border p-3">
                            <div class="text-sm font-medium text-mono truncate">{{ $fromName ?: '—' }}</div>
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
                    <div class="kt-card-header"><h3 class="kt-card-title">Provider routing</h3></div>
                    <div class="kt-card-content p-5 flex flex-col gap-4">

                        <label class="flex items-start gap-3 rounded-lg border p-4 cursor-pointer transition-colors
                            {{ $routing === 'auto' ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40' }}">
                            <input type="radio" class="kt-radio mt-0.5" name="routing" value="auto" wire:model.live="routing">
                            <span>
                                <span class="block text-sm font-medium text-mono">Spread automatically</span>
                                <span class="block text-xs text-muted-foreground mt-1">
                                    The router fills each provider up to its remaining daily cap, highest health score first,
                                    and moves the rest on when one runs dry or starts returning errors.
                                </span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 rounded-lg border p-4 cursor-pointer transition-colors
                            {{ $routing === 'pinned' ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40' }}">
                            <input type="radio" class="kt-radio mt-0.5" name="routing" value="pinned" wire:model.live="routing">
                            <span class="grow">
                                <span class="block text-sm font-medium text-mono">Pin to one provider</span>
                                <span class="block text-xs text-muted-foreground mt-1">
                                    Everything leaves on one account. Useful when you are warming a single IP, risky when the
                                    campaign is larger than that provider's daily cap.
                                </span>
                                @if ($routing === 'pinned')
                                    <select class="kt-select mt-3 max-w-sm" wire:model.live="pinnedProvider">
                                        @foreach ($providers as $p)
                                            <option value="{{ $p['key'] }}">{{ $p['name'] }} — {{ $p['remaining'] }} left today</option>
                                        @endforeach
                                    </select>
                                @endif
                            </span>
                        </label>

                        <div class="kt-scrollable-x-auto border-t border-border pt-4">
                            <table class="kt-table align-middle text-sm">
                                <thead>
                                    <tr>
                                        <th class="min-w-[140px]">Provider</th>
                                        <th class="w-[140px]">Stream</th>
                                        <th class="min-w-[160px]">Domain</th>
                                        <th class="w-[120px] text-end">Left today</th>
                                        <th class="w-[100px] text-end">Health</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($providers as $p)
                                        <tr class="{{ $routing === 'pinned' && $pinnedProvider !== $p['key'] ? 'opacity-40' : '' }}">
                                            <td class="font-medium text-mono">{{ $p['name'] }}</td>
                                            <td class="text-secondary-foreground">{{ $p['stream'] }}</td>
                                            <td class="text-secondary-foreground">{{ $p['domain'] }}</td>
                                            <td class="text-end">{{ $p['remaining'] }}</td>
                                            <td class="text-end {{ $p['health'] >= 95 ? 'text-success' : 'text-warning' }}">{{ $p['health'] }}%</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Rate and domain</h3></div>
                    <div class="kt-card-content p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex flex-col">
                            <label class="kt-form-label" for="campaign-rate">Send rate (messages per hour)</label>
                            <input type="number" id="campaign-rate" min="10" max="2000" step="10" class="kt-input" wire:model.live="sendRate">
                            <span class="text-xs text-muted-foreground mt-1">
                                At {{ $sendRate }}/hour this campaign takes about
                                {{ $sendRate > 0 ? max(1, (int) ceil($sendable / $sendRate)) : '—' }} hour(s).
                            </span>
                        </div>

                        <div class="flex flex-col">
                            <label class="kt-form-label" for="campaign-domain">Sending domain</label>
                            <select id="campaign-domain" class="kt-select" wire:model.live="sendingDomain">
                                @foreach ($domains as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <span class="text-xs text-muted-foreground mt-1">
                                Keep bulk on the marketing subdomain so a bad campaign cannot take invoices down with it.
                            </span>
                        </div>
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
                                <span class="block text-xs text-muted-foreground mt-1">The first batch leaves within a minute.</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3 rounded-lg border p-4 cursor-pointer transition-colors
                            {{ $schedule === 'later' ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40' }}">
                            <input type="radio" class="kt-radio mt-0.5" name="schedule" value="later" wire:model.live="schedule">
                            <span class="grow">
                                <span class="block text-sm font-medium text-mono">Schedule for later</span>
                                <span class="block text-xs text-muted-foreground mt-1">Recipient time zones are ignored; everything runs on Europe/London.</span>
                                @if ($schedule === 'later')
                                    <input type="datetime-local" class="kt-input mt-3 max-w-xs" wire:model="sendAt">
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
                        <span class="text-mono font-medium">{{ number_format($sendable) }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-secondary-foreground">Routing</span>
                        <span class="text-mono font-medium">{{ $routing === 'auto' ? 'Automatic' : ucfirst($pinnedProvider) }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-secondary-foreground">Capacity left today</span>
                        <span class="text-mono font-medium">{{ collect($providers)->where('domain', $sendingDomain)->sum('remaining') }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground border-t border-border pt-4">
                        Anything over the daily cap is held and picked up on the next window rather than being dropped.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Step 4 — Review --}}
    @if ($step === 4)
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
                        <div class="flex items-start gap-3 px-5 py-4">
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
                            @if ($c['label'] === 'Seed test send')
                                <button class="kt-btn kt-btn-sm kt-btn-outline shrink-0" wire:click="sendTest"
                                        wire:loading.attr="disabled" wire:target="sendTest">
                                    <span wire:loading.remove wire:target="sendTest">Send test</span>
                                    <span wire:loading wire:target="sendTest" class="inline-flex items-center gap-1.5">
                                        <i class="ki-filled ki-loading animate-spin"></i> Sending…
                                    </span>
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="kt-card xl:sticky xl:top-24">
                <div class="kt-card-header"><h3 class="kt-card-title">Summary</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <span class="text-secondary-foreground shrink-0">List</span>
                        <span class="text-mono text-end">{{ $selectedList['name'] }}</span>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <span class="text-secondary-foreground shrink-0">Recipients</span>
                        <span class="text-mono text-end">{{ number_format($sendable) }}</span>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <span class="text-secondary-foreground shrink-0">Subject</span>
                        <span class="text-mono text-end truncate">{{ $subject ?: '—' }}</span>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <span class="text-secondary-foreground shrink-0">From</span>
                        <span class="text-mono text-end truncate">{{ $fromAddress }}</span>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <span class="text-secondary-foreground shrink-0">Routing</span>
                        <span class="text-mono text-end">{{ $routing === 'auto' ? 'Automatic' : ucfirst($pinnedProvider) }}</span>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <span class="text-secondary-foreground shrink-0">Rate</span>
                        <span class="text-mono text-end">{{ $sendRate }}/hour</span>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <span class="text-secondary-foreground shrink-0">Starts</span>
                        <span class="text-mono text-end">{{ $schedule === 'now' ? 'Immediately' : ($sendAt ? str_replace('T', ' ', $sendAt) : '—') }}</span>
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
                            {{ $blockingFailures }} blocking check(s) must pass before this can go out.
                        </p>
                    @else
                        <p class="text-xs text-muted-foreground">
                            Once queued the campaign can be paused, but messages already handed to a provider cannot be recalled.
                        </p>
                    @endif
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
