<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Data\Contracts\AttachmentService;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Core\Support\ImageTranscoder;
use Modules\Social\Services\PostPublisher;
use Modules\Social\Support\Networks;

/**
 * Cross-network composer.
 *
 * Write once, pick accounts, publish or schedule. The shared text feeds every
 * account unless one has been given its own copy, and each target renders a
 * live preview so the limit is something you see rather than something you are
 * told about after the fact.
 *
 * **Publishing here is the same code path the scheduler uses.** `submit()`
 * writes the post and its targets and then hands them to `PostPublisher`, which
 * claims each target exactly as the cron job would. Pressing publish twice
 * cannot send twice, and an account with no credentials records the reason on
 * its own target while the others go out.
 *
 * The toast says what actually happened, per network. 'Published' is not a
 * truthful summary of a post that reached two networks out of three, and the
 * person's next action depends on which.
 *
 * ## Pictures
 *
 * Images are held as temporary uploads in component state and only become
 * attachment rows in `submit()`, after the post exists — a file has to be
 * attached *to* something and there is nothing to attach it to until the row is
 * written. They go through `Modules\Data\Contracts\AttachmentService` like every
 * other upload in Kargah; this page never opens a file handle.
 *
 * **Every limit is checked here, while a person is looking at the screen.**
 * Count, byte size, MIME type and — where a network has them — pixel and aspect
 * rules all come from `Modules\Social\Support\Networks` and are evaluated
 * against each selected account before anything is written, let alone queued.
 * The alternative is a red target row an hour later saying a network answered
 * 422, at which point the file is on a disk, the post is half-sent, and the
 * person has gone home. A limit discovered at the point of attaching is a
 * sentence; the same limit discovered by a failed job is an incident.
 *
 * The checks are per *selected account*, not global, because the networks
 * disagree so sharply — Bluesky refuses anything over a million bytes,
 * Telegram refuses a GIF and shortens the copy to a caption's 1,024 characters,
 * LinkedIn refuses WebP. A picture is therefore not simply valid or invalid: it
 * is fine for three of the four networks ticked, and the message has to say
 * which one it is not fine for, or the only available fix is guessing.
 *
 * **Images only.** There is no video path and there should not be one here: a
 * chunked, resumable upload spans minutes and this publishes inside one
 * request. See `Networks` and `project-guaid/spec/08-postiz-parity.md`.
 */
new
#[Title('Publish — Kargah')]
class extends Component
{
    use InteractsWithToasts;
    use WithFileUploads;

    public string $body = '';

    /** @var array<int, int|string> Account ids this post is going to. */
    public array $targets = [];

    /** Per-account copy. A key exists only while that account is overridden. */
    public array $overrides = [];

    /**
     * One of 'now', 'later', 'draft'.
     *
     * `#[Url]` because the calendar links here to start a post in a slot the
     * person clicked: ⚡calendar.blade.php builds
     * `/social/publish?schedule=later&scheduled_at=<UTC>`. Without the
     * attribute Livewire ignores the query string and the link lands on an
     * empty composer set to "now" — the time they picked silently thrown away,
     * which is worse than not offering the link at all.
     */
    #[Url]
    public string $schedule = 'now';

    /** Paired with $schedule above; see the note there. */
    #[Url(as: 'scheduled_at')]
    public string $scheduledAt = '';

    /**
     * Pictures queued for this post, in the order they will be sent.
     *
     * Temporary uploads, not attachments: nothing is attached until `submit()`
     * has written the post. Reordering here is reordering this array, which is
     * why it is cheap enough to have at all.
     *
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    public array $uploads = [];

    /** Per-request memo; see the note on ⚡boards about why these are private. */
    private ?Collection $resolvedAccounts = null;

    /**
     * `getimagesize()` results, per request, keyed by upload index.
     *
     * Geometry is read from the temporary file on disk and the validation pass
     * walks every upload against every selected network, so without this a post
     * with four images going to four networks would stat and parse each file
     * sixteen times on every keystroke that re-renders the page.
     *
     * @var array<int, array{0: int, 1: int}|null>
     */
    private array $dimensions = [];

