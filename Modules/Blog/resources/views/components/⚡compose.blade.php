<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Blog\Models\Article;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Data\Contracts\AttachmentService;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\PostPublisher;
use Modules\Social\Support\Networks;

/**
 * One article, several destinations, one intention.
 *
 * This is the page the whole module exists for. An article goes to a WordPress
 * site *and* a teaser goes to the social networks, from one press of one button,
 * because that is what actually happens when somebody publishes something and
 * the alternative is remembering to do it twice.
 *
 * **Nothing here publishes.** It writes a `posts` row, a `blog_articles` row and
 * one `post_targets` row per destination, and then hands the post to
 * `Modules\Social\Services\PostPublisher` — the same class the one-minute cron
 * hands a scheduled post to. Pressing publish twice cannot send twice, a
 * destination with no credentials records the reason on its own target while the
 * others go out, and a scheduled article is not a special kind of article, it is
 * the same rows with `scheduled_for` set. None of that is code in this file, and
 * that is the point of making a WordPress site a `social_accounts` row in the
 * first place — see `Modules\Social\Support\Networks::WORDPRESS`.
 *
 * ## The split between the body and the teaser
 *
 * The article body goes to the article destinations. The teaser goes everywhere
 * else, as `post_targets.body_override`, because an eight-hundred-word article
 * is not a Bluesky post and pretending otherwise would fail three targets out of
 * four on a character limit. Left empty, the teaser falls back to the excerpt and
 * then to the title, so the common case — an article and a link-shaped
 * announcement — needs nothing typed twice.
 *
 * ## What an article destination is
 *
 * WordPress, DEV.to and Hashnode: the three networks whose drivers implement
 * `Publishers\TakesTargetOptions` and read an article out of
 * `post_targets.options`. They are named in `articleNetworks()` below rather than
 * compared against `Networks::WORDPRESS` inline, which is what this page used to
 * do — and the symptom of leaving it that way was quiet rather than loud: a
 * DEV.to target would have been written with `options` set to null, published the
 * article body as a teaser, and derived its title from the body's first line
 * while a perfectly good one sat in the form.
 *
 * The list is here rather than in `Networks` because it is this page's own
 * question — "does this destination get the article or the teaser" — and Social's
 * catalogue has no opinion about that. A driver is the authority on what it
 * accepts; when the fourth arrives, this list is the one line that changes.
 *
 * ## What goes where
 *
 * The title, slug, excerpt, canonical link and cover image describe the article
 * and live on `blog_articles`. The status, categories and tags are
 * per-destination and go in `post_targets.options`, because the same article is
 * reasonably a draft on the client's site and published on your own — see the
 * migration that added that column, and `Publishers\TakesTargetOptions` for how
 * the driver is handed them. One bag is written and shared by every article
 * destination on the post; each driver takes the keys that mean something on its
 * own network and ignores the rest, which is why `categories` going to DEV.to
 * costs nothing.
 *
 * ## Images
 *
 * Held as temporary uploads until `submit()`, exactly as `social::publish` does
 * it: a file has to be attached *to* something and there is nothing to attach it
 * to until the post row exists. The cover is chosen by position while composing
 * and recorded by attachment id afterwards, so reordering later cannot silently
 * change which picture is the cover.
 */
new
#[Title('Compose article — Kargah')]
class extends Component
{
    use InteractsWithToasts;
    use WithFileUploads;

    public string $title = '';

    public string $slug = '';

    public string $excerpt = '';

    public string $body = '';

    /** The copy every non-WordPress destination receives. See the class docblock. */
    public string $teaser = '';

    public string $canonicalUrl = '';

    /** Comma separated, because a person types tags rather than picks them. */
    public string $categories = '';

    public string $tags = '';

    /** One of the statuses `WordPressPublisher` will send. */
    public string $wpStatus = 'publish';

    public bool $createMissingTerms = true;

    /** @var array<int, int|string> Account ids this article is going to. */
    public array $targets = [];

    /**
     * Pictures queued for this article, in the order they will be sent.
     *
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    public array $uploads = [];

    /** Which queued picture becomes the WordPress featured image. */
    public ?int $featuredIndex = 0;

    /** One of 'now', 'later', 'draft'. */
    public string $schedule = 'now';

