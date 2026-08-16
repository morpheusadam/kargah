<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Site\Services\SiteContent;
use Modules\Site\Services\SiteRequestFailed;
use Modules\Site\Services\SiteSeo;
use Modules\Site\Services\WordPressSite;
use Modules\Site\Support\PostTypes;

/**
 * One post or page on the live site, and its SEO fields, edited in one place.
 *
 * ## Content and SEO are one screen and two writes
 *
 * One screen because they are one decision — nobody rewrites a title and then
 * goes somewhere else to reconsider what the search result will say. Two writes
 * because they can fail independently and for completely different reasons: the
 * content write fails when the user's role is too small, and the SEO write
 * fails when the site has never registered Rank Math's keys for REST. Reporting
 * one outcome for both would mean either claiming a success that did not happen
 * or throwing away one that did.
 *
 * So the save reports what it did, in the order it did it, and the SEO half
 * says plainly when the site swallowed it.
 *
 * ## The original is kept, and it is not decoration
 *
 * `$original` is what the site had when the form was loaded, and every write
 * sends only the difference against it. WordPress treats an absent field as
 * "leave alone" and an empty one as "clear", so a save that posted the whole
 * form back would wipe a featured image, a template or a custom field this page
 * never drew — with no error, because the request was perfectly valid.
 *
 * ## No rich text editor
 *
 * The body is a textarea holding what WordPress actually stores, which for a
 * block-editor site is block markup with its HTML comments intact. A WYSIWYG
 * over the top of that would strip the comments on the first save and silently
 * convert every block into a classic-editor blob. A plain textarea is honest
 * about what it is showing and cannot quietly destroy the page's structure.
 */
