<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Repository detail.
 *
 * A local read of one mirrored GitHub repository: the README, what has landed
 * recently, what is still open, where it is deployed, and which Projects board
 * the work is tracked on.
 */
new
#[Title('Repository — Kargah')]
class extends Component
{
    /** Route parameter — the repository id. */
    public string $repo = '1';

    #[Url]
    public string $tab = 'readme';

    public function mount(string $repo = '1'): void
    {
        $this->repo = $repo;
    }

    /** Static fixtures until the GitHub sync lands. */
    protected function repos(): array
    {
        return [
            '1' => [
                'name' => 'kargah',
                'full' => 'morpheusadam/kargah',
                'desc' => 'Freelance workspace: boards, mail, accounting, data.',
                'private' => false,
                'lang' => 'PHP',
                'stars' => 34,
                'forks' => 5,
                'watchers' => 6,
                'branch' => 'main',
                'pushed' => '2026-08-02 09:14',
                'openIssues' => 7,
                'openPulls' => 2,
                'closedThisMonth' => 19,
                'readme' => [
                    'heading' => 'Kargah',
                    'lead' => 'A single workspace for freelance work: project boards, client mail, invoicing and a private data store. Laravel 13, Livewire 4 single-file components, Metronic 9 for the shell.',
                    'sections' => [
                        ['title' => 'Getting started', 'body' => 'Copy .env.example, point the SQLite path at database/database.sqlite, then run the migrations. The modules register themselves through nwidart/laravel-modules.'],
                        ['title' => 'Modules', 'body' => 'Project, Accounting, Mailbox, Data and Social. Each module owns its routes, its Livewire namespace and its views, so a module can be removed without touching the shell.'],
                    ],
                    'command' => 'php artisan migrate --seed',
                ],
                'commits' => [
                    ['sha' => '9f3c1a7', 'message' => 'Data: preview drawer for the file browser', 'author' => 'morpheusadam', 'when' => '2 hours ago'],
                    ['sha' => '4d81ec2', 'message' => 'Accounting: split VAT out of the invoice total', 'author' => 'morpheusadam', 'when' => 'yesterday'],
                    ['sha' => 'b70a558', 'message' => 'Mailbox: retry failed sends with a backoff', 'author' => 'morpheusadam', 'when' => '3 days ago'],
                    ['sha' => '2ce9d13', 'message' => 'Fix breadcrumb collapse below 375px', 'author' => 'morpheusadam', 'when' => '5 days ago'],
                    ['sha' => 'a10f7b4', 'message' => 'Project: archive board instead of deleting it', 'author' => 'morpheusadam', 'when' => 'last week'],
                ],
                'deployments' => [
                    ['env' => 'Production', 'url' => 'https://kargah.dev',         'state' => 'active',  'when' => '2026-08-01 22:40'],
                    ['env' => 'Staging',    'url' => 'https://staging.kargah.dev', 'state' => 'active',  'when' => '2026-08-02 09:20'],
                ],
                'board' => ['name' => 'Kargah build', 'open' => 12, 'done' => 48, 'due' => '2026-08-15'],
            ],
            '2' => [
                'name' => 'moonwalker',
                'full' => 'morpheusadam/moonwalker',
                'desc' => 'Floating panel tooling for long-running assistant sessions.',
                'private' => false,
                'lang' => 'TypeScript',
                'stars' => 11,
                'forks' => 1,
                'watchers' => 2,
                'branch' => 'main',
                'pushed' => '2026-07-19 16:02',
                'openIssues' => 3,
                'openPulls' => 0,
                'closedThisMonth' => 4,
                'readme' => [
                    'heading' => 'Moonwalker',
                    'lead' => 'A floating panel that keeps a session visible while you work in another window. Built as a small Electron shell around a web view.',
                    'sections' => [
                        ['title' => 'Install', 'body' => 'Download the release for your platform, or build it locally with the package script.'],
                        ['title' => 'Configuration', 'body' => 'Panel position, opacity and the always-on-top flag are stored per profile.'],
                    ],
                    'command' => 'npm run build',
                ],
                'commits' => [
                    ['sha' => 'c88e2f1', 'message' => 'Remember panel position per display', 'author' => 'morpheusadam', 'when' => '2 weeks ago'],
                    ['sha' => '77b0d4a', 'message' => 'Trim the bundle by dropping the unused icon set', 'author' => 'morpheusadam', 'when' => '3 weeks ago'],
                ],
                'deployments' => [],
                'board' => null,
            ],
        ];
    }

    public function with(): array
    {
        $repos = $this->repos();
        $repo = $repos[$this->repo] ?? $repos['1'];

        return [
            'r' => $repo,
            'langColor' => [
                'PHP' => 'bg-indigo-500',
                'TypeScript' => 'bg-blue-500',
                'JavaScript' => 'bg-yellow-400',
                'Python' => 'bg-green-500',
            ][$repo['lang']] ?? 'bg-muted-foreground',
            'tabs' => ['readme' => 'Readme', 'commits' => 'Commits', 'deployments' => 'Deployments'],
        ];
    }