    public string $scheduledAt = '';

    /** Per-request memo; see the note on social::publish about why this is private. */
    private ?Collection $resolvedAccounts = null;

    /**
     * Start with every destination that could actually receive this ticked.
     *
     * An unconnected account is left unticked rather than hidden, for the same
     * reason the social composer does it: aiming at one records that its
     * credentials are missing, which is more useful than the destination
     * quietly not being on the page.
     */
    public function mount(): void
    {
        $this->targets = $this->accounts()
            ->filter(fn (SocialAccount $account): bool => $account->isConnected())
            ->pluck('id')
            ->all();
    }

    /** @return Collection<int, SocialAccount> */
    private function accounts(): Collection
    {
        return $this->resolvedAccounts ??= SocialAccount::query()->inReadingOrder()->get();
    }

    /**
     * The destinations that receive the article rather than the teaser.
     *
     * See the class docblock. One line to change when a fourth arrives, and the
     * three places below ask this rather than each remembering the list.
     *
     * @return list<string>
     */
    private function articleNetworks(): array
    {
        return [Networks::WORDPRESS, Networks::DEVTO, Networks::HASHNODE];
    }

    private function isArticleDestination(SocialAccount $account): bool
    {
        return in_array($account->network, $this->articleNetworks(), true);
    }

    /** Form input arrives as strings. Compare ids as ids. */
    private function targetIds(): array
    {
        return array_map('intval', $this->targets);
    }

    /** @return Collection<int, SocialAccount> */
    private function selected(): Collection
    {
        $ids = $this->targetIds();

        return $this->accounts()
            ->filter(fn (SocialAccount $account): bool => in_array($account->id, $ids, true))
            ->values();
    }

    public function with(): array
    {
        $selected = $this->selected();

        return [
            'accounts' => $this->accounts(),
            'destinations' => $this->destinations(),
            'blogCount' => $selected->filter(fn (SocialAccount $a): bool => $this->isArticleDestination($a))->count(),
            'socialCount' => $selected->reject(fn (SocialAccount $a): bool => $this->isArticleDestination($a))->count(),
            'connectedCount' => $selected->filter(fn (SocialAccount $a): bool => $a->isConnected())->count(),
            'socialCopy' => $this->teaserText(),
            'hasMedia' => $this->uploads !== [],
            'mediaProblems' => $this->mediaProblems(),
            'statuses' => [
                'publish' => 'Publish it',
                'draft' => 'Leave it as a draft',
                'pending' => 'Submit it for review',
                'private' => 'Publish it privately',
            ],
        ];
    }

    /**
     * Every ticked destination with the copy and the limit that apply to it.
     *
     * Built here rather than in the template because two different strings are
     * being counted against two different allowances: an article destination
     * receives the article body, everything else receives the teaser, and
     * Telegram's own allowance drops to a caption's 1,024 the moment a picture
     * is attached.
     * `Networks::limitWithMedia()` is the one place that knows the last part and
     * `social::publish` already asks it the same way.
     *
     * @return list<array{account: SocialAccount, is_blog: bool, copy: string, length: int, limit: int, over: bool}>
     */
    private function destinations(): array
    {
        $hasMedia = $this->uploads !== [];
        $rows = [];

        foreach ($this->selected() as $account) {
            $isBlog = $this->isArticleDestination($account);
            $copy = $isBlog ? trim($this->body) : $this->teaserText();
            $limit = $account->characterLimitWith($hasMedia);

            $rows[] = [
                'account' => $account,
                'is_blog' => $isBlog,
                'copy' => $copy,
                'length' => mb_strlen($copy),
                'limit' => $limit,
                'over' => mb_strlen($copy) > $limit,
            ];
        }

        return $rows;
    }