new
#[Title('Edit — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public string $type = PostTypes::POST;

    public int $id = 0;

    public string $title = '';

    public string $slug = '';

    public string $excerpt = '';

    public string $content = '';

    public string $status = 'draft';

    /** @var array<string, string> */
    public array $seo = [];

    /**
     * What the site had when this form was loaded.
     *
     * @var array<string, mixed>
     */
    public array $original = [];

    public bool $seoEditable = false;

    public bool $found = false;

    public ?string $error = null;

    public ?string $link = null;

    public bool $showSnippet = false;

    public function mount(string $type, int $id): void
    {
        $this->type = PostTypes::has($type) ? $type : PostTypes::POST;
        $this->id = $id;

        $this->load();
    }

    /**
     * Read the item, or record why it could not be read.
     *
     * Called from `mount()` and again after every successful save, so that what
     * is on screen is what the site now holds rather than what this component
     * believes it sent. The difference shows up the first time a plugin filters
     * a slug on save.
     */
    private function load(): void
    {
        $site = WordPressSite::connected();

        if ($site === null) {
            $this->error = 'No WordPress site is connected.';

            return;
        }

        try {
            $item = (new SiteContent($site))->find($this->type, $this->id);
        } catch (SiteRequestFailed $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->found = true;
        $this->error = null;

        $this->title = SiteContent::text($item['title'] ?? '');
        $this->slug = (string) ($item['slug'] ?? '');
        $this->excerpt = SiteContent::text($item['excerpt'] ?? '');
        $this->content = SiteContent::text($item['content'] ?? '');
        $this->status = (string) ($item['status'] ?? 'draft');
        $this->link = is_string($item['link'] ?? null) ? $item['link'] : null;

        $this->seoEditable = SiteSeo::editable($item);
        $this->seo = SiteSeo::read($item);

        $this->original = [
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'content' => $this->content,
            'status' => $this->status,
            'seo' => $this->seo,
        ];
    }

    /**
     * Send the difference, and report each half of it honestly.
     */
    public function save(): void
    {
        $site = WordPressSite::connected();

        if ($site === null) {
            $this->toastError('No site is connected', 'Connect a WordPress site under Social → Accounts first.');

            return;
        }

        $changes = [];

        foreach (['title', 'slug', 'excerpt', 'content', 'status'] as $field) {
            if (($this->original[$field] ?? null) !== $this->{$field}) {
                $changes[$field] = $this->{$field};
            }
        }

        $seoChanges = [];

        foreach (array_keys(SiteSeo::fields()) as $key) {
            if ((($this->original['seo'] ?? [])[$key] ?? '') !== ($this->seo[$key] ?? '')) {
                $seoChanges[$key] = $this->seo[$key] ?? '';
            }
        }

        if ($changes === [] && $seoChanges === []) {
            $this->toastSuccess('Nothing to save', 'Nothing on this page differs from what the site has.');

            return;
        }

        $content = new SiteContent($site);

        if ($changes !== []) {
            try {
                $content->update($this->type, $this->id, $changes);
            } catch (SiteRequestFailed $e) {
                $this->toastError('The site refused the change', $e->getMessage());

                return;
            }
        }

        $rejected = [];

        if ($seoChanges !== []) {
            try {
                $response = SiteSeo::write($site, $this->type, $this->id, $this->seo, $this->original['seo'] ?? []);
                $rejected = SiteSeo::rejected($seoChanges, $response);
            } catch (SiteRequestFailed $e) {
                $this->load();
                $this->toastWarning('The content saved, the SEO fields did not', $e->getMessage());

                return;
            }
        }

        $this->load();

        if ($rejected !== []) {
            // 🔴 The silent failure this whole class is arranged around: 200,
            // no error, and the keys are not there afterwards.
            $this->showSnippet = true;

            $this->toastWarning(
                'The site kept the content and dropped the SEO fields',
                'WordPress accepted the request and stored none of '.implode(', ', $rejected)
                .'. Rank Math does not expose its fields over REST until something registers them.',
            );

            return;
        }

        $this->toastSuccess(PostTypes::label($this->type).' saved', 'The site now has what is on this page.');
    }

    public function with(): array
    {
        return [
            'seoFields' => SiteSeo::fields(),
            'statuses' => PostTypes::statuses(),
            'snippet' => SiteSeo::registrationSnippet(),
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <a href="{{ route('site.content', ['type' => $type]) }}" wire:navigate
               class="text-xs text-muted-foreground hover:text-primary inline-flex items-center gap-1">
                <i class="ki-filled ki-arrow-left"></i> All {{ strtolower(\Modules\Site\Support\PostTypes::plural($type)) }}
            </a>
            <h1 class="text-xl font-semibold text-mono mt-1">
                {{ $title !== '' ? $title : 'Untitled '.strtolower(\Modules\Site\Support\PostTypes::label($type)) }}
            </h1>
        </div>

        @if ($found)
            <div class="flex items-center gap-2">
                @if ($link)
                    <a href="{{ $link }}" target="_blank" rel="noopener" class="kt-btn kt-btn-outline gap-2">
                        <i class="ki-filled ki-exit-right-corner"></i> View
                    </a>
                @endif
                <button wire:click="save" wire:loading.attr="disabled" class="kt-btn kt-btn-primary gap-2">
                    <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-check"></i> Save to the site
                    </span>
                    <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Saving…
                    </span>
                </button>
            </div>
        @endif
    </div>

    @if (! $found)

        <div class="kt-card border-destructive/30">
            <div class="kt-card-content flex flex-col items-center py-16 text-center">
                <i class="ki-filled ki-information-2 text-3xl text-destructive mb-3"></i>
                <h2 class="text-lg font-semibold text-mono">This could not be opened</h2>
                <p class="text-sm text-secondary-foreground mt-1 max-w-lg">{{ $error }}</p>
                <a href="{{ route('site.content', ['type' => $type]) }}" wire:navigate
                   class="kt-btn kt-btn-sm kt-btn-outline mt-4">Back to the list</a>
            </div>
        </div>

    @else

        <div class="grid gap-5 lg:grid-cols-3">

            <div class="lg:col-span-2 flex flex-col gap-5">

                <div class="kt-card">
                    <div class="kt-card-header">
                        <h2 class="kt-card-title">Content</h2>
                    </div>
                    <div class="kt-card-content flex flex-col gap-4">
                        <div>
                            <label class="kt-form-label" for="post-title">Title</label>
                            <input id="post-title" wire:model="title" type="text" class="kt-input">
                        </div>

                        <div>
                            <label class="kt-form-label" for="post-slug">Slug</label>
                            <input id="post-slug" wire:model="slug" type="text" class="kt-input">
                            <p class="text-xs text-muted-foreground mt-1">
                                Changing this changes the URL. Anything already linking to the old one will 404
                                unless the site redirects it.
                            </p>
                        </div>

                        <div>
                            <label class="kt-form-label" for="post-excerpt">Excerpt</label>
                            <textarea id="post-excerpt" wire:model="excerpt" rows="2" class="kt-textarea"></textarea>
                        </div>

                        <div>
                            <label class="kt-form-label" for="post-content">Body</label>
                            <textarea id="post-content" wire:model="content" rows="18"
                                      class="kt-textarea font-mono text-xs"></textarea>
                            <p class="text-xs text-muted-foreground mt-1">
                                What WordPress stores, not what it renders. On a block-editor site that is block
                                markup with its HTML comments — leave them alone and the blocks survive.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header">
                        <h2 class="kt-card-title">Search and social</h2>
                        @if ($seoEditable)
                            <span class="kt-badge kt-badge-sm kt-badge-success">Editable</span>
                        @else
                            <span class="kt-badge kt-badge-sm kt-badge-warning">Not exposed</span>
                        @endif
                    </div>

                    @if (! $seoEditable)
                        <div class="kt-card-content">
                            <p class="text-sm text-secondary-foreground">
                                This site is not exposing Rank Math's fields over the REST API, so they cannot be
                                edited from here. That is WordPress's default rather than a fault: post meta stays
                                invisible to the API until something registers it.
                            </p>
                            <button wire:click="$toggle('showSnippet')" class="kt-btn kt-btn-sm kt-btn-outline mt-3">
                                {{ $showSnippet ? 'Hide' : 'Show' }} the few lines that fix it
                            </button>
                        </div>
                    @else
                        <div class="kt-card-content flex flex-col gap-4">
                            @foreach ($seoFields as $key => $field)
                                <div>
                                    <label class="kt-form-label" for="seo-{{ $key }}">
                                        {{ $field['label'] }}
                                        @if ($field['limit'])
                                            <span class="text-xs font-normal {{ mb_strlen($seo[$key] ?? '') > $field['limit'] ? 'text-warning' : 'text-muted-foreground' }}">
                                                {{ mb_strlen($seo[$key] ?? '') }} / {{ $field['limit'] }}
                                            </span>
                                        @endif
                                    </label>

                                    @if ($field['rows'] > 1)
                                        <textarea id="seo-{{ $key }}" wire:model.live.debounce.300ms="seo.{{ $key }}"
                                                  rows="{{ $field['rows'] }}" class="kt-textarea"></textarea>
                                    @else
                                        <input id="seo-{{ $key }}" wire:model.live.debounce.300ms="seo.{{ $key }}"
                                               type="text" class="kt-input">
                                    @endif

                                    <p class="text-xs text-muted-foreground mt-1">{{ $field['hint'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($showSnippet)
                        <div class="kt-card-footer flex-col items-start gap-2">
                            <p class="text-xs text-secondary-foreground">
                                Save this as <code class="text-mono">wp-content/mu-plugins/rank-math-rest.php</code>.
                                An mu-plugin rather than the theme's functions.php, which a theme update empties.
                            </p>
                            <pre class="kt-scrollable-x-auto w-full bg-muted rounded p-3 text-xs font-mono text-mono">{{ $snippet }}</pre>
                        </div>
                    @endif
                </div>

            </div>

            <div class="flex flex-col gap-5">
                <div class="kt-card">
                    <div class="kt-card-header">
                        <h2 class="kt-card-title">Status</h2>
                    </div>
                    <div class="kt-card-content flex flex-col gap-3">
                        <select wire:model="status" class="kt-select">
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                            @if (! array_key_exists($status, $statuses))
                                <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                            @endif
                        </select>

                        @if ($status !== ($original['status'] ?? $status))
                            <p class="text-xs text-warning">
                                Saving changes this from
                                “{{ $statuses[$original['status'] ?? ''] ?? ($original['status'] ?? '') }}”
                                to “{{ $statuses[$status] ?? $status }}” on the live site.
                            </p>
                        @endif
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header">
                        <h2 class="kt-card-title">What will be sent</h2>
                    </div>
                    <div class="kt-card-content">
                        <p class="text-xs text-secondary-foreground">
                            Only the fields you have changed. Everything else on this
                            {{ strtolower(\Modules\Site\Support\PostTypes::label($type)) }} — its featured image,
                            its template, its custom fields — is left exactly as the site has it.
                        </p>
                    </div>
                </div>
            </div>

        </div>

    @endif

</div>