    /**
     * Start with every account that could actually receive a post ticked.
     *
     * An unconnected account is left unticked rather than hidden: it is still
     * something you can aim at, and doing so records that its credentials are
     * missing, which is more useful than the account quietly not being there.
     *
     * An account whose module has been switched off gets the same treatment for
     * the same reason — shown, listed with the reason, but not ticked for you.
     * `isConnected()` cannot answer that on its own: a DEV.to account keeps
     * every credential it ever had when `Blog` is disabled, so it reads as
     * perfectly connected right up until the target row says nothing can send
     * to it. `Networks::isAvailable()` is the half `isConnected()` does not see.
     */
    public function mount(): void
    {
        $this->targets = $this->accounts()
            ->filter(fn (SocialAccount $a): bool => $a->isConnected() && Networks::isAvailable($a->network))
            ->pluck('id')
            ->all();
    }

    /** @return Collection<int, SocialAccount> */
    private function accounts(): Collection
    {
        return $this->resolvedAccounts ??= SocialAccount::query()->inReadingOrder()->get();
    }

    /** `#[Url]`-shaped arrays and form input arrive as strings. Compare ids as ids. */
    private function targetIds(): array
    {
        return array_map('intval', $this->targets);
    }

    /** @return Collection<int, SocialAccount> */
    private function selected(): Collection
    {
        $ids = $this->targetIds();

        return $this->accounts()->filter(fn (SocialAccount $a): bool => in_array($a->id, $ids, true))->values();
    }

    public function with(): array
    {
        $selected = $this->selected();

        return [
            'accounts' => $this->accounts(),
            'selected' => $selected,
            'catalogue' => Networks::all(),
            // Why an account on the list cannot be published to whatever its
            // credentials say, keyed by id — empty on an install with every
            // module on. Not a filter: an account that exists stays on the list
            // and says why, exactly as an unconnected one does.
            'unavailable' => $this->accounts()
                ->mapWithKeys(fn (SocialAccount $a): array => [$a->id => Networks::unavailableReason($a->network)])
                ->filter()
                ->all(),
            'connectedCount' => $selected
                ->filter(fn (SocialAccount $a): bool => $a->isConnected() && Networks::isAvailable($a->network))
                ->count(),
            'mediaProblems' => $this->mediaProblems(),
            'hasMedia' => $this->uploads !== [],
        ];
    }

    /* Pictures ------------------------------------------------------------- */

    /**
     * The gate that runs the moment a file lands, before any network is asked.
     *
     * Two rules, both about the file rather than about any particular network:
     * it has to be a still image of a type at least one network here can use,
     * and it has to be under the largest ceiling any network allows. A file
     * that fails either is rejected outright, because there is no combination
     * of ticked accounts that would make it publishable. Everything narrower
     * than that — Bluesky's million bytes, Telegram's refusal of GIFs — is a
     * per-account judgement and belongs in `mediaProblems()`, where it can name
     * the network and offer unticking it as the fix.
     */
    public function updatedUploads(): void
    {
        $this->dimensions = [];

        $this->validate([
            'uploads.*' => [
                'file',
                'mimetypes:'.implode(',', Networks::acceptedImageMimes()),
                // Kilobytes. Ten megabytes is the most generous ceiling in the
                // catalogue, so nothing larger can reach any network.
                'max:10240',
            ],
        ], [
            'uploads.*.mimetypes' => 'Kargah publishes still images only — JPEG, PNG, GIF or WebP. Video needs an upload that can span minutes, which a scheduled post cannot.',
            'uploads.*.max' => 'No network here takes an image over 10 MB.',
        ]);

        $this->uploads = array_values($this->uploads);
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);