    /**
     * What the ticked destinations make of what is currently attached.
     *
     * **The three article destinations disagree about pictures and the gap is
     * wide enough to lose a post in.** WordPress uploads bytes to its own media
     * library and takes ten of them; DEV.to and Hashnode have no upload endpoint
     * at all — each takes a single cover image as a URL it fetches — so the
     * catalogue caps both at one. Attach two pictures to an article going to all
     * three and WordPress publishes while the other two fail at send time with
     * “it takes at most 1 image and this post has 2”, which is
     * `HttpPublisher::acceptableMedia()` doing exactly the right thing far too
     * late to be useful.
     *
     * `social::publish` has said this while a person is attaching since the
     * media pipeline shipped, and this page did not — it checked MIME types and
     * nothing else. Same idea, same wording, said in the place where it can
     * still be acted on: a sentence under the picker beats a red target row an
     * hour later. Deliberately a warning rather than a block, because the person
     * may well mean it — the article gets its cover, the extras reach WordPress,
     * and nothing is silently dropped without having been named first.
     *
     * @return list<string>
     */
    private function mediaProblems(): array
    {
        if ($this->uploads === []) {
            return [];
        }

        $problems = [];

        foreach ($this->selected() as $account) {
            $rules = $account->mediaRules();
            $label = $account->label();

            if (count($this->uploads) > $rules['max_count']) {
                $problems[] = $rules['max_count'] === 0
                    ? $label.' takes no images at all, so this post would fail there.'
                    : $label.' takes at most '.$rules['max_count'].' '
                        .($rules['max_count'] === 1 ? 'image' : 'images').', and there are '.count($this->uploads).'.';
            }

            foreach ($this->uploads as $upload) {
                $mime = (string) $upload->getMimeType();

                if (! in_array($mime, $rules['mimes'], true)) {
                    $problems[] = $label.' does not accept '.$mime.', so “'.$upload->getClientOriginalName().'” cannot go to it.';

                    // Nothing further to say about a file this destination will
                    // not take at all; its size is beside the point.
                    continue;
                }

                if ($upload->getSize() > $rules['max_bytes']) {
                    $problems[] = '“'.$upload->getClientOriginalName().'” is larger than '.$label.' accepts.';
                }
            }
        }

        // Keyed and re-listed: two destinations refusing the same WebP is one
        // sentence worth reading, not two.
        return array_keys(array_flip($problems));
    }

