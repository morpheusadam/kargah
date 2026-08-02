<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * File browser.
 *
 * Everything lives on a private disk outside the web root. The browser walks a
 * folder tree, the drawer previews one file at a time, and shareable links are
 * signed per file rather than making the disk public.
 */
new
#[Title('Files — Kargah')]
class extends Component
{
    public string $search = '';

    /** grid | list — kept in the URL so a layout choice survives a refresh. */
    #[Url]
    public string $view = 'grid';

    /** Current folder, as a slash path from the root of the private disk. */
    #[Url]
    public string $path = '/';

    /** Name of the file open in the preview drawer. */
    public ?string $selected = null;

    /** The whole tree, keyed by folder path. Static fixtures for now. */
    protected function tree(): array
    {
        return [
            '/' => [
                'folders' => [
                    ['name' => 'Clients',   'count' => 6],
                    ['name' => 'Contracts', 'count' => 7],
                    ['name' => 'Invoices',  'count' => 41],
                    ['name' => 'Brand',     'count' => 12],
                ],
                'files' => [
                    [
                        'name' => 'resume-fullstack-2026.pdf', 'type' => 'pdf', 'size' => '412 KB',
                        'created' => '2026-02-14', 'modified' => '2026-07-30',
                        'versions' => [
                            ['version' => 4, 'when' => '2026-07-30 11:24', 'size' => '412 KB'],
                            ['version' => 3, 'when' => '2026-05-02 09:10', 'size' => '396 KB'],
                            ['version' => 2, 'when' => '2026-03-18 16:41', 'size' => '388 KB'],
                            ['version' => 1, 'when' => '2026-02-14 08:02', 'size' => '371 KB'],
                        ],
                        'share' => ['url' => 'https://kargah.dev/s/9f3c1a7b', 'expires' => '2026-08-09'],
                    ],
                    [
                        'name' => 'rate-card-2026.pdf', 'type' => 'pdf', 'size' => '96 KB',
                        'created' => '2026-01-06', 'modified' => '2026-06-11',
                        'versions' => [
                            ['version' => 2, 'when' => '2026-06-11 14:05', 'size' => '96 KB'],
                            ['version' => 1, 'when' => '2026-01-06 10:30', 'size' => '91 KB'],
                        ],
                        'share' => null,
                    ],
                    [
                        'name' => 'expenses-q2.csv', 'type' => 'csv', 'size' => '9 KB',
                        'created' => '2026-07-05', 'modified' => '2026-07-05',
                        'versions' => [['version' => 1, 'when' => '2026-07-05 18:22', 'size' => '9 KB']],
                        'share' => null,
                    ],
                ],
            ],
            '/Clients' => [
                'folders' => [
                    ['name' => 'Northwind Studio', 'count' => 9],
                    ['name' => 'Bluepine Coffee',  'count' => 4],
                    ['name' => 'Halcyon Media',    'count' => 3],
                ],
                'files' => [
                    [
                        'name' => 'client-onboarding-checklist.docx', 'type' => 'docx', 'size' => '31 KB',
                        'created' => '2025-11-20', 'modified' => '2026-04-28',
                        'versions' => [
                            ['version' => 3, 'when' => '2026-04-28 12:15', 'size' => '31 KB'],
                            ['version' => 2, 'when' => '2026-01-19 09:44', 'size' => '29 KB'],
                            ['version' => 1, 'when' => '2025-11-20 15:03', 'size' => '24 KB'],
                        ],
                        'share' => null,
                    ],
                ],
            ],
            '/Clients/Northwind Studio' => [
                'folders' => [],
                'files' => [
                    [
                        'name' => 'northwind-contract.pdf', 'type' => 'pdf', 'size' => '288 KB',
                        'created' => '2026-07-22', 'modified' => '2026-07-22',
                        'versions' => [['version' => 1, 'when' => '2026-07-22 10:08', 'size' => '288 KB']],
                        'share' => ['url' => 'https://kargah.dev/s/4d81ec20', 'expires' => '2026-08-05'],
                    ],
                    [
                        'name' => 'northwind-scope-v2.md', 'type' => 'md', 'size' => '12 KB',
                        'created' => '2026-07-18', 'modified' => '2026-07-24',
                        'versions' => [
                            ['version' => 2, 'when' => '2026-07-24 13:37', 'size' => '12 KB'],
                            ['version' => 1, 'when' => '2026-07-18 17:50', 'size' => '8 KB'],
                        ],
                        'share' => null,
                    ],
                    [
                        'name' => 'northwind-wireframes.png', 'type' => 'png', 'size' => '1.8 MB',
                        'created' => '2026-07-19', 'modified' => '2026-07-19',
                        'versions' => [['version' => 1, 'when' => '2026-07-19 11:02', 'size' => '1.8 MB']],
                        'share' => null,
                    ],
                ],
            ],
            '/Contracts' => [
                'folders' => [],
                'files' => [
                    [
                        'name' => 'bluepine-retainer-2026.pdf', 'type' => 'pdf', 'size' => '204 KB',
                        'created' => '2026-03-02', 'modified' => '2026-03-02',
                        'versions' => [['version' => 1, 'when' => '2026-03-02 09:15', 'size' => '204 KB']],
                        'share' => null,
                    ],
                    [
                        'name' => 'nda-halcyon-media.pdf', 'type' => 'pdf', 'size' => '118 KB',
                        'created' => '2026-05-14', 'modified' => '2026-05-14',
                        'versions' => [['version' => 1, 'when' => '2026-05-14 16:20', 'size' => '118 KB']],
                        'share' => null,
                    ],
                ],
            ],
            '/Invoices' => [
                'folders' => [],
                'files' => [
                    [
                        'name' => 'INV-2026-041-northwind.pdf', 'type' => 'pdf', 'size' => '74 KB',
                        'created' => '2026-07-31', 'modified' => '2026-07-31',
                        'versions' => [['version' => 1, 'when' => '2026-07-31 08:45', 'size' => '74 KB']],
                        'share' => null,
                    ],
                    [
                        'name' => 'INV-2026-040-bluepine.pdf', 'type' => 'pdf', 'size' => '71 KB',
                        'created' => '2026-07-15', 'modified' => '2026-07-15',
                        'versions' => [['version' => 1, 'when' => '2026-07-15 09:12', 'size' => '71 KB']],
                        'share' => null,
                    ],
                    [
                        'name' => 'invoice-log-2026.csv', 'type' => 'csv', 'size' => '18 KB',
                        'created' => '2026-01-02', 'modified' => '2026-07-31',
                        'versions' => [
                            ['version' => 7, 'when' => '2026-07-31 08:46', 'size' => '18 KB'],
                            ['version' => 6, 'when' => '2026-06-30 19:01', 'size' => '16 KB'],
                        ],
                        'share' => null,
                    ],
                ],
            ],
            '/Brand' => [
                'folders' => [],
                'files' => [
                    [
                        'name' => 'kargah-logo.svg', 'type' => 'svg', 'size' => '14 KB',
                        'created' => '2026-06-01', 'modified' => '2026-07-19',
                        'versions' => [
                            ['version' => 3, 'when' => '2026-07-19 20:33', 'size' => '14 KB'],
                            ['version' => 2, 'when' => '2026-06-22 12:00', 'size' => '13 KB'],
                            ['version' => 1, 'when' => '2026-06-01 10:11', 'size' => '11 KB'],
                        ],
                        'share' => null,
                    ],
                    [
                        'name' => 'avatar-square.png', 'type' => 'png', 'size' => '240 KB',
                        'created' => '2026-06-03', 'modified' => '2026-06-03',
                        'versions' => [['version' => 1, 'when' => '2026-06-03 14:29', 'size' => '240 KB']],
                        'share' => null,
                    ],
                    [
                        'name' => 'brand-assets.zip', 'type' => 'zip', 'size' => '22 MB',
                        'created' => '2026-06-04', 'modified' => '2026-06-04',
                        'versions' => [['version' => 1, 'when' => '2026-06-04 09:00', 'size' => '22 MB']],
                        'share' => null,
                    ],
                ],
            ],
        ];
    }

