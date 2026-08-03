<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;
use Modules\Data\Contracts\AttachmentService;
use Modules\Data\Models\Attachment;
use Modules\Data\Models\Repository;

/**
 * Every file the application holds, and the one place they arrive through.
 *
 * There is no folder tree. Files are not filed by where somebody put them, they
 * are filed by what they belong to: a company, a customer, a repository, a card,
 * an invoice, an email. `attachable_type` holds a morph alias, so this page can
 * list a file attached by a module it knows nothing about and still label it.
 *
 * Uploading goes through `Modules\Data\Contracts\AttachmentService` like every
 * other caller. This component never opens a file handle, and neither does
 * anything else outside that service.
 *
 * The target picker offers what Data can enumerate without reaching into
 * another module: Core's companies and customers, and Data's own repositories.
 * A card or an invoice gets its files from its own page, through the same
 * service — that is what the contract is for.
 */
new
#[Title('Files — Kargah')]
class extends Component
{
    use InteractsWithToasts;
    use WithFileUploads;

    public string $search = '';

    /** grid | list — kept in the URL so a layout choice survives a refresh. */
    #[Url]
    public string $view = 'grid';

    /** Morph alias to narrow the list to, or 'all'. */
    #[Url(as: 'type')]
    public string $filterType = 'all';

    /** The attachment open in the preview drawer. */
    public ?int $selected = null;

    /** Where a new upload will be attached, as `alias:id`. */
    public string $target = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    public function mount(): void
    {
        $this->target = $this->targetOptions()->keys()->first() ?? '';
    }

    /**
     * Icon and tone per extension.
     *
     * Whole class strings in a map. Never `text-{$tone}`: the Tailwind scanner
     * reads this file as text and cannot see a class assembled at run time.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function typeIcons(): array
    {
        return [
            'pdf' => ['ki-document', 'text-destructive'],
            'doc' => ['ki-document', 'text-primary'],
            'docx' => ['ki-document', 'text-primary'],
            'csv' => ['ki-file-sheet', 'text-success'],
            'xlsx' => ['ki-file-sheet', 'text-success'],
            'md' => ['ki-notepad', 'text-secondary-foreground'],
            'txt' => ['ki-notepad', 'text-secondary-foreground'],
            'svg' => ['ki-picture', 'text-info'],
            'png' => ['ki-picture', 'text-info'],
            'jpg' => ['ki-picture', 'text-info'],
            'jpeg' => ['ki-picture', 'text-info'],
            'webp' => ['ki-picture', 'text-info'],
            'zip' => ['ki-archive', 'text-warning'],
        ];
    }

    /**
     * What a new upload may be attached to.
     *
     * @return Collection<string, string> `alias:id` => label
     */
    private function targetOptions(): Collection
    {
        $options = new Collection;

        foreach (Company::query()->orderBy('name')->limit(50)->get() as $company) {
            $options->put('company:'.$company->id, 'Company · '.$company->name);
        }

        foreach (Customer::query()->orderBy('name')->limit(50)->get() as $customer) {
            $options->put('customer:'.$customer->id, 'Customer · '.$customer->name);
        }

        foreach (Repository::query()->orderBy('full_name')->limit(50)->get() as $repository) {
            $options->put('repository:'.$repository->id, 'Repository · '.$repository->full_name);
        }

        return $options;
    }

    /**
     * A readable name for each target, resolved in batches.
     *
     * Only the types Data can name itself. A card or an invoice is labelled from
     * its alias and its id, because asking Project for a card title from here
     * would be exactly the reach across modules the contract exists to prevent.
     *
     * @param  Collection<int, Attachment>  $attachments
     * @return array<string, string>
     */
    private function targetLabels(Collection $attachments): array
    {
        $byType = $attachments->groupBy('attachable_type')->map(fn ($group) => $group->pluck('attachable_id')->unique());

        $labels = [];

        foreach ([
            'company' => Company::class,
            'customer' => Customer::class,
            'repository' => Repository::class,
        ] as $alias => $model) {
            if (! $byType->has($alias)) {
                continue;
            }

            $column = $alias === 'repository' ? 'full_name' : 'name';

            foreach ($model::query()->whereIn('id', $byType[$alias])->get() as $record) {
                $labels[$alias.':'.$record->id] = $record->{$column};
            }
        }

        return $labels;
    }

