<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Mailbox\Models\Contact;
use Modules\Mailbox\Models\Suppression;

/**
 * Contact import.
 *
 * Upload, map, confirm. The suppression check runs at the mapping stage rather
 * than at send time, so a list that is largely made of previously bounced or
 * complained addresses is caught here instead of after the damage — and a
 * suppressed address is never written to `contacts` at all, so a later export
 * cannot resurrect it.
 *
 * The file is read three times and never held in memory whole: once to find the
 * headers, once for the preview and the counts, and once to write. A 50,000-row
 * CSV on a host with a 128 MB limit is exactly the case where `file()` would
 * fall over, and reading it three times costs nothing anyone can feel.
 */
new
#[Title('Import contacts — Kargah')]
class extends Component
{
    use InteractsWithToasts;
    use WithFileUploads;

    /** How many rows the preview and the pre-import count read. */
    private const PREVIEW_ROWS = 5;

    #[Url]
    public int $step = 1;

    public ?TemporaryUploadedFile $file = null;

    public string $targetList = '';

    public bool $createNewList = false;

    public string $newListName = '';

    public bool $hasHeaderRow = true;

    public string $delimiter = ',';

    public bool $consentConfirmed = false;

    /** CSV column index → contact field. */
    public array $mapping = [];

    public function updatedFile(): void
    {
        $this->mapping = [];
        $this->consentConfirmed = false;

        if ($this->file === null) {
            return;
        }

        // Guessed from the header names, because a CSV exported from anywhere
        // sensible already says which column is which and making somebody map
        // five columns by hand to change none of them is a waste of their time.
        foreach ($this->headers() as $i => $header) {
            $this->mapping[$i] = $this->guessField((string) $header, $i);
        }

        $this->step = 2;
    }

    public function with(): array
    {
        $headers = $this->headers();
        $rows = $this->previewRows();

        return [
            'steps' => [
                1 => ['label' => 'Upload', 'hint' => 'Pick the CSV'],
                2 => ['label' => 'Map', 'hint' => 'Match the columns'],
                3 => ['label' => 'Confirm', 'hint' => 'Check what lands'],
            ],
            'lists' => Contact::tagCounts(),
            'fields' => [
                'email' => 'Email address',
                'name' => 'Name',
                'company' => 'Company',
                'tag' => 'Tag',
                'skip' => 'Do not import',
            ],
            'headers' => $headers,
            'rows' => $rows,
            'totalRows' => $this->countRows(),
            'summary' => $this->summary(),
            'blocked' => $this->blockedRows(),
            'fileName' => $this->file?->getClientOriginalName(),
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
        $this->reset('file', 'mapping', 'consentConfirmed');

        $this->step = 1;

        $this->toastSuccess('File removed', 'Nothing was imported. Pick another CSV when you are ready.');
    }

    /**
     * Write the file to `contacts`, and say exactly what happened to it.
     *
     * Idempotent on the address: a row for an address already in the table
     * updates its name and adds the tag rather than creating a second contact,
     * because `contacts.email` is unique and re-importing last month's list is
     * something everybody does eventually.
     */
    public function import(): void
    {
        if ($this->file === null) {
            $this->toastError('No file', 'Upload a CSV before importing.');

            return;
        }

        if (! in_array('email', $this->mapping, true)) {
            $this->toastError('No email column', 'Map one of the columns to the email address first.');

            return;
        }

        if (! $this->consentConfirmed) {
            $this->toastError(
                'Consent is not confirmed',
                'Importing a list you cannot show consent for is how a sending account is closed.',
            );

            return;
        }

        $tag = $this->tag();
        $added = 0;
        $updated = 0;
        $suppressed = 0;
        $invalid = 0;

        foreach ($this->readRows() as $row) {
            $values = $this->valuesFrom($row);
            $email = Suppression::normalise((string) ($values['email'] ?? ''));

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid++;

                continue;
            }

            // Before the write, not after. A suppressed address that reaches
            // the contacts table is an address a later export puts back into
            // circulation.
            if (Suppression::blocks($email)) {
                $suppressed++;

                continue;
            }

            $contact = Contact::query()->firstOrNew(['email' => $email]);
            $existed = $contact->exists;

            $tags = $contact->tagList();

            if ($tag !== null && ! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }

            $contact->fill(array_filter([
                'name' => $values['name'] ?? null,
                'company_name' => $values['company'] ?? null,
            ]) + [
                'tags' => $tags,
                'source' => Contact::IMPORT,
            ]);

            if (! $existed) {
                $contact->is_subscribed = true;
            }

            $contact->save();

            $existed ? $updated++ : $added++;
        }

        $this->reset('file', 'mapping', 'consentConfirmed', 'newListName');
        $this->step = 1;

        if ($added === 0 && $updated === 0) {
            $this->toastWarning(
                'Nothing was added',
                $suppressed.' '.str('address')->plural($suppressed).' were suppressed and '
                .$invalid.' did not parse as an email address.',
            );

            return;
        }

        $this->toastSuccess(
            $added.' '.str('contact')->plural($added).' added',
            trim(
                ($updated > 0 ? $updated.' already here and updated. ' : '')
                .($suppressed > 0 ? $suppressed.' blocked by the suppression list and never written. ' : '')
                .($invalid > 0 ? $invalid.' did not parse as an email address.' : ''),
            ) ?: 'Every row in the file was new and valid.',
        );
    }