    /** Icon and tone per file extension. */
    protected function typeIcon(): array
    {
        return [
            'pdf'  => ['ki-document', 'text-destructive'],
            'docx' => ['ki-document', 'text-primary'],
            'csv'  => ['ki-file-sheet', 'text-success'],
            'md'   => ['ki-notepad', 'text-secondary-foreground'],
            'svg'  => ['ki-picture', 'text-info'],
            'png'  => ['ki-picture', 'text-info'],
            'zip'  => ['ki-archive', 'text-warning'],
        ];
    }

    /** The folder currently being browsed, with icons folded in. */
    protected function currentFolder(): array
    {
        $tree = $this->tree();
        $node = $tree[$this->path] ?? ['folders' => [], 'files' => []];
        $icons = $this->typeIcon();

        $files = array_map(function (array $file) use ($icons) {
            [$icon, $tone] = $icons[$file['type']] ?? ['ki-document', 'text-muted-foreground'];
            $file['icon'] = $icon;
            $file['tone'] = $tone;
            $file['path'] = rtrim($this->path, '/').'/'.$file['name'];

            return $file;
        }, $node['files']);

        if ($this->search !== '') {
            $needle = mb_strtolower($this->search);
            $files = array_values(array_filter(
                $files,
                fn (array $f) => str_contains(mb_strtolower($f['name']), $needle)
            ));
        }

        return ['folders' => $node['folders'], 'files' => $files];
    }

