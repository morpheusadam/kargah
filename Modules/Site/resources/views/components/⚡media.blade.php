<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Site\Services\SiteMedia;
use Modules\Site\Services\SiteRequestFailed;
use Modules\Site\Services\WordPressSite;

/**
 * The website's media library.
 *
 * ## Alt text is the point of this page, not the grid
 *
 * A media browser that only browses is a worse version of one wp-admin already
 * has. What this adds is the one field that is simultaneously an accessibility
 * requirement, a search signal and the thing nobody ever fills in — editable
 * inline, one click from the picture, with a running count of how many on the
 * page still have none. Everything else on the screen is in service of that.
 *
 * ## Delete says "permanently" because it means it
 *
 * WordPress has no trash for attachments — `DELETE` without `force` is refused
 * outright — so unlike a post, a picture deleted here is gone. The button says
 * so rather than reading like the trash action on the content page and doing
 * something worse than it.
 *
 * ## The upload never touches Kargah's disk beyond Livewire's own temp file
 *
 * Livewire has to stage the bytes somewhere to get them off the browser; from
 * there they are streamed straight to the site and the temporary file is
 * dropped. Nothing is written into Kargah's own storage, because a picture
 * destined for somebody's website is not one of Kargah's files.
 */
new
#[Title('Media — Kargah')]
class extends Component
{
    use InteractsWithToasts;
    use WithFileUploads;

    #[Url]
    public string $search = '';

    #[Url]
    public string $mediaType = '';

    #[Url]
    public int $page = 1;

    /**
     * Eight megabytes, matching `Networks::WORDPRESS['media']['max_bytes']`.
     *
     * Not because WordPress cannot take more — `upload_max_filesize` decides
     * that and is usually higher — but because a limit Kargah enforces produces
     * a sentence under the field, and one the host enforces produces a 500 with
     * an HTML body several seconds after the upload appeared to be working.
     */
    #[Validate('nullable|file|max:8192')]
    public $upload = null;

    /** The attachment whose alt text is open for editing, if any. */
    public ?int $editing = null;

    public string $altText = '';

    /** The attachment whose delete confirmation is open, if any. */
    public ?int $confirming = null;

    /**
     * @var array{items: list<array<array-key, mixed>>, total: int, pages: int, error: ?string}|null
     */
    private ?array $memo = null;

    public function updatedSearch(): void
    {
        $this->reset('page');
        $this->memo = null;
    }

    public function updatedMediaType(): void
    {
        $this->reset('page');
        $this->memo = null;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
        $this->memo = null;
    }

    /**
     * Send the staged file to the site.
     *
     * The real filename and the real mime are read off the temporary file
     * rather than trusted from the browser — the same lesson commit 3daeb66
     * recorded for social uploads, where a browser that claimed
     * `application/octet-stream` for a JPEG had the network refuse it.
     */
    public function send(): void
    {
        $this->validate();

        $site = WordPressSite::connected();

        if ($site === null || ! $this->upload instanceof TemporaryUploadedFile) {
            $this->toastError('Nothing to upload', 'Choose a file first.');

            return;
        }

        $file = $this->upload;

        try {
            $media = (new SiteMedia($site))->upload(
                $file->getClientOriginalName(),
                (string) file_get_contents($file->getRealPath()),
                (string) ($file->getMimeType() ?: 'application/octet-stream'),
            );
        } catch (SiteRequestFailed $e) {
            $this->toastError('The site did not take it', $e->getMessage());

            return;
        }

        $this->reset('upload');
        $this->memo = null;

        $this->toastSuccess(
            'Uploaded to the library',
            is_string($media['source_url'] ?? null) ? $media['source_url'] : 'It is on the site.',
        );
    }

    public function edit(int $id, string $current): void
    {
        $this->editing = $id;
        $this->altText = $current;
    }

    public function saveAltText(): void
    {
        $site = WordPressSite::connected();

        if ($site === null || $this->editing === null) {
            return;
        }

        try {
            (new SiteMedia($site))->update($this->editing, ['alt_text' => $this->altText]);
        } catch (SiteRequestFailed $e) {
            $this->toastError('The site refused it', $e->getMessage());

            return;
        }

        $this->editing = null;
        $this->memo = null;

        $this->toastSuccess('Alternative text saved', 'Screen readers and search engines both read this field.');
    }