    /* Reading the file ------------------------------------------------------- */

    /**
     * The column names, or generated ones when the file has no header row.
     *
     * @return list<string>
     */
    private function headers(): array
    {
        $first = $this->firstRow();

        if ($first === null) {
            return [];
        }

        return $this->hasHeaderRow
            ? array_map(fn ($v): string => trim((string) $v), $first)
            : array_map(fn (int $i): string => 'Column '.($i + 1), range(0, count($first) - 1));
    }

    /** @return list<string>|null */
    private function firstRow(): ?array
    {
        foreach ($this->readRaw(1) as $row) {
            return $row;
        }

        return null;
    }

    /** @return list<list<string>> */
    private function previewRows(): array
    {
        return iterator_to_array($this->readRows(self::PREVIEW_ROWS), false);
    }

    /**
     * How many data rows the file holds.
     *
     * Counted by walking it rather than by loading it, and not cached: the
     * numbers on the confirm step have to describe the file that is about to be
     * written, and a stale count is worse than no count.
     */
    private function countRows(): int
    {
        $n = 0;

        foreach ($this->readRows() as $ignored) {
            $n++;
        }

        return $n;
    }

    /**
     * Data rows, skipping the header when there is one.
     *
     * A generator, so a 50,000-row file never exists in memory as an array.
     *
     * @return \Generator<int, list<string>>
     */
    private function readRows(?int $limit = null): \Generator
    {
        $skip = $this->hasHeaderRow ? 1 : 0;
        $i = 0;
        $yielded = 0;

        foreach ($this->readRaw() as $row) {
            if ($i++ < $skip) {
                continue;
            }

            yield $row;

            if ($limit !== null && ++$yielded >= $limit) {
                return;
            }
        }
    }