    /**
     * The copy the social destinations receive.
     *
     * Falls back rather than refusing, because "the article's excerpt" and
     * "the article's title" are both perfectly good announcements and asking
     * somebody to retype one of them into a third box is how a feature stops
     * being used.
     */
    private function teaserText(): string
    {
        foreach ([$this->teaser, $this->excerpt, $this->title] as $candidate) {
            $candidate = trim($candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    public function toggleTarget(int $accountId): void
    {
        $account = $this->accounts()->firstWhere('id', $accountId);

        if ($account === null) {
            $this->toastError('That destination is no longer here', 'Reload the page and try again.');

            return;
        }

        $current = $this->targetIds();

        $this->targets = in_array($accountId, $current, true)
            ? array_values(array_diff($current, [$accountId]))
            : [...$current, $accountId];

        if (in_array($accountId, $this->targetIds(), true) && ! $account->isConnected()) {
            $this->toastWarning(
                $account->label().' credentials are not configured',
                'It will be recorded as a failed destination rather than published to.',
            );
        }
    }

    /* Pictures ------------------------------------------------------------- */

    /**
     * The gate that runs the moment a file lands.
     *
     * The same two rules `social::publish` applies, and for the same reason:
     * a still image of a type at least one destination can use, under the most
     * generous ceiling in the catalogue. Everything narrower is per-network and
     * is reported against the destination it belongs to.
     */
    public function updatedUploads(): void
    {
        $this->validate([
            'uploads.*' => [
                'file',
                'mimetypes:'.implode(',', Networks::acceptedImageMimes()),
                // Kilobytes. Ten megabytes is the most generous ceiling in the
                // catalogue, so nothing larger can reach any destination.
                'max:10240',
            ],
        ], [
            'uploads.*.mimetypes' => 'Kargah publishes still images only — JPEG, PNG, GIF or WebP.',
            'uploads.*.max' => 'No destination here takes an image over 10 MB.',
        ]);

        $this->uploads = array_values($this->uploads);

        if ($this->featuredIndex === null || ! isset($this->uploads[$this->featuredIndex])) {
            $this->featuredIndex = $this->uploads === [] ? null : 0;
        }
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);

        $this->uploads = array_values($this->uploads);
        $this->featuredIndex = $this->uploads === [] ? null : 0;
    }

    public function setFeatured(int $index): void
    {
        if (isset($this->uploads[$index])) {
            $this->featuredIndex = $index;
        }
    }

    /* Writing --------------------------------------------------------------- */

    /**
     * Write the post, the article and one target per destination.
     *
     * The write is the same for all three modes — a draft is real rows with real
     * destinations, which is what makes scheduling it later an edit rather than
     * a fresh composition.
     */
    public function submit(): void
    {
        $title = trim($this->title);
        $body = trim($this->body);

        if ($title === '') {
            $this->toastError('The article has no title', 'WordPress needs one, and so does anybody reading the list.');

            return;
        }

        if ($body === '') {
            $this->toastError('The article has nothing in it', 'Write something before publishing or scheduling.');

            return;
        }

        $accounts = $this->selected();

        if ($accounts->isEmpty()) {
            $this->toastError('Pick at least one destination', 'Nothing was written.');

            return;
        }

        $over = array_filter($this->destinations(), static fn (array $row): bool => $row['over']);

        if ($over !== []) {
            $first = reset($over);

            $this->toastError(
                'The copy is too long for '.$first['account']->label(),
                'It allows '.number_format($first['limit']).' characters and this is '
                .number_format($first['length']).'. Shorten the teaser or untick it. Nothing was written.',
            );

            return;
        }

        $when = $this->scheduledFor();

        if ($this->schedule === 'later' && $when === null) {
            $this->toastError('That is not a date Kargah can read', 'Pick the day and time this should go out.');

            return;
        }

        if ($this->schedule === 'later' && $when->isPast()) {
            $this->toastError('That time has already passed', 'Pick a time in the future, or publish it now.');

            return;
        }

        $post = Post::query()->create([
            'body' => $body,
            'status' => $this->schedule === 'later' ? Post::SCHEDULED : Post::DRAFT,
            'scheduled_for' => $when,
            'created_by' => auth()->id(),
        ]);

        // Attached before the targets exist and long before anything is
        // published: `PostPublisher` resolves a post's images from the
        // attachment rows, so a target claimed before they are written would
        // send the article without its pictures.
        $attachmentIds = $this->attachTo($post);

        $featured = $this->featuredIndex !== null ? ($attachmentIds[$this->featuredIndex] ?? null) : null;

        Article::query()->create([
            'post_id' => $post->id,
            'title' => $title,
            'slug' => trim($this->slug) === '' ? null : trim($this->slug),
            'excerpt' => trim($this->excerpt) === '' ? null : trim($this->excerpt),
            'canonical_url' => trim($this->canonicalUrl) === '' ? null : trim($this->canonicalUrl),
            'featured_attachment_id' => $featured,
        ]);

        $teaser = $this->teaserText();
        $options = $this->optionsFor($featured);

        foreach ($accounts as $account) {
            $isBlog = $this->isArticleDestination($account);

            PostTarget::query()->create([
                'post_id' => $post->id,
                'social_account_id' => $account->id,
                // An article destination reads `posts.body`, which is the
                // article. Everything else reads the teaser, and only when it
                // genuinely differs, so an override never freezes a copy that
                // was going to follow the post anyway.
                'body_override' => $isBlog || $teaser === $body || $teaser === '' ? null : $teaser,
                'options' => $isBlog ? $options : null,
                'status' => PostTarget::PENDING,
            ]);
        }

        if ($this->schedule === 'draft') {
            $this->flashToast(
                'success',
                'Saved as a draft',
                'It is aimed at '.$accounts->count().' '.($accounts->count() === 1 ? 'destination' : 'destinations')
                .' and will not go out until you schedule or publish it.',
            );

            $this->redirectRoute('blog.articles', navigate: true);

            return;
        }

        if ($this->schedule === 'later') {
            $this->flashToast(
                'success',
                'Scheduled for '.$when->format('j M Y, H:i'),
                'The scheduler checks every minute, so it goes out within a minute of that time.',
            );

            $this->redirectRoute('blog.articles', navigate: true);

            return;
        }

        $report = app(PostPublisher::class)->publishPost($post->refresh());

        if ($report->failed === 0) {
            $this->flashToast('success', 'Published', $report->summary());
        } elseif ($report->published > 0) {
            $this->flashToast('warning', 'Published to some destinations and not others', $report->firstError());
        } else {
            $this->flashToast('error', 'Nothing was published', $report->firstError());
        }

        $this->redirectRoute('blog.articles', navigate: true);
    }

    /**
     * The bag every article destination is handed.
     *
     * Written once and shared by every article target on this post, because the
     * composer only offers one set of them. The column is per target so that a
     * later edit can give one site a different status without touching the
     * other, which is exactly the case a single column on `posts` could not have
     * expressed.
     *
     * The keys are a union rather than a per-network shape, and that is the
     * point of the design: `WordPressPublisher` reads `slug` and `categories`,
     * `DevToPublisher` reads `excerpt` as a description and normalises the tags
     * down to four lowercase ones, `HashnodePublisher` turns each tag into a
     * `{slug, name}` object — and each of them ignores what it has no use for.
     * Nothing here has to know which is which, which is why adding the third
     * destination cost this method nothing.
     *
     * @return array<string, mixed>
     */
    private function optionsFor(?int $featured): array
    {
        $options = [
            'title' => trim($this->title),
            'status' => $this->wpStatus,
            'create_missing_terms' => $this->createMissingTerms,
        ];

        foreach (['slug' => $this->slug, 'excerpt' => $this->excerpt, 'canonical_url' => $this->canonicalUrl] as $key => $value) {
            if (trim($value) !== '') {
                $options[$key] = trim($value);
            }
        }

        foreach (['categories' => $this->categories, 'tags' => $this->tags] as $key => $value) {
            $names = $this->namesFrom($value);

            if ($names !== []) {
                $options[$key] = $names;
            }
        }

        if ($featured !== null) {
            $options['featured_attachment_id'] = $featured;
        }

        return $options;
    }

    /**
     * A comma separated field as a list of names.
     *
     * Deduplicated case-insensitively while keeping what the person typed: the
     * driver matches a term on the site case-insensitively too, so "Release
     * Notes" and "release notes" are one lookup and one category rather than a
     * race to create the same term twice.
     *
     * @return list<string>
     */
    private function namesFrom(string $value): array
    {
        $names = [];

        foreach (explode(',', $value) as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $names[mb_strtolower($name)] ??= $name;
        }

        return array_values($names);
    }

    /**
     * Turn the queued uploads into attachment rows, in the order shown.
     *
     * @return list<int> attachment ids, in the same order as `$uploads`
     */
    private function attachTo(Post $post): array
    {
        if ($this->uploads === []) {
            return [];
        }

        $attachments = app(AttachmentService::class);
        $ids = [];

        foreach ($this->uploads as $upload) {
            $stored = $attachments->attach($post, $upload, auth()->id());

            $ids[] = (int) $stored['id'];
        }

        // Cleared so the redirect cannot leave temporary files pointed at a post
        // that already owns copies of them.
        $this->uploads = [];

        return $ids;
    }

    /**
     * The scheduled moment, or null when there is not one to read.
     *
     * `datetime-local` gives 'Y-m-d\TH:i' when a browser fills it and anything
     * at all when a person types into it, so this refuses rather than guesses.
     */
    private function scheduledFor(): ?\Illuminate\Support\Carbon
    {
        if ($this->schedule !== 'later' || trim($this->scheduledAt) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($this->scheduledAt);
        } catch (\Throwable) {
            return null;
        }
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Compose article</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                One article to your blog, and a teaser to everywhere else, in a single go.
            </p>
        </div>
        <a href="{{ route('blog.articles') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
            <i class="ki-filled ki-notepad"></i> Articles
        </a>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        {{-- The article --}}
        <div class="col-span-12 lg:col-span-7 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label text-xs" for="article-title">Title</label>
                        <input id="article-title" type="text"
                               class="kt-input @error('title') border-destructive @enderror"
                               placeholder="What the board views taught me about scope"
                               wire:model.live.debounce.300ms="title">
                        @error('title')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <textarea class="kt-textarea min-h-[220px] text-sm"
                              placeholder="Write the article. A blank line starts a new paragraph."
                              aria-label="Article body"
                              wire:model.live.debounce.300ms="body"></textarea>

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-xs text-muted-foreground">{{ number_format(mb_strlen($body)) }} characters</span>
                        <span class="text-xs text-muted-foreground">
                            {{ $connectedCount }} of {{ count($destinations) }} selected destinations can publish
                        </span>
                    </div>

                    <div class="border-t border-border pt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1">
                            <label class="kt-form-label text-xs" for="article-slug">Slug</label>
                            <input id="article-slug" type="text" class="kt-input"
                                   placeholder="board-views-and-scope"
                                   wire:model="slug">
                            <span class="text-[11px] text-muted-foreground">
                                WordPress only. Left empty it makes one from the title, and DEV.to and Hashnode always do.
                            </span>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="kt-form-label text-xs" for="article-canonical">Canonical link</label>
                            <input id="article-canonical" type="url" class="kt-input"
                                   placeholder="https://kargah.dev/notes/board-views"
                                   wire:model="canonicalUrl">
                            <span class="text-[11px] text-muted-foreground">
                                Where it was first published, if that is somewhere else. It is credited at the foot of the post.
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label text-xs" for="article-excerpt">Excerpt</label>
                        <textarea id="article-excerpt" class="kt-textarea min-h-[110px] text-sm"
                                  placeholder="A sentence or two for the blog index and for anywhere the teaser is empty."
                                  wire:model.live.debounce.300ms="excerpt"></textarea>
                    </div>

                </div>
            </div>

            {{-- Images --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Images</h3>
                    <span class="text-xs text-muted-foreground">The starred one becomes the featured image</span>
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-3">

                    @if (count($uploads) > 0)
                        <div class="flex flex-wrap gap-3">
                            @foreach ($uploads as $index => $upload)
                                <div class="relative w-[140px]" wire:key="upload-{{ $index }}-{{ $upload->getFilename() }}">
                                    {{-- `temporaryUrl()` throws for anything Livewire will not preview, and a file
                                         that failed validation is still in this array when the page re-renders.

                                         `size-16` rather than the `h-28 w-28 object-cover` social::publish uses:
                                         none of those three classes is in the compiled sheet, so that page's
                                         thumbnails render unsized today. See the note in this module's report. --}}
                                    @if ($upload->isPreviewable())
                                        <img src="{{ $upload->temporaryUrl() }}"
                                             alt="{{ $upload->getClientOriginalName() }}"
                                             class="size-16 rounded-lg border border-border">
                                    @else
                                        <div class="size-16 rounded-lg border border-dashed border-destructive flex flex-col items-center justify-center gap-1 text-center px-2">
                                            <i class="ki-filled ki-picture text-xl text-destructive"></i>
                                            <span class="text-[11px] text-destructive">Not an image</span>
                                        </div>
                                    @endif

                                    <button type="button" wire:click="removeUpload({{ $index }})"
                                            aria-label="Remove {{ $upload->getClientOriginalName() }}"
                                            class="absolute top-1 end-1 kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost bg-background">
                                        <i class="ki-filled ki-cross text-xs"></i>
                                    </button>

                                    <button type="button" wire:click="setFeatured({{ $index }})"
                                            aria-label="Make {{ $upload->getClientOriginalName() }} the featured image"
                                            title="Make this the featured image"
                                            class="absolute top-1 start-1 kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost bg-background">
                                        <i class="ki-filled ki-check-circle text-xs {{ $featuredIndex === $index ? 'text-primary' : 'text-muted-foreground' }}"></i>
                                    </button>

                                    <span class="block text-[11px] text-muted-foreground truncate mt-1"
                                          title="{{ $upload->getClientOriginalName() }}">
                                        {{ $upload->getClientOriginalName() }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <label class="rounded-lg border border-dashed border-border bg-accent/60 px-5 py-4 flex flex-col items-center gap-1 text-center cursor-pointer">
                        <i class="ki-filled ki-picture text-xl text-muted-foreground"></i>
                        <span class="text-sm text-secondary-foreground">
                            {{ count($uploads) > 0 ? 'Add another image' : 'Attach an image' }}
                        </span>
                        <input type="file" multiple accept="image/jpeg,image/png,image/gif,image/webp"
                               class="hidden" wire:model="uploads">
                        <span class="text-[11px] text-muted-foreground">
                            The featured one becomes the cover everywhere. WordPress appends the rest to the post as
                            figures; DEV.to and Hashnode take one picture each and fetch it from this install, so they
                            publish without a cover when Kargah has no public address.
                        </span>
                    </label>

                    <div wire:loading wire:target="uploads" class="text-xs text-secondary-foreground">
                        <i class="ki-filled ki-loading animate-spin"></i> Receiving…
                    </div>

                    @error('uploads.*')<span class="text-xs text-destructive">{{ $message }}</span>@enderror

                    {{--
                        What the ticked destinations make of what is attached,
                        said here rather than discovered from a red target row an
                        hour later. A warning, not a block — see `mediaProblems()`
                        for why. `social::publish` has done this since the media
                        pipeline shipped; this page had only a MIME check.
                    --}}
                    @if ($mediaProblems !== [])
                        <div class="flex flex-col gap-1.5 rounded-lg border border-warning/30 bg-warning/10 px-3.5 py-3">
                            @foreach ($mediaProblems as $problem)
                                <div class="flex items-start gap-2.5">
                                    <i class="ki-filled ki-information-2 text-warning text-base mt-0.5 shrink-0"></i>
                                    <span class="text-xs text-secondary-foreground">{{ $problem }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                </div>
            </div>

            {{-- The teaser --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Teaser</h3>
                    <span class="text-xs text-muted-foreground">What the social destinations receive</span>
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-2">
                    <textarea class="kt-textarea min-h-[110px] text-sm"
                              placeholder="New post: what building four board views taught me about scope."
                              aria-label="Teaser"
                              wire:model.live.debounce.300ms="teaser"></textarea>
                    <p class="text-xs text-muted-foreground">
                        @if (trim($teaser) === '' && $socialCopy !== '')
                            Empty, so the {{ $socialCount }} social {{ $socialCount === 1 ? 'destination' : 'destinations' }}
                            will receive “{{ \Illuminate\Support\Str::limit($socialCopy, 80) }}”.
                        @else
                            The article body goes to the blog destinations. This goes everywhere else.
                        @endif
                    </p>
                </div>
            </div>

        </div>

        {{-- Destinations and options --}}
        <div class="col-span-12 lg:col-span-5 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Publish to</h3>
                    <a href="{{ route('social.account-connect') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost">Connect</a>
                </div>
                <div class="kt-card-content p-3 flex flex-col gap-1">
                    @forelse ($accounts as $account)
                        @php $active = in_array($account->id, array_map('intval', $targets), true); @endphp
                        <button wire:click="toggleTarget({{ $account->id }})" wire:key="target-{{ $account->id }}"
                                class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-start transition-colors
                                       {{ $active ? 'bg-primary/10' : 'hover:bg-accent/50' }}">
                            <i class="ki-filled {{ $account->icon() }} text-lg shrink-0 {{ $active ? 'text-primary' : 'text-muted-foreground' }}"></i>
                            <span class="min-w-0 grow">
                                <span class="block text-sm font-medium text-mono">{{ $account->label() }}</span>
                                <span class="block text-xs text-muted-foreground truncate">{{ $account->handle }}</span>
                                @if (! $account->isConnected())
                                    <span class="block text-xs text-muted-foreground">Credentials not configured</span>
                                @endif
                            </span>
                            @if ($active)
                                <i class="ki-filled ki-check-circle text-primary text-base shrink-0"></i>
                            @endif
                        </button>
                    @empty
                        <div class="flex flex-col items-center py-10 text-center">
                            <i class="ki-filled ki-abstract-26 text-3xl text-muted-foreground mb-3"></i>
                            <p class="text-sm text-secondary-foreground">No destinations yet.</p>
                            <a href="{{ route('social.account-connect') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-3">
                                Connect a site or an account
                            </a>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Counters, one per ticked destination --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">What each one receives</h3>
                </div>
                <div class="kt-card-content p-0 divide-y divide-border">
                    @forelse ($destinations as $row)
                        <div class="px-4 py-3 flex items-center justify-between gap-3" wire:key="count-{{ $row['account']->id }}">
                            <div class="flex items-center gap-2 min-w-0">
                                <i class="ki-filled {{ $row['account']->icon() }} text-base text-muted-foreground shrink-0"></i>
                                <span class="text-sm text-mono truncate">{{ $row['account']->label() }}</span>
                                <span class="kt-badge kt-badge-sm kt-badge-outline shrink-0">
                                    {{ $row['is_blog'] ? 'Article' : 'Teaser' }}
                                </span>
                            </div>
                            <span class="text-xs shrink-0 {{ $row['over'] ? 'text-destructive' : 'text-muted-foreground' }}">
                                {{ number_format($row['length']) }} / {{ number_format($row['limit']) }}
                            </span>
                        </div>
                    @empty
                        <div class="flex flex-col items-center py-10 text-center">
                            <i class="ki-filled ki-element-11 text-3xl text-muted-foreground mb-3"></i>
                            <p class="text-sm text-secondary-foreground">Pick at least one destination.</p>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Article destination options. One bag, shared by every blog destination
                 on this post; each driver takes the keys that mean something to it. --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">On the blog</h3>
                    <span class="text-xs text-muted-foreground">
                        {{ $blogCount === 0 ? 'No blog destination ticked' : $blogCount . ' ' . ($blogCount === 1 ? 'destination' : 'destinations') }}
                    </span>
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label text-xs" for="wp-status">When it arrives</label>
                        <select id="wp-status" class="kt-select" wire:model="wpStatus">
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="text-[11px] text-muted-foreground">
                            These are WordPress's four. DEV.to has only published or not, so anything except Publish it
                            arrives there as an unpublished draft. Hashnode can only publish outright and records
                            anything else as a failed destination rather than putting a draft on a public blog.
                        </span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label text-xs" for="wp-categories">Categories</label>
                        <input id="wp-categories" type="text" class="kt-input"
                               placeholder="Build log, Tooling" wire:model="categories">
                        <span class="text-[11px] text-muted-foreground">
                            WordPress only. Neither DEV.to nor Hashnode has categories, and both ignore these.
                        </span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label text-xs" for="wp-tags">Tags</label>
                        <input id="wp-tags" type="text" class="kt-input"
                               placeholder="laravel, livewire, scope" wire:model="tags">
                        <span class="text-[11px] text-muted-foreground">
                            DEV.to takes four at most and strips them to lowercase letters and digits, so “Build log”
                            becomes “buildlog” and a fifth tag is dropped rather than failing the article.
                        </span>
                    </div>

                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" class="kt-checkbox mt-0.5" wire:model="createMissingTerms">
                        <span class="text-xs text-secondary-foreground">
                            Create a category or tag the site does not have yet. Unticked, a name nobody has made is skipped
                            rather than added to somebody's site. WordPress only.
                        </span>
                    </label>

                </div>
            </div>

            {{-- Schedule and send --}}
            <div class="kt-card">
                <div class="kt-card-content p-5 flex flex-wrap items-end justify-between gap-3">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="flex flex-col gap-1">
                            <label class="kt-form-label text-xs" for="article-when">When</label>
                            <select id="article-when" class="kt-select max-w-[180px]" wire:model.live="schedule">
                                <option value="now">Publish now</option>
                                <option value="later">Schedule…</option>
                                <option value="draft">Save as draft</option>
                            </select>
                        </div>
                        @if ($schedule === 'later')
                            <div class="flex flex-col gap-1">
                                <label class="kt-form-label text-xs" for="article-at">Date and time</label>
                                <input id="article-at" type="datetime-local" class="kt-input max-w-[220px]" wire:model="scheduledAt">
                            </div>
                        @endif
                    </div>

                    <button wire:click="submit" wire:loading.attr="disabled"
                            class="kt-btn kt-btn-primary gap-2"
                            @disabled(empty($targets) || trim($title) === '' || trim($body) === '')>
                        <span wire:loading.remove wire:target="submit" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-paper-plane"></i>
                            {{ $schedule === 'now' ? 'Publish' : ($schedule === 'later' ? 'Schedule' : 'Save draft') }}
                        </span>
                        <span wire:loading wire:target="submit" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Working…
                        </span>
                    </button>
                </div>
            </div>

        </div>

    </div>
</div>