    public function delete(int $id): void
    {
        $site = WordPressSite::connected();

        if ($site === null) {
            return;
        }

        try {
            (new SiteMedia($site))->delete($id);
        } catch (SiteRequestFailed $e) {
            $this->toastError('It was not deleted', $e->getMessage());

            return;
        }

        $this->confirming = null;
        $this->memo = null;

        $this->toastSuccess(
            'Deleted from the site',
            'WordPress has no trash for attachments, so this one is gone. Anything embedding it now has a broken image.',
        );
    }

    public function with(): array
    {
        $result = $this->fetch();

        return [
            'site' => WordPressSite::connected(),
            'rows' => $result['items'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'error' => $result['error'],
            'missingAlt' => SiteMedia::missingAltText($result['items']),
        ];
    }

    /**
     * @return array{items: list<array<array-key, mixed>>, total: int, pages: int, error: ?string}
     */
    private function fetch(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $site = WordPressSite::connected();

        if ($site === null) {
            return $this->memo = ['items' => [], 'total' => 0, 'pages' => 1, 'error' => null];
        }

        try {
            $result = (new SiteMedia($site))->list([
                'search' => $this->search,
                'media_type' => $this->mediaType,
            ], $this->page);

            return $this->memo = $result + ['error' => null];
        } catch (SiteRequestFailed $e) {
            return $this->memo = ['items' => [], 'total' => 0, 'pages' => 1, 'error' => $e->getMessage()];
        }
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold text-mono">Media</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                The site's library, and the alternative text most of it is missing.
            </p>
        </div>
        <a href="{{ route('site.overview') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
            <i class="ki-filled ki-information-2"></i> Connection
        </a>
    </div>

    @if (! $site)

        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center py-16 text-center">
                <i class="ki-filled ki-picture text-4xl text-muted-foreground mb-3"></i>
                <h2 class="text-lg font-semibold text-mono">No website is connected</h2>
                <a href="{{ route('social.accounts') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-primary mt-4">
                    Connect a site
                </a>
            </div>
        </div>

    @else

        <div class="kt-card">
            <div class="kt-card-header flex-wrap gap-3">
                <h2 class="kt-card-title">Add a file</h2>
                @if ($missingAlt > 0)
                    <span class="kt-badge kt-badge-sm kt-badge-warning">
                        {{ $missingAlt }} {{ \Illuminate\Support\Str::plural('image', $missingAlt) }} on this page have no alt text
                    </span>
                @endif
            </div>
            <div class="kt-card-content flex flex-wrap items-end gap-3">
                <div class="min-w-[260px] grow">
                    <label class="kt-form-label" for="media-upload">File</label>
                    <input id="media-upload" wire:model="upload" type="file"
                           class="kt-input @error('upload') border-destructive @enderror">
                    @error('upload')
                        <span class="text-xs text-destructive mt-1">{{ $message }}</span>
                    @enderror
                </div>
                <button wire:click="send" wire:loading.attr="disabled" wire:target="send,upload"
                        class="kt-btn kt-btn-primary gap-2">
                    <span wire:loading.remove wire:target="send,upload" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-cloud-add"></i> Upload to the site
                    </span>
                    <span wire:loading wire:target="send,upload" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Sending…
                    </span>
                </button>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-header flex-wrap gap-3">
                <h2 class="kt-card-title">Library</h2>
                <div class="flex flex-wrap items-center gap-2">
                    <select wire:model.live="mediaType" class="kt-select kt-select-sm w-[140px]">
                        <option value="">Everything</option>
                        <option value="image">Images</option>
                        <option value="video">Video</option>
                        <option value="audio">Audio</option>
                        <option value="application">Documents</option>
                    </select>
                    <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search the library…"
                           class="kt-input kt-input-sm w-[220px]">
                    <span wire:loading wire:target="search,mediaType,goToPage" class="text-xs text-muted-foreground">
                        <i class="ki-filled ki-loading animate-spin"></i> Asking the site…
                    </span>
                </div>
            </div>

            @if ($error)
                <div class="kt-card-content">
                    <div class="flex items-start gap-3 py-6">
                        <i class="ki-filled ki-information-2 text-destructive text-xl mt-0.5"></i>
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-mono">The site did not return its library</div>
                            <p class="text-sm text-secondary-foreground mt-1">{{ $error }}</p>
                        </div>
                    </div>
                </div>
            @else
                <div class="kt-card-content">
                    @forelse ($rows as $row)
                        @php($id = (int) ($row['id'] ?? 0))
                        @php($thumb = \Modules\Site\Services\SiteMedia::thumbnail($row))
                        @php($alt = (string) ($row['alt_text'] ?? ''))
                        @php($isImage = ($row['media_type'] ?? '') === 'image')
                        @if ($loop->first)
                            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        @endif

                        <div wire:key="media-{{ $id }}" class="border border-border rounded-lg overflow-hidden flex flex-col">
                            <div class="aspect-video bg-muted flex items-center justify-center overflow-hidden">
                                @if ($isImage && $thumb)
                                    <img src="{{ $thumb }}" alt="{{ $alt }}" class="max-w-full max-h-full object-contain" loading="lazy">
                                @else
                                    <i class="ki-filled ki-document text-3xl text-muted-foreground"></i>
                                @endif
                            </div>

                            <div class="p-3 flex flex-col gap-2 grow">
                                <div class="text-xs font-medium text-mono truncate" title="{{ $row['slug'] ?? '' }}">
                                    {{ \Modules\Site\Services\SiteContent::text($row['title'] ?? '') ?: ($row['slug'] ?? 'Untitled') }}
                                </div>

                                @if ($isImage)
                                    @if ($editing === $id)
                                        <input wire:model="altText" type="text" class="kt-input kt-input-sm"
                                               placeholder="What is in this picture?">
                                        <div class="flex items-center gap-1">
                                            <button wire:click="saveAltText" wire:loading.attr="disabled"
                                                    class="kt-btn kt-btn-sm kt-btn-primary">Save</button>
                                            <button wire:click="$set('editing', null)"
                                                    class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                                        </div>
                                    @else
                                        <button wire:click="edit({{ $id }}, @js($alt))"
                                                class="text-start text-xs {{ $alt === '' ? 'text-warning' : 'text-secondary-foreground' }} hover:text-primary">
                                            {{ $alt !== '' ? $alt : 'No alternative text — add some' }}
                                        </button>
                                    @endif
                                @endif

                                <div class="mt-auto pt-1 flex items-center justify-between gap-1">
                                    @if (is_string($row['source_url'] ?? null))
                                        <a href="{{ $row['source_url'] }}" target="_blank" rel="noopener"
                                           class="text-xs text-muted-foreground hover:text-primary">Open</a>
                                    @else
                                        <span></span>
                                    @endif

                                    @if ($confirming === $id)
                                        <div class="flex items-center gap-1">
                                            <button wire:click="delete({{ $id }})" wire:loading.attr="disabled"
                                                    class="kt-btn kt-btn-sm kt-btn-destructive">Delete for good</button>
                                            <button wire:click="$set('confirming', null)"
                                                    class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                                        </div>
                                    @else
                                        <button wire:click="$set('confirming', {{ $id }})"
                                                class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost" aria-label="Delete permanently">
                                            <i class="ki-filled ki-trash"></i>
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>

                        @if ($loop->last)
                            </div>
                        @endif
                    @empty
                        <div class="flex flex-col items-center py-12 text-center">
                            <i class="ki-filled ki-picture text-3xl text-muted-foreground mb-2"></i>
                            <div class="text-sm font-medium text-mono">Nothing in the library</div>
                            <p class="text-sm text-secondary-foreground mt-1">
                                @if ($search !== '')
                                    Nothing matches “{{ $search }}”.
                                @else
                                    Upload a file above and it appears here.
                                @endif
                            </p>
                        </div>
                    @endforelse
                </div>

                @if ($pages > 1)
                    <div class="kt-card-footer flex items-center justify-between gap-3">
                        <span class="text-xs text-muted-foreground">
                            {{ $total }} {{ \Illuminate\Support\Str::plural('file', $total) }}, page {{ $page }} of {{ $pages }}
                        </span>
                        <div class="flex items-center gap-1">
                            <button wire:click="goToPage({{ $page - 1 }})" @disabled($page <= 1)
                                    class="kt-btn kt-btn-sm kt-btn-ghost">Previous</button>
                            <button wire:click="goToPage({{ $page + 1 }})" @disabled($page >= $pages)
                                    class="kt-btn kt-btn-sm kt-btn-ghost">Next</button>
                        </div>
                    </div>
                @endif
            @endif
        </div>

    @endif

</div>