    public function with(): array
    {
        $attachments = Attachment::query()
            ->when($this->search !== '', fn ($query) => $query->where('original_name', 'like', '%'.trim($this->search).'%'))
            ->when($this->filterType !== 'all', fn ($query) => $query->where('attachable_type', $this->filterType))
            ->latest('id')
            ->get();

        $labels = $this->targetLabels($attachments);
        $icons = $this->typeIcons();

        $files = $attachments->map(function (Attachment $attachment) use ($labels, $icons): array {
            [$icon, $tone] = $icons[$attachment->extension()] ?? ['ki-document', 'text-muted-foreground'];
            $key = $attachment->attachable_type.':'.$attachment->attachable_id;

            return [
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'size' => $attachment->humanSize(),
                'extension' => $attachment->extension() ?: '—',
                'mime' => $attachment->mime,
                'checksum' => $attachment->checksum,
                'icon' => $icon,
                'tone' => $tone,
                'target_type' => $attachment->attachable_type,
                'target_label' => $labels[$key] ?? ($attachment->attachable_type.' #'.$attachment->attachable_id),
                'added' => $attachment->created_at?->toDateTimeString() ?? '—',
                'download_url' => route('data.file-download', ['attachment' => $attachment->id]),
            ];
        });

        return [
            'files' => $files,
            'types' => Attachment::query()->selectRaw('attachable_type, count(*) as total')
                ->groupBy('attachable_type')->orderBy('attachable_type')->pluck('total', 'attachable_type'),
            'targets' => $this->targetOptions(),
            'selectedFile' => $files->firstWhere('id', $this->selected),
            'diskName' => (string) config('data.disk', 'local'),
        ];
    }

    public function setView(string $view): void
    {
        // Switching between grid and list is its own confirmation. Only an
        // unknown value, which quietly falls back to the grid, is worth saying.
        $requested = $view;
        $this->view = in_array($view, ['grid', 'list'], true) ? $view : 'grid';

        if ($requested !== $this->view) {
            $this->toastWarning('Unknown layout', 'Showing the grid instead.');
        }
    }

    /** Open or close the preview drawer. The drawer sliding in is the feedback. */
    public function select(int $id): void
    {
        $this->selected = $this->selected === $id ? null : $id;
    }

    public function closePreview(): void
    {
        $this->selected = null;
    }

    /** Store every queued upload against the chosen target. */
    public function upload(): void
    {
        if ($this->uploads === []) {
            $this->toastWarning('Nothing to upload', 'Choose a file first.');

            return;
        }

        [$alias, $id] = array_pad(explode(':', $this->target, 2), 2, null);
        $model = $this->resolveTarget((string) $alias, (int) $id);

        if ($model === null) {
            $this->toastError('Nothing was uploaded', 'Choose what these files belong to first.');

            return;
        }

        $this->validate([
            // 25 MB, which is generous for a contract and mean for a video —
            // deliberately, since the disk is a shared host's.
            'uploads.*' => 'file|max:25600',
        ]);

        $service = app(AttachmentService::class);
        $stored = 0;

        foreach ($this->uploads as $upload) {
            $service->attach($model, $upload, auth()->id());
            $stored++;
        }

        $names = collect($this->uploads)->map(fn ($u) => $u->getClientOriginalName())->join(', ', ' and ');
        $this->uploads = [];

        $this->toastSuccess(
            'Stored '.$stored.' '.str('file')->plural($stored),
            $names.' — attached to '.$this->targetOptions()->get($this->target, 'the chosen target').'.'
        );
    }

    public function deleteFile(int $id): void
    {
        $file = app(AttachmentService::class)->find($id);

        if ($file === null) {
            return;
        }

        app(AttachmentService::class)->delete($id);

        if ($this->selected === $id) {
            $this->selected = null;
        }

        $this->toastSuccess(
            'Removed '.$file['name'],
            'The row is soft deleted and the bytes are still on the disk, so it can be brought back.'
        );
    }

