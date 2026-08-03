<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * Contact import.
 *
 * Upload, map, confirm. The suppression check runs at the mapping stage rather
 * than at send time, so a list that is largely made of previously bounced or
 * complained addresses is caught here instead of after the damage.
 */
new
#[Title('Import contacts — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url]
    public int $step = 1;

    public string $fileName = 'agencies-uk-2026.csv';

    public string $targetList = 'agencies-uk';

    public bool $createNewList = false;

    public string $newListName = '';

    public bool $hasHeaderRow = true;

    public string $delimiter = ',';

    public bool $consentConfirmed = false;

    /** CSV column index → contact field. */
    public array $mapping = [
        0 => 'email',
        1 => 'first_name',
        2 => 'company',
        3 => 'city',
        4 => 'skip',
    ];

    public function with(): array
    {
        return [
            'steps' => [
                1 => ['label' => 'Upload',  'hint' => 'Pick the CSV'],
                2 => ['label' => 'Map',     'hint' => 'Match the columns'],
                3 => ['label' => 'Confirm', 'hint' => 'Check what lands'],
            ],
            'lists' => [
                'agencies-uk' => 'Agencies UK — 240 contacts',
                'startups-de' => 'Startups DE — 310 contacts',
                'leads-raw'   => 'Crawler leads — 0 contacts',
            ],
            'fields' => [
                'email'      => 'Email address',
                'first_name' => 'First name',
                'last_name'  => 'Last name',
                'company'    => 'Company',
                'city'       => 'City',
                'tag'        => 'Tag',
                'skip'       => 'Do not import',
            ],
            'headers' => ['email', 'contact_name', 'agency', 'town', 'source_url'],
            'rows' => [
                ['hello@studio-nord.example',  'Rita Vance',   'Studio Nord',   'Manchester', 'https://studio-nord.example/about'],
                ['jobs@pixelforge.example',    'Sam Okafor',   'Pixelforge',    'Leeds',      'https://pixelforge.example/careers'],
                ['studio@northloop.example',   'Jonas Reyes',  'Northloop',     'Bristol',    'https://northloop.example'],
                ['contact@brightlab.example',  'Amara Diallo', 'Brightlab',     'Glasgow',    'https://brightlab.example/team'],
                ['hi@makers-lane.example',     'Tom Whitby',   "Makers' Lane",  'Sheffield',  'https://makers-lane.example'],
            ],
            'summary' => [
                ['label' => 'Will be added',       'value' => 388, 'tone' => 'text-success',            'icon' => 'ki-check-circle',  'note' => 'New addresses that pass format validation.'],
                ['label' => 'Skipped as duplicate', 'value' => 37, 'tone' => 'text-secondary-foreground', 'icon' => 'ki-copy',        'note' => 'Already on this list. Existing fields are left alone.'],
                ['label' => 'Blocked by suppression', 'value' => 6, 'tone' => 'text-destructive',       'icon' => 'ki-shield-cross',  'note' => '4 hard bounces and 2 complaints, blocked on every provider.'],
                ['label' => 'Invalid or unparsable', 'value' => 3, 'tone' => 'text-warning',            'icon' => 'ki-cross-circle',  'note' => 'No @, or a domain with no MX record.'],
            ],
            'blocked' => [
                ['email' => 'contact@brightlab.example', 'reason' => 'Hard bounce',  'provider' => 'Amazon SES', 'since' => '22 Jul 2026'],
                ['email' => 'team@harbourside.example',  'reason' => 'Complaint',    'provider' => 'Mailgun',    'since' => '23 Jul 2026'],
                ['email' => 'mail@oldfold.example',      'reason' => 'Hard bounce',  'provider' => 'Brevo',      'since' => '02 Jul 2026'],
            ],
            'totalRows' => 434,
        ];
    }

    // Upload · Map · Confirm is drawn at the top of the page and the panel
    // changes under it, so stepping needs no toast of its own.

    public function goToStep(int $step): void
    {
        $this->step = max(1, min(3, $step));
    }

    public function next(): void
    {
        $this->goToStep($this->step + 1);
    }

    public function back(): void
    {
        $this->goToStep($this->step - 1);
    }

    public function clearFile(): void
    {
        // Drops the staged upload and returns to step one.
        $this->toastInfo('Not connected yet', 'The upload is a fixture until file handling lands.');
    }

    public function import(): void
    {
        // Queues the import job; suppressed rows never reach the contacts table.
        $this->toastInfo('Not connected yet', 'CSV import lands with the backend phase.');
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Import contacts</h1>
            <p class="text-sm text-secondary-foreground mt-1">Bring a CSV in, match its columns, and see what will actually be added.</p>
        </div>
        <a href="{{ route('mail.contacts') }}" class="kt-btn kt-btn-ghost gap-2">
            <i class="ki-filled ki-arrow-left"></i> Contacts
        </a>
    </div>

    {{-- Step indicator --}}
    <div class="kt-card">
        <div class="kt-card-content p-0">
            <ol class="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-border">
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

    {{-- Step 1 — Upload --}}
    @if ($step === 1)
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">

            <div class="xl:col-span-2 kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Upload a CSV</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <label for="import-file"
                           class="flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-border hover:border-primary/50 bg-muted/30 py-12 px-6 text-center cursor-pointer transition-colors">
                        <i class="ki-filled ki-file-up text-3xl text-muted-foreground"></i>
                        <span class="text-sm text-secondary-foreground">Drop a CSV here, or <span class="text-primary font-medium">browse</span></span>
                        <span class="text-xs text-muted-foreground">UTF-8 encoded, up to 10 MB — roughly 50,000 rows</span>
                        <input type="file" id="import-file" accept=".csv,text/csv" class="hidden">
                    </label>

                    @if ($fileName)
                        <div class="flex items-center gap-3 rounded-lg border border-border px-4 py-3">
                            <i class="ki-filled ki-file-sheet text-lg text-primary shrink-0"></i>
                            <div class="grow min-w-0">
                                <div class="text-sm font-medium text-mono truncate">{{ $fileName }}</div>
                                <div class="text-xs text-muted-foreground">{{ number_format($totalRows) }} rows detected · {{ count($headers) }} columns</div>
                            </div>
                            <button wire:click="clearFile" class="kt-btn kt-btn-icon kt-btn-ghost size-8 shrink-0"
                                    title="Remove file" aria-label="Remove file">
                                <i class="ki-filled ki-cross text-base"></i>
                            </button>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2 border-t border-border">
                        <div class="flex flex-col">
                            <label class="kt-form-label" for="import-delimiter">Delimiter</label>
                            <select id="import-delimiter" class="kt-select" wire:model.live="delimiter">
                                <option value=",">Comma</option>
                                <option value=";">Semicolon</option>
                                <option value="&#9;">Tab</option>
                            </select>
                        </div>
                        <div class="flex flex-col justify-end">
                            <label class="flex items-center gap-2.5 cursor-pointer py-2">
                                <input type="checkbox" class="kt-checkbox" wire:model.live="hasHeaderRow">
                                <span class="text-sm text-secondary-foreground">First row holds column names</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Where it goes</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4">
                    <div class="flex flex-col">
                        <label class="kt-form-label" for="import-list">Target list</label>
                        <select id="import-list" class="kt-select" wire:model.live="targetList" @disabled($createNewList)>
                            @foreach ($lists as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input type="checkbox" class="kt-checkbox" wire:model.live="createNewList">
                        <span class="text-sm text-secondary-foreground">Create a new list instead</span>
                    </label>

                    @if ($createNewList)
                        <div class="flex flex-col">
                            <label class="kt-form-label" for="import-new-list">New list name</label>
                            <input type="text" id="import-new-list" class="kt-input" wire:model="newListName"
                                   placeholder="Agencies UK — August">
                        </div>
                    @endif

                    <p class="text-xs text-muted-foreground border-t border-border pt-4">
                        Only import addresses that asked to hear from you. Scraped lists produce complaints, and a
                        complaint rate over 0.3% is enough for a provider to close the account.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Step 2 — Map --}}
    @if ($step === 2)
        <div class="flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Match columns to fields</h3>
                    <span class="text-xs text-muted-foreground">Email is the only required field</span>
                </div>
                <div class="kt-card-table">
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table align-middle text-sm">
                            <thead>
                                <tr>
                                    <th class="w-[60px]">#</th>
                                    <th class="min-w-[180px]">Column in file</th>
                                    <th class="min-w-[220px]">First value</th>
                                    <th class="w-[220px]">Import as</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($headers as $i => $header)
                                    <tr>
                                        <td class="text-muted-foreground">{{ $i + 1 }}</td>
                                        <td class="font-medium text-mono">{{ $hasHeaderRow ? $header : 'Column ' . ($i + 1) }}</td>
                                        <td class="text-secondary-foreground truncate max-w-[280px]">{{ $rows[0][$i] ?? '—' }}</td>
                                        <td>
                                            <select class="kt-select" wire:model.live="mapping.{{ $i }}">
                                                @foreach ($fields as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Preview</h3>
                    <span class="text-xs text-muted-foreground">
                        First {{ count($rows) }} rows of {{ number_format($totalRows) }}, as they will be stored
                        <span wire:loading wire:target="mapping" class="inline-flex items-center gap-1.5 ms-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Updating…
                        </span>
                    </span>
                </div>
                <div class="kt-card-table">
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table align-middle text-sm">
                            <thead>
                                <tr>
                                    @foreach ($headers as $i => $header)
                                        @continue(($mapping[$i] ?? 'skip') === 'skip')
                                        <th class="min-w-[150px]">{{ $fields[$mapping[$i]] ?? $header }}</th>
                                    @endforeach
                                    @if (collect($mapping)->reject(fn ($m) => $m === 'skip')->isEmpty())
                                        <th>Nothing mapped</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr>
                                        @foreach ($headers as $i => $header)
                                            @continue(($mapping[$i] ?? 'skip') === 'skip')
                                            <td class="{{ ($mapping[$i] ?? '') === 'email' ? 'font-medium text-mono' : 'text-secondary-foreground' }}">
                                                {{ $row[$i] ?? '—' }}
                                            </td>
                                        @endforeach
                                        @if (collect($mapping)->reject(fn ($m) => $m === 'skip')->isEmpty())
                                            <td class="text-muted-foreground">—</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            @unless (in_array('email', $mapping, true))
                <div class="kt-card bg-destructive/5 border-destructive/30">
                    <div class="kt-card-content flex items-start gap-3 p-4">
                        <i class="ki-filled ki-cross-circle text-destructive text-lg mt-0.5 shrink-0"></i>
                        <div class="text-sm text-secondary-foreground">
                            <strong class="text-mono">No column is mapped to the email address.</strong>
                            Nothing can be imported until one is.
                        </div>
                    </div>
                </div>
            @endunless
        </div>
    @endif

    {{-- Step 3 — Confirm --}}
    @if ($step === 3)
        <div class="flex flex-col gap-5">

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
                @foreach ($summary as $s)
                    <div class="kt-card">
                        <div class="kt-card-content p-5">
                            <div class="flex items-center gap-2 text-sm text-secondary-foreground">
                                <i class="ki-filled {{ $s['icon'] }} {{ $s['tone'] }}"></i>
                                {{ $s['label'] }}
                            </div>
                            <div class="text-2xl font-semibold text-mono mt-2">{{ number_format($s['value']) }}</div>
                            <p class="text-xs text-muted-foreground mt-1.5 leading-relaxed">{{ $s['note'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">

                <div class="xl:col-span-2 kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">Blocked by the suppression list</h3>
                        <a href="{{ route('mail.suppression') }}" class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5">
                            <i class="ki-filled ki-shield-cross text-sm"></i> View list
                        </a>
                    </div>
                    <div class="kt-card-table">
                        <div class="kt-scrollable-x-auto">
                            <table class="kt-table align-middle text-sm">
                                <thead>
                                    <tr>
                                        <th class="min-w-[240px]">Email</th>
                                        <th class="w-[140px]">Reason</th>
                                        <th class="w-[140px]">Reported by</th>
                                        <th class="w-[140px]">Since</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($blocked as $b)
                                        <tr>
                                            <td class="font-medium text-mono">{{ $b['email'] }}</td>
                                            <td>
                                                <span class="kt-badge kt-badge-sm {{ $b['reason'] === 'Hard bounce' ? 'kt-badge-destructive' : 'kt-badge-warning' }}">
                                                    {{ $b['reason'] }}
                                                </span>
                                            </td>
                                            <td class="text-secondary-foreground">{{ $b['provider'] }}</td>
                                            <td class="text-secondary-foreground">{{ $b['since'] }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4">
                                                <div class="flex flex-col items-center justify-center text-center py-10">
                                                    <i class="ki-filled ki-shield-tick text-4xl text-success mb-3"></i>
                                                    <p class="text-sm text-secondary-foreground">Nothing in this file is suppressed.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Finish</h3></div>
                    <div class="kt-card-content p-5 flex flex-col gap-4 text-sm">
                        <div class="flex items-start justify-between gap-3">
                            <span class="text-secondary-foreground shrink-0">File</span>
                            <span class="text-mono text-end truncate">{{ $fileName ?: '—' }}</span>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <span class="text-secondary-foreground shrink-0">Goes to</span>
                            <span class="text-mono text-end">
                                {{ $createNewList ? ($newListName ?: 'New list') : \Illuminate\Support\Str::before($lists[$targetList] ?? '—', ' —') }}
                            </span>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <span class="text-secondary-foreground shrink-0">Rows read</span>
                            <span class="text-mono text-end">{{ number_format($totalRows) }}</span>
                        </div>

                        <label class="flex items-start gap-2.5 cursor-pointer border-t border-border pt-4">
                            <input type="checkbox" class="kt-checkbox mt-0.5" wire:model.live="consentConfirmed">
                            <span class="text-xs text-secondary-foreground leading-relaxed">
                                I confirm these people gave permission to be emailed and I can show where that consent came from.
                            </span>
                        </label>

                        <button class="kt-btn kt-btn-primary w-full justify-center gap-2"
                                wire:click="import" wire:loading.attr="disabled" wire:target="import"
                                @disabled(! $consentConfirmed)>
                            <span wire:loading.remove wire:target="import" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-file-up"></i> Import {{ number_format($summary[0]['value']) }} contacts
                            </span>
                            <span wire:loading wire:target="import" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Importing…
                            </span>
                        </button>

                        <p class="text-xs text-muted-foreground">
                            Suppressed addresses are never written to the list, so a later export cannot resurrect them.
                        </p>
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
        <button class="kt-btn kt-btn-primary gap-2" wire:click="next" @disabled($step === 3)>
            Next <i class="ki-filled ki-arrow-right"></i>
        </button>
    </div>
</div>