    public function with(): array
    {
        $folder = $this->currentFolder();
        $segments = array_values(array_filter(explode('/', $this->path)));

        $crumbs = [];
        $walked = '';
        foreach ($segments as $segment) {
            $walked .= '/'.$segment;
            $crumbs[] = ['label' => $segment, 'path' => $walked];
        }

        $selectedFile = null;
        foreach ($folder['files'] as $file) {
            if ($file['name'] === $this->selected) {
                $selectedFile = $file;
            }
        }

        return [
            'folders' => $folder['folders'],
            'files' => $folder['files'],
            'crumbs' => $crumbs,
            'selectedFile' => $selectedFile,
        ];
    }

    /** Walk into a child folder of the current path. */
    public function openFolder(string $name): void
    {
        $this->path = rtrim($this->path, '/').'/'.$name;
        $this->selected = null;
    }

    /** Jump to any ancestor from the breadcrumb. */
    public function goTo(string $path): void
    {
        $this->path = $path === '' ? '/' : $path;
        $this->selected = null;
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['grid', 'list'], true) ? $view : 'grid';
    }

    public function select(string $name): void
    {
        $this->selected = $this->selected === $name ? null : $name;
    }

    public function closePreview(): void
    {
        $this->selected = null;
    }

    /** Store the queued uploads on the private disk under the current path. */
    public function uploadFiles(): void
    {
        // Backend: validate each queued file, store it, then record version 1.
    }

    public function createFolder(string $name): void
    {
        // Backend: create the folder under the current path.
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Files</h1>
            <p class="text-sm text-secondary-foreground mt-1">Resumes, contracts, anything you attach to work.</p>
        </div>
        <div class="flex items-center gap-2">
            <div class="kt-input max-w-[220px]">
                <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                <input type="text" placeholder="Search this folder…" wire:model.live.debounce.300ms="search">
            </div>
            <button class="kt-btn kt-btn-primary gap-2" data-kt-modal-toggle="#data-upload-modal">
                <i class="ki-filled ki-file-up"></i> Upload
            </button>
        </div>
    </div>

    {{-- Breadcrumb + view toggle --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <nav class="flex items-center gap-1 text-sm min-w-0" aria-label="Folder path">
            <button wire:click="goTo('/')"
                    class="kt-btn kt-btn-sm kt-btn-ghost gap-1.5 {{ $path === '/' ? 'text-mono font-medium' : 'text-secondary-foreground' }}">
                <i class="ki-filled ki-home-2 text-sm"></i> Files
            </button>
            @foreach ($crumbs as $crumb)
                <i class="ki-filled ki-right text-xs text-muted-foreground shrink-0"></i>
                <button wire:click="goTo('{{ $crumb['path'] }}')"
                        class="kt-btn kt-btn-sm kt-btn-ghost truncate {{ $loop->last ? 'text-mono font-medium' : 'text-secondary-foreground' }}">
                    {{ $crumb['label'] }}
                </button>
            @endforeach
        </nav>

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

            {{-- Drop zone --}}
            <div data-data-dropzone
                 class="rounded-lg border border-dashed border-border bg-accent/60 px-5 py-6 flex flex-col items-center gap-2 text-center transition-colors">
                <i class="ki-filled ki-file-up text-2xl text-muted-foreground"></i>
                <p class="text-sm text-secondary-foreground">
                    Drop files here to upload into
                    <span class="text-mono font-medium">{{ $path === '/' ? 'Files' : $path }}</span>
                </p>
                <label class="kt-btn kt-btn-sm kt-btn-outline gap-2 cursor-pointer">
                    <i class="ki-filled ki-folder-added text-sm"></i> Choose files
                    <input type="file" multiple class="hidden" data-data-dropzone-input>
                </label>
                <ul class="hidden w-full max-w-md text-start flex-col gap-1 text-xs text-secondary-foreground" data-data-dropzone-queue></ul>
                <button wire:click="uploadFiles" wire:loading.attr="disabled" wire:target="uploadFiles"
                        class="kt-btn kt-btn-sm kt-btn-primary gap-2 hidden" data-data-dropzone-start>
                    <span wire:loading.remove wire:target="uploadFiles">Start upload</span>
                    <span wire:loading wire:target="uploadFiles"><i class="ki-filled ki-loading animate-spin"></i> Uploading…</span>
                </button>
                <p class="text-[11px] text-muted-foreground">Stored on a private disk. Nothing here is public until you sign a link.</p>
            </div>

            {{-- Folders --}}
            @if (count($folders) > 0)
                <div>
                    <h3 class="text-sm font-semibold text-mono mb-3">Folders</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        @foreach ($folders as $f)
                            <button wire:click="openFolder('{{ $f['name'] }}')" class="kt-card hover:border-primary/40 transition-colors text-start">
                                <div class="kt-card-content flex items-center gap-3 p-4">
                                    <i class="ki-filled ki-folder text-2xl text-warning shrink-0"></i>
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium text-mono truncate">{{ $f['name'] }}</div>
                                        <div class="text-xs text-muted-foreground">{{ $f['count'] }} items</div>
                                    </div>
                                </div>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Files --}}
            <div>
                <h3 class="text-sm font-semibold text-mono mb-3">Files</h3>

                @if ($view === 'grid')
                    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
                        @forelse ($files as $file)
                            <button wire:click="select('{{ $file['name'] }}')" wire:key="grid-{{ $file['name'] }}"
                                    class="kt-card text-start transition-colors {{ $selected === $file['name'] ? 'border-primary/60' : 'hover:border-primary/40' }}">
                                <div class="kt-card-content p-4 flex flex-col gap-3">
                                    <div class="flex items-center justify-center h-20 rounded-md bg-muted">
                                        <i class="ki-filled {{ $file['icon'] }} {{ $file['tone'] }} text-3xl"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium text-mono truncate">{{ $file['name'] }}</div>
                                        <div class="text-xs text-muted-foreground">{{ $file['size'] }} · {{ $file['modified'] }}</div>
                                    </div>
                                </div>
                            </button>
                        @empty
                            <div class="col-span-full kt-card">
                                <div class="kt-card-content flex flex-col items-center py-14 text-center gap-3">
                                    <i class="ki-filled ki-folder-down text-4xl text-muted-foreground"></i>
                                    <p class="text-sm text-secondary-foreground">This folder is empty.</p>
                                    <button class="kt-btn kt-btn-primary gap-2" data-kt-modal-toggle="#data-upload-modal">
                                        <i class="ki-filled ki-file-up"></i> Upload
                                    </button>
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
                                            <th class="min-w-[280px]">Name</th>
                                            <th class="w-[110px]">Size</th>
                                            <th class="w-[130px]">Modified</th>
                                            <th class="w-[90px] text-end"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($files as $file)
                                            <tr wire:key="row-{{ $file['name'] }}" class="{{ $selected === $file['name'] ? 'bg-accent/60' : '' }}">
                                                <td>
                                                    <button wire:click="select('{{ $file['name'] }}')" class="flex items-center gap-2.5 min-w-0 text-start">
                                                        <i class="ki-filled {{ $file['icon'] }} {{ $file['tone'] }} text-lg shrink-0"></i>
                                                        <span class="font-medium text-mono truncate">{{ $file['name'] }}</span>
                                                    </button>
                                                </td>
                                                <td class="text-secondary-foreground">{{ $file['size'] }}</td>
                                                <td class="text-secondary-foreground">{{ $file['modified'] }}</td>
                                                <td class="text-end">
                                                    <button wire:click="select('{{ $file['name'] }}')" class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Preview" aria-label="Preview">
                                                        <i class="ki-filled ki-eye text-sm"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4">
                                                    <div class="flex flex-col items-center py-12 text-center gap-3">
                                                        <i class="ki-filled ki-folder-down text-4xl text-muted-foreground"></i>
                                                        <p class="text-sm text-secondary-foreground">This folder is empty.</p>
                                                        <button class="kt-btn kt-btn-primary gap-2" data-kt-modal-toggle="#data-upload-modal">
                                                            <i class="ki-filled ki-file-up"></i> Upload
                                                        </button>
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
        </div>

        @if ($selectedFile)
            <livewire:data::file-preview :file="$selectedFile" :key="'preview-'.$selectedFile['name']" />
        @endif
    </div>

    {{-- Upload modal --}}
    <div class="kt-modal" data-kt-modal="true" id="data-upload-modal">
        <div class="kt-modal-content max-w-[460px]">
            <div class="kt-modal-header">
                <h3 class="kt-modal-title">Upload files</h3>
                <button class="kt-btn kt-btn-icon kt-btn-ghost size-7" data-kt-modal-dismiss="true" title="Close" aria-label="Close">
                    <i class="ki-filled ki-cross text-sm"></i>
                </button>
            </div>
            <div class="kt-modal-body flex flex-col gap-3">
                <p class="text-sm text-secondary-foreground">
                    Files land in <span class="text-mono font-medium">{{ $path === '/' ? 'Files' : $path }}</span>.
                    Uploading a file that already exists adds a version rather than replacing it.
                </p>
                <label class="rounded-lg border border-dashed border-border bg-accent/60 px-5 py-8 flex flex-col items-center gap-2 text-center cursor-pointer">
                    <i class="ki-filled ki-file-up text-2xl text-muted-foreground"></i>
                    <span class="text-sm text-secondary-foreground">Choose files or drop them here</span>
                    <input type="file" multiple class="hidden" data-data-dropzone-input>
                </label>
            </div>
            <div class="kt-modal-footer justify-end gap-2">
                <button class="kt-btn kt-btn-outline" data-kt-modal-dismiss="true">Cancel</button>
                <button wire:click="uploadFiles" wire:loading.attr="disabled" wire:target="uploadFiles" class="kt-btn kt-btn-primary gap-2">
                    <span wire:loading.remove wire:target="uploadFiles">Upload</span>
                    <span wire:loading wire:target="uploadFiles"><i class="ki-filled ki-loading animate-spin"></i> Uploading…</span>
                </button>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
    (function () {
        function bytes(n) {
            if (n < 1024) return n + ' B';
            if (n < 1048576) return (n / 1024).toFixed(0) + ' KB';
            return (n / 1048576).toFixed(1) + ' MB';
        }

        function render(zone, files) {
            var queue = zone.querySelector('[data-data-dropzone-queue]');
            var start = zone.querySelector('[data-data-dropzone-start]');
            if (!queue) return;

            queue.innerHTML = '';
            Array.prototype.forEach.call(files, function (file) {
                var li = document.createElement('li');
                li.className = 'flex items-center justify-between gap-3 rounded bg-background px-2 py-1';
                li.textContent = file.name;
                var size = document.createElement('span');
                size.className = 'text-muted-foreground';
                size.textContent = bytes(file.size);
                li.appendChild(size);
                queue.appendChild(li);
            });

            var has = files.length > 0;
            queue.classList.toggle('hidden', !has);
            queue.classList.toggle('flex', has);
            if (start) start.classList.toggle('hidden', !has);
        }

        function mount() {
            document.querySelectorAll('[data-data-dropzone]').forEach(function (zone) {
                if (zone.dataset.dropzoneBound === '1') return;
                zone.dataset.dropzoneBound = '1';

                ['dragenter', 'dragover'].forEach(function (type) {
                    zone.addEventListener(type, function (e) {
                        e.preventDefault();
                        zone.classList.add('border-primary', 'bg-primary/5');
                    });
                });

                ['dragleave', 'drop'].forEach(function (type) {
                    zone.addEventListener(type, function (e) {
                        e.preventDefault();
                        zone.classList.remove('border-primary', 'bg-primary/5');
                    });
                });

                zone.addEventListener('drop', function (e) {
                    if (e.dataTransfer && e.dataTransfer.files) render(zone, e.dataTransfer.files);
                });

                var input = zone.querySelector('[data-data-dropzone-input]');
                if (input) {
                    input.addEventListener('change', function () { render(zone, input.files); });
                }
            });
        }

        document.addEventListener('DOMContentLoaded', mount);
        if (window.Livewire) Livewire.hook('morph.updated', mount);
        mount();
    })();
    </script>
    @endpush
</div>