    /** Turn `alias:id` back into the model it names, or null. */
    private function resolveTarget(string $alias, int $id): ?object
    {
        return match ($alias) {
            'company' => Company::query()->find($id),
            'customer' => Customer::query()->find($id),
            'repository' => Repository::query()->find($id),
            default => null,
        };
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Files</h1>
            <p class="text-sm text-secondary-foreground mt-1">Everything the application holds, filed by what it belongs to.</p>
        </div>
        <div class="flex items-center gap-2">
            <div class="kt-input max-w-[220px]">
                <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                <input type="text" placeholder="Search by name…" aria-label="Search files"
                       wire:model.live.debounce.300ms="search">
            </div>
        </div>
    </div>

    {{-- Filter chips + view toggle --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <button wire:click="$set('filterType', 'all')"
                    class="kt-btn kt-btn-sm gap-2 {{ $filterType === 'all' ? 'kt-btn-primary' : 'kt-btn-outline' }}">
                <i class="ki-filled ki-element-11 text-sm"></i> All
            </button>
            @foreach ($types as $type => $total)
                <button wire:click="$set('filterType', '{{ $type }}')"
                        class="kt-btn kt-btn-sm gap-2 {{ $filterType === $type ? 'kt-btn-primary' : 'kt-btn-outline' }}">
                    {{ str($type)->replace('_', ' ')->title() }}
                    <span class="text-xs opacity-70">{{ $total }}</span>
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-1 p-1 rounded-lg bg-muted shrink-0" role="group" aria-label="View mode">
            <button wire:click="setView('grid')" title="Grid view" aria-label="Grid view"
                    class="kt-btn kt-btn-icon kt-btn-sm size-7 {{ $view === 'grid' ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
                <i class="ki-filled ki-element-11 text-sm"></i>
            </button>
            <button wire:click="setView('list')" title="List view" aria-label="List view"
                    class="kt-btn kt-btn-icon kt-btn-sm size-7 {{ $view === 'list' ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
                <i class="ki-filled ki-row-vertical text-sm"></i>
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 {{ $selectedFile ? 'xl:grid-cols-[minmax(0,1fr)_340px]' : '' }}">

        <div class="flex flex-col gap-5 min-w-0">

            {{-- Upload --}}
            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Add a file</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4">
                    @if ($targets->isEmpty())
                        <p class="text-sm text-secondary-foreground">
                            There is nothing to attach a file to yet. Add a company, a customer or sync a repository
                            first — a file in Kargah always belongs to something.
                        </p>
                    @else
                        <div class="flex flex-col">
                            <label class="kt-form-label" for="file-target">Attach to</label>
                            <select id="file-target" class="kt-select" wire:model="target">
                                @foreach ($targets as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <label class="rounded-lg border border-dashed border-border bg-accent/60 px-5 py-6 flex flex-col items-center gap-2 text-center cursor-pointer">
                            <i class="ki-filled ki-file-up text-2xl text-muted-foreground"></i>
                            <span class="text-sm text-secondary-foreground">Choose files, up to 25 MB each</span>
                            <input type="file" multiple class="hidden" wire:model="uploads">
                            <span class="text-[11px] text-muted-foreground">
                                Stored on the <span class="text-mono">{{ $diskName }}</span> disk, outside the web root.
                                Nothing is public until you sign a link for it.
                            </span>
                        </label>

                        <div wire:loading wire:target="uploads" class="text-xs text-secondary-foreground">
                            <i class="ki-filled ki-loading animate-spin"></i> Receiving…
                        </div>

                        @error('uploads.*')<span class="text-xs text-destructive">{{ $message }}</span>@enderror

                        @if (count($uploads) > 0)
                            <ul class="flex flex-col gap-1 text-xs text-secondary-foreground">
                                @foreach ($uploads as $upload)
                                    <li class="flex items-center justify-between gap-3 rounded bg-background px-2 py-1">
                                        <span class="truncate">{{ $upload->getClientOriginalName() }}</span>
                                        <span class="text-muted-foreground shrink-0">{{ round($upload->getSize() / 1024) }} KB</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <button wire:click="upload" wire:loading.attr="disabled" wire:target="upload"
                                class="kt-btn kt-btn-primary gap-2 self-start">
                            <span wire:loading.remove wire:target="upload" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-file-up"></i> Store {{ count($uploads) > 0 ? count($uploads).' '.str('file')->plural(count($uploads)) : 'file' }}
                            </span>
                            <span wire:loading wire:target="upload" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Storing…
                            </span>
                        </button>
                    @endif
                </div>
            </div>

            {{-- Files --}}
            @if ($view === 'grid')
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
                    @forelse ($files as $file)
                        <button wire:click="select({{ $file['id'] }})" wire:key="grid-{{ $file['id'] }}"
                                class="kt-card text-start transition-colors {{ $selected === $file['id'] ? 'border-primary/60' : 'hover:border-primary/40' }}">
                            <div class="kt-card-content p-4 flex flex-col gap-3">
                                <div class="flex items-center justify-center h-20 rounded-md bg-muted">
                                    <i class="ki-filled {{ $file['icon'] }} {{ $file['tone'] }} text-3xl"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-mono truncate">{{ $file['name'] }}</div>
                                    <div class="text-xs text-muted-foreground truncate">{{ $file['size'] }} · {{ $file['target_label'] }}</div>
                                </div>
                            </div>
                        </button>
                    @empty
                        <div class="col-span-full kt-card">
                            <div class="kt-card-content flex flex-col items-center py-14 text-center gap-3">
                                <i class="ki-filled ki-document text-4xl text-muted-foreground"></i>
                                <p class="text-sm text-secondary-foreground">
                                    {{ $search !== '' || $filterType !== 'all' ? 'No file matches that filter.' : 'No files stored yet.' }}
                                </p>
                            </div>
                        </div>
                    @endforelse
                </div>
            @else
                <div class="kt-card">
                    <div class="kt-card-table">
                        <div class="kt-scrollable-x-auto">
                            <table class="kt-table align-middle text-sm">
                                <thead>
                                    <tr>
                                        <th class="min-w-[260px]">Name</th>
                                        <th class="min-w-[180px]">Belongs to</th>
                                        <th class="w-[110px] text-end">Size</th>
                                        <th class="w-[160px]">Added</th>
                                        <th class="w-[90px] text-end"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($files as $file)
                                        <tr wire:key="row-{{ $file['id'] }}" class="{{ $selected === $file['id'] ? 'bg-accent/60' : '' }}">
                                            <td>
                                                <button wire:click="select({{ $file['id'] }})" class="flex items-center gap-2.5 min-w-0 text-start">
                                                    <i class="ki-filled {{ $file['icon'] }} {{ $file['tone'] }} text-lg shrink-0"></i>
                                                    <span class="font-medium text-mono truncate">{{ $file['name'] }}</span>
                                                </button>
                                            </td>
                                            <td class="text-secondary-foreground">
                                                <span class="kt-badge kt-badge-sm kt-badge-outline">{{ str($file['target_type'])->replace('_', ' ') }}</span>
                                                <span class="ms-1.5">{{ $file['target_label'] }}</span>
                                            </td>
                                            <td class="text-end text-secondary-foreground">{{ $file['size'] }}</td>
                                            <td class="text-secondary-foreground">{{ $file['added'] }}</td>
                                            <td class="text-end">
                                                <button wire:click="select({{ $file['id'] }})" class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Preview" aria-label="Preview">
                                                    <i class="ki-filled ki-eye text-sm"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5">
                                                <div class="flex flex-col items-center py-12 text-center gap-3">
                                                    <i class="ki-filled ki-document text-4xl text-muted-foreground"></i>
                                                    <p class="text-sm text-secondary-foreground">
                                                        {{ $search !== '' || $filterType !== 'all' ? 'No file matches that filter.' : 'No files stored yet.' }}
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        @if ($selectedFile)
            <livewire:data::file-preview :file="$selectedFile" :key="'preview-'.$selectedFile['id']" />
        @endif
    </div>
</div>