        $this->uploads = array_values($this->uploads);
        $this->dimensions = [];
    }

    /**
     * Move one picture earlier or later in the sequence.
     *
     * Order is not decoration: a carousel is a sequence, the first image is the
     * one that appears in a timeline preview, and Telegram captions the first
     * item of an album and no other. A swap on an array is the whole
     * implementation, which is what makes this worth having rather than a
     * drag-and-drop library.
     */
    public function moveUpload(int $index, int $by): void
    {
        $to = $index + $by;

        if (! isset($this->uploads[$index], $this->uploads[$to])) {
            return;
        }

        [$this->uploads[$index], $this->uploads[$to]] = [$this->uploads[$to], $this->uploads[$index]];

        $this->uploads = array_values($this->uploads);
        $this->dimensions = [];
    }

    /**
     * Every reason the attached pictures will not go out as they stand.
     *
     * Phrased per network and per file, because that is the granularity of the
     * fix — 'too big' is not actionable when four networks are ticked and one
     * of them is the one complaining. Deduplicated, because four images that
     * are each too large for Bluesky is one sentence about Bluesky's limit
     * repeated, not four findings.
     *
     * @return list<string>
     */
    public function mediaProblems(): array
    {
        if ($this->uploads === []) {
            return [];
        }

        $problems = [];

        foreach ($this->selected() as $account) {
            foreach ($this->problemsFor($account) as $problem) {
                $problems[$problem] = true;
            }
        }

        return array_keys($problems);
    }

    /**
     * Every MIME type any connected network here will take, for the file
     * picker's `accept`.
     *
     * The union rather than the intersection, and rather than the selected
     * accounts' types: the picker is one control shared by every target, so
     * narrowing it to what today's selection allows would hide a file the person
     * is about to tick a network for. Attaching something a *selected* network
     * refuses is already answered — `problemsFor()` says which network and why,
     * in a sentence, before anything is queued.
     *
     * Deliberately read from `all()` rather than `available()`: an account
     * connected before its module was switched off still appears on this page,
     * and a picker that stopped offering its file types would be confusing in a
     * way nothing here explains.
     */
    public function acceptedMimes(): string
    {
        $mimes = [];

        foreach (Networks::all() as $entry) {
            foreach ($entry['media']['mimes'] as $mime) {
                $mimes[$mime] = true;
            }
        }

        return implode(',', array_keys($mimes));
    }

    /**
     * What one network makes of what is currently attached.
     *
     * @return list<string>
     */
    private function problemsFor(SocialAccount $account): array
    {
        $rules = $account->mediaRules();
        $label = $account->label();
        $found = [];

        if (count($this->uploads) > $rules['max_count']) {
            $found[] = $label.' takes at most '.$rules['max_count'].' '
                .($rules['max_count'] === 1 ? 'image' : 'images').', and there are '.count($this->uploads).'.';
        }

        $captionLimit = $account->characterLimitWith(true);

        if (mb_strlen($this->textFor($account->id)) > $captionLimit) {
            $found[] = $label.' allows '.number_format($captionLimit)
                .' characters once an image is attached, and this copy is '
                .number_format(mb_strlen($this->textFor($account->id))).'.';
        }

        foreach ($this->uploads as $index => $upload) {
            $name = $upload->getClientOriginalName();
            $mime = (string) $upload->getMimeType();

            if (! in_array($mime, $rules['mimes'], true)) {
                // Not a block when it is a mime `HttpPublisher::acceptableMedia()`
                // will re-encode to JPEG before it ever leaves — Instagram
                // refusing a PNG is exactly this case. Nothing further to say
                // either way: the size and shape of the *original* file are
                // beside the point once it is going out as a different one.
                if (! in_array('image/jpeg', $rules['mimes'], true) || ! ImageTranscoder::canConvert($mime)) {
                    $found[] = $label.' does not accept '.$mime.', so “'.$name.'” cannot go to it.';
                }

                continue;
            }

            if ($upload->getSize() > $rules['max_bytes']) {
                $found[] = '“'.$name.'” is '.$this->megabytes((int) $upload->getSize())
                    .' and '.$label.' accepts up to '.$this->megabytes((int) $rules['max_bytes']).'.';
            }

            $found = [...$found, ...$this->geometryProblems($index, $name, $label, $rules)];
        }

        return $found;
    }

    /**
     * The rules that need the picture's actual pixels.
     *
     * Only two networks have any: Mastodon re-encodes above a total pixel count
     * and refuses what it cannot re-encode, and Telegram refuses a photo whose
     * sides sum past 10,000 or whose longer side is more than twenty times its
     * shorter. Both are refusals rather than resizes, which is why they are
     * worth reading a file header for.
     *
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    private function geometryProblems(int $index, string $name, string $label, array $rules): array
    {
        if ($rules['max_pixels'] === null && $rules['max_dimension_sum'] === null && $rules['max_aspect_ratio'] === null) {
            return [];
        }

        $size = $this->dimensionsOf($index);

        // A file whose header will not parse is not reported here. The type
        // check has already passed, so this is a truncated or unusual encoding
        // rather than a wrong file, and the network is a better judge of it
        // than a guess made from a partial read.
        if ($size === null) {
            return [];
        }

        [$width, $height] = $size;
        $found = [];

        if ($rules['max_pixels'] !== null && $width * $height > $rules['max_pixels']) {
            $found[] = '“'.$name.'” is '.$width.'×'.$height.', which is more than '.$label
                .' will re-encode. Scale it down before attaching it.';
        }

        if ($rules['max_dimension_sum'] !== null && $width + $height > $rules['max_dimension_sum']) {
            $found[] = '“'.$name.'” is '.$width.'×'.$height.' and '.$label
                .' refuses a photo whose sides add up to more than '.number_format($rules['max_dimension_sum']).'.';
        }

        if ($rules['max_aspect_ratio'] !== null && $width > 0 && $height > 0) {
            $ratio = max($width / $height, $height / $width);

            if ($ratio > $rules['max_aspect_ratio']) {
                $found[] = '“'.$name.'” is '.$width.'×'.$height.', which is too long and thin for '.$label
                    .' — it refuses anything past '.$rules['max_aspect_ratio'].':1.';
            }
        }

        return $found;
    }

    /** @return array{0: int, 1: int}|null */
    private function dimensionsOf(int $index): ?array
    {
        if (array_key_exists($index, $this->dimensions)) {
            return $this->dimensions[$index];
        }

        $path = $this->uploads[$index]->getRealPath();

        $size = is_string($path) && $path !== '' && is_readable($path) ? @getimagesize($path) : false;

        return $this->dimensions[$index] = $size === false ? null : [(int) $size[0], (int) $size[1]];
    }

    /** Bytes as something a person can compare against a network's stated limit. */
    private function megabytes(int $bytes): string
    {
        return $bytes < 1048576
            ? max(1, (int) round($bytes / 1024)).' KB'
            : round($bytes / 1048576, 1).' MB';
    }

    /** The copy a given account will actually receive. */
    public function textFor(int $accountId): string
    {
        return $this->overrides[$accountId] ?? $this->body;
    }

    public function isOverridden(int $accountId): bool
    {
        return array_key_exists($accountId, $this->overrides);
    }

    private function account(int $id): ?SocialAccount
    {
        return $this->accounts()->firstWhere('id', $id);
    }

    public function toggleTarget(int $accountId): void
    {
        $account = $this->account($accountId);

        if ($account === null) {
            $this->toastError('That account is no longer here', 'Reload the page and try again.');

            return;
        }

        $current = $this->targetIds();

        $this->targets = in_array($accountId, $current, true)
            ? array_values(array_diff($current, [$accountId]))
            : [...$current, $accountId];

        if (! in_array($accountId, $this->targetIds(), true)) {
            unset($this->overrides[$accountId]);

            return;
        }

        // Selecting is visible in the list and in the previews; the only thing
        // worth saying is what the tick does not show.
        //
        // Unavailability is said first because it is the one a person cannot
        // work out from the screen: an account with its module switched off has
        // every credential it ever had, so "credentials are not configured"
        // would be both wrong and useless.
        if (($reason = Networks::unavailableReason($account->network)) !== null) {
            $this->toastWarning($account->label().' cannot be published to', $reason);

            return;
        }

        if (! $account->isConnected()) {
            $this->toastWarning(
                $account->label().' credentials are not configured',
                'It will be recorded as a failed target rather than published to.',
            );
        }
    }

    /** Fork this account's copy off the shared text, or fold it back in. */
    public function toggleOverride(int $accountId): void
    {
        $account = $this->account($accountId);

        if ($account === null) {
            return;
        }

        if ($this->isOverridden($accountId)) {
            unset($this->overrides[$accountId]);

            return;
        }

        $this->overrides[$accountId] = $this->body;
    }

    /** Cut the overridden copy down to whatever this network allows. */
    public function trimToLimit(int $accountId): void
    {
        $account = $this->account($accountId);

        if ($account === null) {
            return;
        }

        // Asked with the pictures in mind: Telegram's allowance drops from
        // 4,096 to 1,024 the moment one is attached, and trimming to the
        // message limit would leave the caption still over its own.
        $limit = $account->characterLimitWith($this->uploads !== []);

        // The textarea and its counter show the result, so nothing is said.
        $this->overrides[$accountId] = rtrim(mb_substr($this->textFor($accountId), 0, $limit));
    }

    /**
     * Write the post and its targets, then do what the composer was asked to.
     *
     * The write is one post plus one target per account, and it happens for all
     * three modes — a draft is a real row with real targets, which is why
     * scheduling it later is an edit rather than a fresh composition.
     */
    public function submit(): void
    {
        $body = trim($this->body);

        if ($body === '') {
            $this->toastError('The post has nothing in it', 'Write something before publishing or scheduling.');

            return;
        }

        $accounts = $this->selected();

        if ($accounts->isEmpty()) {
            $this->toastError('Pick at least one account', 'Nothing was written.');

            return;
        }

        // Checked here as well as on the page, because the page is advisory and
        // this is the last point at which nothing has been written. A post row
        // and four attachment rows are a great deal harder to take back than a
        // refusal.
        $problems = $this->mediaProblems();

        if ($problems !== []) {
            $this->toastError(
                count($problems) === 1 ? 'That image will not go out as attached' : 'Those images will not go out as attached',
                $problems[0].' Nothing was written.',
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
            'status' => match ($this->schedule) {
                'later' => Post::SCHEDULED,
                default => Post::DRAFT,
            },
            'scheduled_for' => $when,
            'created_by' => auth()->id(),
        ]);

        // Attached before the targets exist, and certainly before anything is
        // published: `PostPublisher` resolves a post's images from these rows,
        // so a target claimed before they are written would send the text on
        // its own. There is no `posts.media` to keep in step — see the docblock
        // on Modules\Social\Models\Post for why that column is dead.
        $this->attachTo($post);

        foreach ($accounts as $account) {
            PostTarget::query()->create([
                'post_id' => $post->id,
                'social_account_id' => $account->id,
                // Stored only when it genuinely differs, so a target with no
                // override keeps following the post if the post is edited.
                'body_override' => $this->isOverridden($account->id) && trim($this->textFor($account->id)) !== $body
                    ? trim($this->textFor($account->id))
                    : null,
                'status' => PostTarget::PENDING,
            ]);
        }

        if ($this->schedule === 'draft') {
            $this->flashToast(
                'success',
                'Saved as a draft',
                'It is aimed at '.$accounts->count().' '.($accounts->count() === 1 ? 'account' : 'accounts')
                .' and will not go out until you schedule or publish it.',
            );

            $this->redirectRoute('social.posts', navigate: true);

            return;
        }

        if ($this->schedule === 'later') {
            $this->flashToast(
                'success',
                'Scheduled for '.$when->format('j M Y, H:i'),
                'The scheduler checks every minute, so it goes out within a minute of that time.',
            );

            $this->redirectRoute('social.calendar', navigate: true);

            return;
        }

        // Publish now, through the same claim-and-send path cron uses.
        $report = app(PostPublisher::class)->publishPost($post->refresh());

        if ($report->failed === 0) {
            $this->flashToast('success', 'Published', $report->summary());
        } elseif ($report->published > 0) {
            $this->flashToast('warning', 'Published to some networks and not others', $report->firstError());
        } else {
            $this->flashToast('error', 'Nothing was published', $report->firstError());
        }

        $this->redirectRoute('social.post-show', ['post' => $post->id], navigate: true);
    }

    /**
     * Turn the queued uploads into attachment rows on the new post.
     *
     * In array order, because attachment ids ascend and
     * `Modules\Social\Services\PostMedia` reads them back oldest first — so the
     * sequence the person arranged on this page is the sequence the network
     * receives.
     */
    private function attachTo(Post $post): void
    {
        if ($this->uploads === []) {
            return;
        }

        $attachments = app(AttachmentService::class);

        foreach ($this->uploads as $upload) {
            $attachments->attach($post, $upload, auth()->id());
        }

        // Cleared so that the redirect cannot leave temporary files pointed at
        // a post that already owns copies of them.
        $this->uploads = [];
        $this->dimensions = [];
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
            <h1 class="text-xl font-semibold text-mono">Publish</h1>
            <p class="text-sm text-secondary-foreground mt-1">One post, every account you pick.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('social.calendar') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-calendar"></i> Calendar
            </a>
            <a href="{{ route('social.posts') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-questionnaire-tablet"></i> Queue
            </a>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        {{-- Composer --}}
        <div class="col-span-12 lg:col-span-7 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <textarea class="kt-textarea min-h-[220px] text-sm"
                              placeholder="What are you shipping today?"
                              aria-label="Post text"
                              wire:model.live.debounce.300ms="body"></textarea>

                    <div class="flex items-center justify-between">
                        <span class="text-xs text-muted-foreground">{{ mb_strlen($body) }} characters</span>
                        <span class="text-xs text-muted-foreground">
                            {{ $connectedCount }} of {{ $selected->count() }} selected accounts can publish
                        </span>
                    </div>

                    {{-- Images --}}
                    <div class="border-t border-border pt-4 flex flex-col gap-3">

                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-xs font-medium text-mono">Images</span>
                            <span class="text-xs text-muted-foreground">
                                Still images only — video needs an upload that can span minutes
                            </span>
                        </div>

                        @if (count($uploads) > 0)
                            <div class="flex flex-wrap gap-3">
                                @foreach ($uploads as $index => $upload)
                                    <div class="relative w-28" wire:key="upload-{{ $index }}-{{ $upload->getFilename() }}">
                                        {{-- `temporaryUrl()` throws for anything Livewire will not preview, and a
                                             file that failed validation is still sitting in this array when the
                                             page re-renders — so the guard is what keeps a rejected upload from
                                             turning an error message into a 500. --}}
                                        @if ($upload->isPreviewable())
                                            <img src="{{ $upload->temporaryUrl() }}"
                                                 alt="{{ $upload->getClientOriginalName() }}"
                                                 class="h-28 w-28 rounded-lg border border-border object-cover">
                                        @else
                                            <div class="h-28 w-28 rounded-lg border border-dashed border-destructive flex flex-col items-center justify-center gap-1 text-center px-2">
                                                <i class="ki-filled ki-picture text-xl text-destructive"></i>
                                                <span class="text-[11px] text-destructive">Not an image Kargah can send</span>
                                            </div>
                                        @endif
                                        <span class="absolute top-1 start-1 kt-badge kt-badge-sm kt-badge-outline bg-background">
                                            {{ $index + 1 }}
                                        </span>
                                        <button type="button" wire:click="removeUpload({{ $index }})"
                                                aria-label="Remove {{ $upload->getClientOriginalName() }}"
                                                class="absolute top-1 end-1 kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost bg-background">
                                            <i class="ki-filled ki-cross text-xs"></i>
                                        </button>
                                        <div class="flex items-center justify-between gap-1 mt-1">
                                            <button type="button" wire:click="moveUpload({{ $index }}, -1)"
                                                    aria-label="Move {{ $upload->getClientOriginalName() }} earlier"
                                                    class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost"
                                                    @disabled($index === 0)>
                                                <i class="ki-filled ki-left text-xs"></i>
                                            </button>
                                            <span class="text-[11px] text-muted-foreground truncate" title="{{ $upload->getClientOriginalName() }}">
                                                {{ $upload->getClientOriginalName() }}
                                            </span>
                                            <button type="button" wire:click="moveUpload({{ $index }}, 1)"
                                                    aria-label="Move {{ $upload->getClientOriginalName() }} later"
                                                    class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost"
                                                    @disabled($index === count($uploads) - 1)>
                                                <i class="ki-filled ki-right text-xs"></i>
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <label class="rounded-lg border border-dashed border-border bg-accent/60 px-5 py-4 flex flex-col items-center gap-1 text-center cursor-pointer">
                            <i class="ki-filled ki-picture text-xl text-muted-foreground"></i>
                            <span class="text-sm text-secondary-foreground">
                                {{ count($uploads) > 0 ? 'Add another file' : 'Attach an image or video' }}
                            </span>
                            {{--
                                🔴 Built from the catalogue, never typed out here. This attribute used to be a
                                hardcoded image list, which was already a second copy of `Networks`' `mimes` and
                                went wrong the moment YouTube arrived: the picker refused every video while the
                                validation a few lines up cheerfully allowed one. A browser filter that disagrees
                                with the rules is the worse half of the pair, because it makes a supported file
                                look impossible rather than merely rejected.
                            --}}
                            <input type="file" multiple accept="{{ $this->acceptedMimes() }}"
                                   class="hidden" wire:model="uploads">
                            <span class="text-[11px] text-muted-foreground">
                                Images for most networks; YouTube takes one video and nothing else. Each network has its own ceiling and Kargah checks against the ones you have ticked.
                            </span>
                        </label>

                        <div wire:loading wire:target="uploads" class="text-xs text-secondary-foreground">
                            <i class="ki-filled ki-loading animate-spin"></i> Receiving…
                        </div>

                        @error('uploads.*')<span class="text-xs text-destructive">{{ $message }}</span>@enderror

                        @if ($mediaProblems !== [])
                            <ul class="flex flex-col gap-1 rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2">
                                @foreach ($mediaProblems as $problem)
                                    <li class="text-xs text-destructive flex items-start gap-2">
                                        <i class="ki-filled ki-information-2 text-sm shrink-0 mt-px"></i>
                                        <span>{{ $problem }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                    </div>

                    <div class="border-t border-border pt-4 flex flex-wrap items-end justify-between gap-3">
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="flex flex-col gap-1">
                                <label class="kt-form-label text-xs" for="publish-when">When</label>
                                <select id="publish-when" class="kt-select max-w-[180px]" wire:model.live="schedule">
                                    <option value="now">Publish now</option>
                                    <option value="later">Schedule…</option>
                                    <option value="draft">Save as draft</option>
                                </select>
                            </div>
                            @if ($schedule === 'later')
                                <div class="flex flex-col gap-1">
                                    <label class="kt-form-label text-xs" for="publish-at">Date and time</label>
                                    <input id="publish-at" type="datetime-local" class="kt-input max-w-[220px]" wire:model="scheduledAt">
                                </div>
                            @endif
                        </div>

                        <button wire:click="submit" wire:loading.attr="disabled"
                                class="kt-btn kt-btn-primary gap-2"
                                @disabled(empty($targets) || trim($body) === '' || $mediaProblems !== [])>
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

            {{-- Per-account copy --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Per-network copy</h3>
                    <span class="text-xs text-muted-foreground">Fork one network without touching the rest</span>
                </div>
                <div class="kt-card-content p-0 divide-y divide-border">
                    @forelse ($selected as $account)
                        @php
                            $text = $this->textFor($account->id);
                            $forked = $this->isOverridden($account->id);
                            // Telegram's allowance drops to a caption's 1,024
                            // once a picture is attached, so the counter has to
                            // move with the images rather than sit on 4,096.
                            $limit = $account->characterLimitWith($hasMedia);
                            $over = mb_strlen($text) - $limit;
                        @endphp
                        <div class="p-4 flex flex-col gap-3" wire:key="override-{{ $account->id }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <i class="ki-filled {{ $account->icon() }} text-base text-muted-foreground"></i>
                                    <span class="text-sm font-medium text-mono">{{ $account->label() }}</span>
                                    <span class="text-xs {{ $over > 0 ? 'text-destructive' : 'text-muted-foreground' }}">
                                        {{ mb_strlen($text) }} / {{ number_format($limit) }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    @if ($forked && $over > 0)
                                        <button wire:click="trimToLimit({{ $account->id }})" class="kt-btn kt-btn-sm kt-btn-outline">
                                            Trim to fit
                                        </button>
                                    @endif
                                    <button wire:click="toggleOverride({{ $account->id }})" class="kt-btn kt-btn-sm kt-btn-ghost">
                                        {{ $forked ? 'Use shared text' : 'Customise' }}
                                    </button>
                                </div>
                            </div>

                            @if ($forked)
                                <textarea class="kt-textarea min-h-[110px] text-sm {{ $over > 0 ? 'border-destructive' : '' }}"
                                          wire:model.live.debounce.300ms="overrides.{{ $account->id }}"
                                          aria-label="{{ $account->label() }} copy"></textarea>
                            @else
                                <p class="text-xs text-muted-foreground">Follows the shared text above.</p>
                            @endif
                        </div>
                    @empty
                        <div class="flex flex-col items-center py-12 text-center">
                            <i class="ki-filled ki-element-11 text-3xl text-muted-foreground mb-3"></i>
                            <p class="text-sm text-secondary-foreground">Pick at least one account to post to.</p>
                        </div>
                    @endforelse
                </div>
            </div>

        </div>

        {{-- Targets and previews --}}
        <div class="col-span-12 lg:col-span-5 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Post to</h3>
                    <a href="{{ route('social.account-connect') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost">Connect</a>
                </div>
                <div class="kt-card-content p-3 flex flex-col gap-1">
                    @forelse ($accounts as $account)
                        @php
                            $active = in_array($account->id, array_map('intval', $targets), true);
                            $length = mb_strlen($this->textFor($account->id));
                            $accountLimit = $account->characterLimitWith($hasMedia);
                            $over = $length > $accountLimit;
                            $blocked = $unavailable[$account->id] ?? null;
                        @endphp
                        <button wire:click="toggleTarget({{ $account->id }})" wire:key="target-{{ $account->id }}"
                                class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-start transition-colors
                                       {{ $active ? 'bg-primary/10' : 'hover:bg-accent/50' }}">
                            <i class="ki-filled {{ $account->icon() }} text-lg shrink-0 {{ $active ? 'text-primary' : 'text-muted-foreground' }}"></i>
                            <span class="min-w-0 grow">
                                <span class="block text-sm font-medium text-mono">{{ $account->label() }}</span>
                                <span class="block text-xs text-muted-foreground truncate">{{ $account->handle }}</span>
                                {{-- A character count is beside the point for a destination nothing
                                     can send to, and "credentials not configured" would be a lie
                                     about one whose credentials are all present. --}}
                                <span class="block text-xs {{ $blocked ? 'text-warning' : ($over ? 'text-destructive' : 'text-muted-foreground') }}">
                                    @if ($blocked)
                                        Unavailable on this install
                                    @elseif ($account->isConnected())
                                        {{ $length }} / {{ number_format($accountLimit) }}
                                    @else
                                        Credentials not configured
                                    @endif
                                </span>
                                @if ($blocked)
                                    <span class="block text-[11px] text-muted-foreground mt-0.5">{{ $blocked }}</span>
                                @endif
                            </span>
                            @if ($active)
                                <i class="ki-filled ki-check-circle text-primary text-base shrink-0"></i>
                            @endif
                        </button>
                    @empty
                        <div class="flex flex-col items-center py-10 text-center">
                            <i class="ki-filled ki-abstract-26 text-3xl text-muted-foreground mb-3"></i>
                            <p class="text-sm text-secondary-foreground">No accounts yet.</p>
                            <a href="{{ route('social.account-connect') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-3">Connect one</a>
                        </div>
                    @endforelse
                </div>
            </div>

            @foreach ($selected as $account)
                <livewire:social::post-preview
                    :key="'preview-'.$account->id"
                    :network-key="$account->network"
                    :handle="$account->handle"
                    :body="$this->textFor($account->id)"
                    :overridden="$this->isOverridden($account->id)" />
            @endforeach

            @if ($selected->isEmpty())
                <div class="kt-card">
                    <div class="kt-card-content flex flex-col items-center py-12 text-center">
                        <i class="ki-filled ki-eye text-3xl text-muted-foreground mb-3"></i>
                        <p class="text-sm text-secondary-foreground">Previews appear once you select an account.</p>
                    </div>
                </div>
            @endif

        </div>

    </div>
</div>
