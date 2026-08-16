<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Site\Services\SiteContent;
use Modules\Site\Services\SiteRequestFailed;
use Modules\Site\Services\SiteSeo;
use Modules\Site\Services\WordPressSite;
use Modules\Site\Support\PostTypes;

/**
 * What is wrong with the site's search metadata, page by page.
 *
 * ## An audit, not a score
 *
 * There is no number out of a hundred here and there will not be one. A score
 * invites somebody to optimise the score, which on every SEO plugin ever
 * shipped means padding a page until a meter turns green. Every row below is a
 * specific missing or malformed field with the page it belongs to, and fixing
 * it is one click into the editor.
 *
 * ## It audits what it can see, and says so
 *
 * The findings are computed over one page of results, because the alternative
 * is walking a whole site on every load — thirty round trips for a site with
 * six hundred posts, inside a Livewire request with somebody waiting. The
 * heading says how many were examined rather than implying a site-wide sweep,
 * which is the difference between a report and a claim.
 *
 * ## When Rank Math's fields are not exposed
 *
 * Then nothing here can be checked and the page says exactly that, in place of
 * a clean bill of health. 🔴 An audit that reports "no problems found" because
 * it could not read anything is worse than no audit: it is the same words as
 * success. `SiteSeo::editable()` decides, per item, from what the site actually
 * returned.
 */
new
#[Title('SEO — Kargah')]
class extends Component
{
    #[Url]
    public string $type = PostTypes::POST;

    /**
     * How many items to examine.
     *
     * WordPress caps `per_page` at 100 and rejects anything higher with a 400,
     * so this is the ceiling rather than a preference.
     */
    public int $examine = 50;

    /**
     * @var array{items: list<array<array-key, mixed>>, total: int, error: ?string}|null
     */
    private ?array $memo = null;

    public function updatedType(): void
    {
        $this->memo = null;
    }

    /**
     * The checks, in the order they matter.
     *
     * Each returns a sentence when it has a complaint and null when it does
     * not. Written as a list rather than as a chain of ifs so that adding a
     * check is a line, and so the page can count them without knowing what any
     * of them do.
     *
     * The two length checks use the same limits as the editor's counters
     * (`SiteSeo::fields()`), because a panel that warns at one number and
     * counts up to another is a panel nobody believes twice.
     *
     * @return array<string, \Closure(array<string, string>): ?string>
     */
    private function checks(): array
    {
        $titleLimit = SiteSeo::fields()['rank_math_title']['limit'] ?? 60;
        $descriptionLimit = SiteSeo::fields()['rank_math_description']['limit'] ?? 160;

        return [
            'No SEO title' => fn (array $seo): ?string => trim($seo['rank_math_title'] ?? '') === ''
                ? 'Search results will use the title template rather than anything written for this page.'
                : null,

            'No meta description' => fn (array $seo): ?string => trim($seo['rank_math_description'] ?? '') === ''
                ? 'Google will invent one from the body, and it is usually the first sentence whether or not that sells the page.'
                : null,

            // `fn ()` captures the two limits by value automatically — an
            // arrow function takes no `use` clause, and writing one is a parse
            // error rather than a warning.
            'SEO title is too long' => fn (array $seo): ?string => mb_strlen($seo['rank_math_title'] ?? '') > $titleLimit
                ? 'It is '.mb_strlen($seo['rank_math_title'] ?? '').' characters; past about '.$titleLimit.' the end is cut off.'
                : null,

            'Meta description is too long' => fn (array $seo): ?string => mb_strlen($seo['rank_math_description'] ?? '') > $descriptionLimit
                ? 'It is '.mb_strlen($seo['rank_math_description'] ?? '').' characters; past about '.$descriptionLimit.' the end is cut off.'
                : null,

            'No focus keyword' => fn (array $seo): ?string => trim($seo['rank_math_focus_keyword'] ?? '') === ''
                ? 'Nothing records what this page is meant to rank for, so nothing can tell you whether it does.'
                : null,
        ];
    }

    public function with(): array
    {
        $result = $this->fetch();

        $findings = [];
        $clean = 0;
        $unreadable = 0;

        foreach ($result['items'] as $item) {
            if (! SiteSeo::editable($item)) {
                $unreadable++;

                continue;
            }

            $seo = SiteSeo::read($item);
            $title = SiteContent::text($item['title'] ?? '');
            $problems = [];

            foreach ($this->checks() as $label => $check) {
                $detail = $check($seo);

                if ($detail !== null) {
                    $problems[] = ['label' => $label, 'detail' => $detail];
                }
            }

            if ($problems === []) {
                $clean++;

                continue;
            }

            $findings[] = [
                'id' => (int) ($item['id'] ?? 0),
                'title' => $title !== '' ? $title : '(no title)',
                'status' => (string) ($item['status'] ?? ''),
                'problems' => $problems,
            ];
        }

        // Worst first: a page missing three things is a better use of the next
        // ten minutes than one missing a focus keyword.
        usort($findings, fn (array $a, array $b): int => count($b['problems']) <=> count($a['problems']));

        return [
            'site' => WordPressSite::connected(),
            'findings' => $findings,
            'examined' => count($result['items']),
            'total' => $result['total'],
            'clean' => $clean,
            'unreadable' => $unreadable,
            'error' => $result['error'],
            'types' => PostTypes::all(),
        ];
    }