    /** Pull the latest metadata for this repository from GitHub. */
    public function resync(): void
    {
        // Backend: refresh the cached repo, commits, issues and deployments.
    }

    /** Attach this repository to a board in the Projects module. */
    public function linkBoard(int $boardId): void
    {
        // Backend: store the repo-to-board association.
    }
};

?>

<div class="flex flex-col gap-5">

    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <a href="{{ route('data.repos') }}" wire:navigate class="text-xs text-muted-foreground hover:text-primary inline-flex items-center gap-1">
                <i class="ki-filled ki-left text-xs"></i> Repositories
            </a>
            <div class="flex flex-wrap items-center gap-2.5 mt-1">
                <h1 class="text-xl font-semibold text-mono">{{ $r['name'] }}</h1>
                <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $r['private'] ? 'Private' : 'Public' }}</span>
                <span class="kt-badge kt-badge-sm kt-badge-outline gap-1.5">
                    <i class="ki-filled ki-tree text-[11px]"></i> {{ $r['branch'] }}
                </span>
            </div>
            <p class="text-sm text-secondary-foreground mt-1">{{ $r['desc'] }}</p>
            <div class="flex flex-wrap items-center gap-4 text-xs text-muted-foreground mt-2">
                <span class="inline-flex items-center gap-1.5">
                    <span class="size-2.5 rounded-full {{ $langColor }}"></span> {{ $r['lang'] }}
                </span>
                <span class="inline-flex items-center gap-1"><i class="ki-filled ki-star text-sm"></i>{{ $r['stars'] }} stars</span>
                <span class="inline-flex items-center gap-1"><i class="ki-filled ki-arrow-two-diagonals text-sm"></i>{{ $r['forks'] }} forks</span>
                <span class="inline-flex items-center gap-1"><i class="ki-filled ki-eye text-sm"></i>{{ $r['watchers'] }} watching</span>
                <span>Last push {{ $r['pushed'] }}</span>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <button wire:click="resync" wire:loading.attr="disabled" wire:target="resync" class="kt-btn kt-btn-outline gap-2">
                <span wire:loading.remove wire:target="resync" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-arrows-circle"></i> Resync
                </span>
                <span wire:loading wire:target="resync" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Syncing…
                </span>
            </button>
            <a href="https://github.com/{{ $r['full'] }}" target="_blank" rel="noopener" class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-github"></i> Open on GitHub
            </a>
        </div>
    </div>

    {{-- Counts --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-5">
        <div class="kt-card">
            <div class="kt-card-content p-4">
                <div class="text-xs text-muted-foreground">Open issues</div>
                <div class="text-2xl font-semibold text-mono mt-1">{{ $r['openIssues'] }}</div>
            </div>
        </div>
        <div class="kt-card">
            <div class="kt-card-content p-4">
                <div class="text-xs text-muted-foreground">Open pull requests</div>
                <div class="text-2xl font-semibold text-mono mt-1">{{ $r['openPulls'] }}</div>
            </div>
        </div>
        <div class="kt-card">
            <div class="kt-card-content p-4">
                <div class="text-xs text-muted-foreground">Closed this month</div>
                <div class="text-2xl font-semibold text-mono mt-1">{{ $r['closedThisMonth'] }}</div>
            </div>
        </div>
        <div class="kt-card">
            <div class="kt-card-content p-4">
                <div class="text-xs text-muted-foreground">Environments</div>
                <div class="text-2xl font-semibold text-mono mt-1">{{ count($r['deployments']) }}</div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

        <div class="xl:col-span-2 flex flex-col gap-5">

            {{-- Tabs --}}
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($tabs as $key => $label)
                    <button wire:click="$set('tab', '{{ $key }}')"
                            class="kt-btn kt-btn-sm {{ $tab === $key ? 'kt-btn-primary' : 'kt-btn-outline' }}">{{ $label }}</button>
                @endforeach
            </div>

            @if ($tab === 'readme')
                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title flex items-center gap-2">
                            <i class="ki-filled ki-notepad text-muted-foreground"></i> README.md
                        </h3>
                        <span class="text-xs text-muted-foreground">Rendered from the default branch</span>
                    </div>
                    <div class="kt-card-content p-5 flex flex-col gap-4">
                        <h2 class="text-lg font-semibold text-mono">{{ $r['readme']['heading'] }}</h2>
                        <p class="text-sm text-secondary-foreground">{{ $r['readme']['lead'] }}</p>
                        @foreach ($r['readme']['sections'] as $s)
                            <div>
                                <h3 class="text-sm font-semibold text-mono mb-1">{{ $s['title'] }}</h3>
                                <p class="text-sm text-secondary-foreground">{{ $s['body'] }}</p>
                            </div>
                        @endforeach
                        <pre class="kt-scrollable-x-auto text-xs rounded-lg bg-muted p-3 text-secondary-foreground"><code>{{ $r['readme']['command'] }}</code></pre>
                    </div>
                </div>
            @elseif ($tab === 'commits')
                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Recent commits</h3></div>
                    <div class="kt-card-table">
                        <div class="kt-scrollable-x-auto">
                            <table class="kt-table align-middle text-sm">
                                <thead>
                                    <tr>
                                        <th class="min-w-[300px]">Message</th>
                                        <th class="w-[140px]">Author</th>
                                        <th class="w-[110px]">SHA</th>
                                        <th class="w-[120px]">When</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($r['commits'] as $c)
                                        <tr>
                                            <td class="font-medium text-mono">{{ $c['message'] }}</td>
                                            <td class="text-secondary-foreground">{{ $c['author'] }}</td>
                                            <td><code class="text-xs px-1.5 py-0.5 rounded bg-muted text-secondary-foreground">{{ $c['sha'] }}</code></td>
                                            <td class="text-secondary-foreground">{{ $c['when'] }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4">
                                                <div class="flex flex-col items-center py-12 text-center gap-2">
                                                    <i class="ki-filled ki-code text-4xl text-muted-foreground"></i>
                                                    <p class="text-sm text-secondary-foreground">No commits pulled yet.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @else
                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Deployments</h3></div>
                    <div class="kt-card-content p-5 flex flex-col gap-3">
                        @forelse ($r['deployments'] as $d)
                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="kt-badge kt-badge-sm {{ $d['env'] === 'Production' ? 'kt-badge-success' : 'kt-badge-info' }}">{{ $d['env'] }}</span>
                                        <span class="text-xs text-muted-foreground">Deployed {{ $d['when'] }}</span>
                                    </div>
                                    <a href="{{ $d['url'] }}" target="_blank" rel="noopener"
                                       class="text-sm text-primary hover:underline truncate block mt-1">{{ $d['url'] }}</a>
                                </div>
                                <a href="{{ $d['url'] }}" target="_blank" rel="noopener" class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 shrink-0">
                                    <i class="ki-filled ki-exit-right-corner text-sm"></i> Visit
                                </a>
                            </div>
                        @empty
                            <div class="flex flex-col items-center py-10 text-center gap-3">
                                <i class="ki-filled ki-rocket text-4xl text-muted-foreground"></i>
                                <p class="text-sm text-secondary-foreground">Nothing deployed from this repository yet.</p>
                                <a href="{{ route('data.links') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
                                    <i class="ki-filled ki-plus"></i> Add a deployment link
                                </a>
                            </div>
                        @endforelse
                    </div>
                </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="flex flex-col gap-5">

            {{-- Linked board --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Linked board</h3>
                    @if ($r['board'])
                        <span class="kt-badge kt-badge-sm kt-badge-primary">Connected</span>
                    @endif
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-4">
                    @if ($r['board'])
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="inline-flex items-center justify-center size-10 rounded-lg bg-primary/10 text-primary shrink-0">
                                <i class="ki-filled ki-element-11 text-lg"></i>
                            </span>
                            <div class="min-w-0">
                                <div class="font-semibold text-mono truncate">{{ $r['board']['name'] }}</div>
                                <div class="text-xs text-muted-foreground">Due {{ $r['board']['due'] }}</div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-lg bg-muted p-3">
                                <div class="text-xs text-muted-foreground">Open cards</div>
                                <div class="text-lg font-semibold text-mono">{{ $r['board']['open'] }}</div>
                            </div>
                            <div class="rounded-lg bg-muted p-3">
                                <div class="text-xs text-muted-foreground">Done</div>
                                <div class="text-lg font-semibold text-mono">{{ $r['board']['done'] }}</div>
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Commits that mention a card reference move it along on the board.
                        </p>
                        <a href="{{ route('projects.boards') }}" wire:navigate class="kt-btn kt-btn-outline w-full gap-2">
                            <i class="ki-filled ki-element-11"></i> Open board
                        </a>
                    @else
                        <div class="flex flex-col items-center py-6 text-center gap-3">
                            <i class="ki-filled ki-element-11 text-3xl text-muted-foreground"></i>
                            <p class="text-sm text-secondary-foreground">Not linked to a board yet.</p>
                            <button wire:click="linkBoard(1)" wire:loading.attr="disabled" wire:target="linkBoard(1)" class="kt-btn kt-btn-primary gap-2">
                                <span wire:loading.remove wire:target="linkBoard(1)" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-plus"></i> Link a board
                                </span>
                                <span wire:loading wire:target="linkBoard(1)" class="inline-flex items-center gap-2">
                                    <i class="ki-filled ki-loading animate-spin"></i> Linking…
                                </span>
                            </button>
                        </div>
                    @endif
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Clone</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-2">
                    <div class="kt-input">
                        <input type="text" readonly value="git@github.com:{{ $r['full'] }}.git" aria-label="Clone URL">
                        <button class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Copy clone URL" aria-label="Copy clone URL">
                            <i class="ki-filled ki-copy text-sm"></i>
                        </button>
                    </div>
                    <p class="text-xs text-muted-foreground">Mirrored locally on every sync so Data holds a copy even if GitHub is unreachable.</p>
                </div>
            </div>
        </div>
    </div>
</div>