    /**
     * Every line of the file as it stands, header included.
     *
     * @return \Generator<int, list<string>>
     */
    private function readRaw(?int $limit = null): \Generator
    {
        $path = $this->file?->getRealPath();

        if ($path === null || $path === false || ! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            return;
        }

        $delimiter = $this->delimiter === '' ? ',' : $this->delimiter;
        $n = 0;

        try {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                // A blank line comes back as `[null]` rather than as an empty
                // array, which would otherwise count as a row and then fail to
                // parse as an address.
                if ($row === [null]) {
                    continue;
                }

                yield array_map(fn ($v): string => trim((string) $v), $row);

                if ($limit !== null && ++$n >= $limit) {
                    return;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * One row, keyed by the field each column was mapped to.
     *
     * @param  list<string>  $row
     * @return array<string, string>
     */
    private function valuesFrom(array $row): array
    {
        $values = [];

        foreach ($this->mapping as $index => $field) {
            if ($field === 'skip' || ! isset($row[$index]) || $row[$index] === '') {
                continue;
            }

            $values[$field] = $row[$index];
        }

        return $values;
    }

    /** The list every imported contact is tagged with, or null when none was chosen. */
    private function tag(): ?string
    {
        if ($this->createNewList) {
            $name = trim($this->newListName);

            return $name === '' ? null : $name;
        }

        return $this->targetList === '' ? null : $this->targetList;
    }

    /**
     * What the import would do, counted from the whole file.
     *
     * Read before anything is written, because 'six of these are hard bounces'
     * is the one thing worth knowing *before* pressing the button rather than
     * after.
     *
     * @return list<array{label: string, value: int, tone: string, icon: string, note: string}>
     */
    private function summary(): array
    {
        $new = 0;
        $duplicate = 0;
        $suppressed = 0;
        $invalid = 0;

        if ($this->file !== null && in_array('email', $this->mapping, true)) {
            $seen = [];

            foreach ($this->readRows() as $row) {
                $email = Suppression::normalise((string) ($this->valuesFrom($row)['email'] ?? ''));

                if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $invalid++;

                    continue;
                }

                if (isset($seen[$email]) || Contact::query()->where('email', $email)->exists()) {
                    $duplicate++;

                    continue;
                }

                $seen[$email] = true;

                if (Suppression::blocks($email)) {
                    $suppressed++;

                    continue;
                }

                $new++;
            }
        }

        return [
            ['label' => 'Will be added', 'value' => $new, 'tone' => 'text-success', 'icon' => 'ki-check-circle', 'note' => 'New addresses that pass format validation.'],
            ['label' => 'Already here', 'value' => $duplicate, 'tone' => 'text-secondary-foreground', 'icon' => 'ki-copy', 'note' => 'The name and the list tag are updated; nothing else is touched.'],
            ['label' => 'Blocked by suppression', 'value' => $suppressed, 'tone' => 'text-destructive', 'icon' => 'ki-shield-cross', 'note' => 'Bounced or complained on some provider, and blocked on all of them.'],
            ['label' => 'Invalid or unparsable', 'value' => $invalid, 'tone' => 'text-warning', 'icon' => 'ki-cross-circle', 'note' => 'No @, or a shape that is not an email address.'],
        ];
    }

    /**
     * The suppressed addresses this file contains, with why.
     *
     * @return \Illuminate\Support\Collection<int, Suppression>
     */
    private function blockedRows()
    {
        if ($this->file === null || ! in_array('email', $this->mapping, true)) {
            return collect();
        }

        $emails = [];

        foreach ($this->readRows() as $row) {
            $email = Suppression::normalise((string) ($this->valuesFrom($row)['email'] ?? ''));

            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return collect(Suppression::among($emails))->values();
    }

    /** Which field a column called `contact_name` probably is. */
    private function guessField(string $header, int $index): string
    {
        $key = mb_strtolower(str_replace([' ', '-'], '_', trim($header)));

        return match (true) {
            str_contains($key, 'email'), str_contains($key, 'mail') => 'email',
            str_contains($key, 'company'), str_contains($key, 'agency'), str_contains($key, 'organisation') => 'company',
            str_contains($key, 'name') => 'name',
            str_contains($key, 'tag'), str_contains($key, 'list') => 'tag',
            // An unmapped column is not imported. Guessing wrong is worse than
            // making somebody choose: a mis-mapped column writes a URL into a
            // person's name and every message afterwards says 'Hello https://'.
            default => 'skip',
        };
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
                        <input type="file" id="import-file" accept=".csv,text/csv,text/plain" class="hidden" wire:model="file">
                    </label>

                    <div wire:loading wire:target="file" class="text-xs text-muted-foreground inline-flex items-center gap-1.5">
                        <i class="ki-filled ki-loading animate-spin"></i> Reading the file…
                    </div>

                    @if ($fileName)
                        <div class="flex items-center gap-3 rounded-lg border border-border px-4 py-3">
                            <i class="ki-filled ki-document text-lg text-primary shrink-0"></i>
                            <div class="grow min-w-0">
                                <div class="text-sm font-medium text-mono truncate">{{ $fileName }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ number_format($totalRows) }} {{ str('row')->plural($totalRows) }} ·
                                    {{ count($headers) }} {{ str('column')->plural(count($headers)) }}
                                </div>
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
                            <option value="">No list</option>
                            @foreach ($lists as $tag => $count)
                                <option value="{{ $tag }}">{{ $tag }} — {{ $count }} {{ str('contact')->plural($count) }}</option>
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
                                   placeholder="agencies-uk-august">
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

            @if ($headers === [])
                <div class="kt-card">
                    <div class="kt-card-content flex flex-col items-center justify-center text-center py-14">
                        <i class="ki-filled ki-document text-4xl text-muted-foreground mb-3"></i>
                        <p class="text-sm text-secondary-foreground">No file is loaded, so there is nothing to map.</p>
                        <button wire:click="goToStep(1)" class="kt-btn kt-btn-primary gap-2 mt-4">
                            <i class="ki-filled ki-file-up"></i> Pick a CSV
                        </button>
                    </div>
                </div>
            @else
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
                                        <tr wire:key="map-{{ $i }}">
                                            <td class="text-muted-foreground">{{ $i + 1 }}</td>
                                            <td class="font-medium text-mono">{{ $header }}</td>
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
                            First {{ count($rows) }} {{ str('row')->plural(count($rows)) }} of {{ number_format($totalRows) }}, as they will be stored
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
                                    @foreach ($rows as $r => $row)
                                        <tr wire:key="preview-{{ $r }}">
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
            @endif
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
                                        <tr wire:key="blocked-{{ $b->id }}">
                                            <td class="font-medium text-mono">{{ $b->email }}</td>
                                            <td><span class="kt-badge kt-badge-sm {{ $b->badge() }}">{{ $b->reasonLabel() }}</span></td>
                                            <td class="text-secondary-foreground">{{ $b->source ?: '—' }}</td>
                                            <td class="text-secondary-foreground">{{ $b->suppressed_at?->format('d M Y') ?? '—' }}</td>
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
                                {{ $createNewList ? ($newListName ?: 'New list') : ($targetList ?: 'No list') }}
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
                                @disabled(! $consentConfirmed || $summary[0]['value'] === 0)>
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