    /**
     * @return array{items: list<array<array-key, mixed>>, total: int, error: ?string}
     */
    private function fetch(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $site = WordPressSite::connected();

        if ($site === null) {
            return $this->memo = ['items' => [], 'total' => 0, 'error' => null];
        }

        try {
            $result = (new SiteContent($site))->list($this->type, ['status' => 'publish'], 1, $this->examine);

            return $this->memo = ['items' => $result['items'], 'total' => $result['total'], 'error' => null];
        } catch (SiteRequestFailed $e) {
            return $this->memo = ['items' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold text-mono">SEO</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                What is missing from the site's search metadata, and which page it belongs to.
            </p>
        </div>
        <div class="flex items-center gap-1">
            @foreach ($types as $key => $meta)
                <button wire:click="$set('type', '{{ $key }}')"
                        class="kt-btn kt-btn-sm {{ $type === $key ? 'kt-btn-primary' : 'kt-btn-ghost' }} gap-1.5">
                    <i class="ki-filled {{ $meta['icon'] }}"></i> {{ $meta['plural'] }}
                </button>
            @endforeach
        </div>
    </div>

    @if (! $site)

        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center py-16 text-center">
                <i class="ki-filled ki-magnifier text-4xl text-muted-foreground mb-3"></i>
                <h2 class="text-lg font-semibold text-mono">No website is connected</h2>
                <a href="{{ route('social.accounts') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-4">
                    Connect a site
                </a>
            </div>
        </div>

    @elseif ($error)

        <div class="kt-card border-destructive/30">
            <div class="kt-card-content flex items-start gap-3 py-8">
                <i class="ki-filled ki-information-2 text-destructive text-xl mt-0.5"></i>
                <div class="min-w-0">
                    <div class="text-sm font-medium text-mono">The site did not return anything to audit</div>
                    <p class="text-sm text-secondary-foreground mt-1">{{ $error }}</p>
                </div>
            </div>
        </div>

    @elseif ($unreadable > 0 && $unreadable === $examined)

        <div class="kt-card border-warning/30">
            <div class="kt-card-content flex flex-col gap-2 py-8">
                <div class="flex items-start gap-3">
                    <i class="ki-filled ki-information-2 text-warning text-xl mt-0.5"></i>
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-mono">Nothing here can be checked</h2>
                        <p class="text-sm text-secondary-foreground mt-1">
                            This site is not exposing Rank Math's fields over the REST API, so Kargah cannot read a
                            single SEO value — which is not the same as there being nothing wrong with them. Open any
                            page in the editor to get the few lines that fix it.
                        </p>
                    </div>
                </div>
                <div>
                    <a href="{{ route('site.content', ['type' => $type]) }}" wire:navigate
                       class="kt-btn kt-btn-sm kt-btn-outline">Go to the content list</a>
                </div>
            </div>
        </div>

    @else

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="kt-card">
                <div class="kt-card-content py-4">
                    <div class="text-2xl font-semibold text-mono">{{ count($findings) }}</div>
                    <div class="text-sm text-secondary-foreground">
                        {{ \Illuminate\Support\Str::plural(\Modules\Site\Support\PostTypes::label($type), count($findings)) }}
                        with something missing
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="kt-card-content py-4">
                    <div class="text-2xl font-semibold text-mono">{{ $clean }}</div>
                    <div class="text-sm text-secondary-foreground">complete</div>
                </div>
            </div>
            <div class="kt-card">
                <div class="kt-card-content py-4">
                    <div class="text-2xl font-semibold text-mono">{{ $examined }}</div>
                    <div class="text-sm text-secondary-foreground">
                        examined of {{ $total }} published
                        @if ($total > $examined)
                            <span class="block text-xs text-muted-foreground mt-0.5">
                                The newest {{ $examined }}, not the whole site.
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-header">
                <h2 class="kt-card-title">Findings</h2>
                <span class="text-xs text-muted-foreground">Worst first</span>
            </div>

            <div class="kt-card-content flex flex-col gap-3">
                @forelse ($findings as $finding)
                    <div wire:key="finding-{{ $finding['id'] }}"
                         class="border border-border rounded-lg p-3 flex flex-col gap-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <a href="{{ route('site.content-edit', ['type' => $type, 'id' => $finding['id']]) }}"
                               wire:navigate class="text-sm font-medium text-mono hover:text-primary">
                                {{ $finding['title'] }}
                            </a>
                            <span class="kt-badge kt-badge-sm kt-badge-warning">
                                {{ count($finding['problems']) }}
                                {{ \Illuminate\Support\Str::plural('issue', count($finding['problems'])) }}
                            </span>
                        </div>

                        <ul class="flex flex-col gap-1.5">
                            @foreach ($finding['problems'] as $problem)
                                <li class="text-sm">
                                    <span class="text-mono">{{ $problem['label'] }}</span>
                                    <span class="text-secondary-foreground">— {{ $problem['detail'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <div class="flex flex-col items-center py-12 text-center">
                        <i class="ki-filled ki-check-circle text-3xl text-success mb-2"></i>
                        <div class="text-sm font-medium text-mono">Nothing missing</div>
                        <p class="text-sm text-secondary-foreground mt-1">
                            All {{ $examined }} examined
                            {{ strtolower(\Illuminate\Support\Str::plural(\Modules\Site\Support\PostTypes::label($type), $examined)) }}
                            have a title, a description and a focus keyword, all within length.
                        </p>
                    </div>
                @endforelse

                @if ($unreadable > 0)
                    <p class="text-xs text-warning">
                        {{ $unreadable }} of the {{ $examined }} examined did not expose their SEO fields and were
                        skipped rather than reported as clean.
                    </p>
                @endif
            </div>
        </div>

    @endif

</div>
