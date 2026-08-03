<?php

namespace Modules\Social\Services\Publishers;

use Illuminate\Support\Facades\Storage;

/**
 * One picture on its way to a network.
 *
 * **Why this is a class and not the attachment array it came from.** Every
 * driver needs three things a `Modules\Data\Contracts\AttachmentService` array
 * gives (a name, a MIME type, a length) and one it does not: the bytes. Passing
 * the raw array around would mean each of the five drivers deciding for itself
 * how to turn a disk and a path into a string, five times, with five chances to
 * read a file twice or read it when the network was never going to be asked.
 *
 * **The bytes are read late and read once.** `contents()` is what touches the
 * disk, so a target that fails its credential check costs nothing, and
 * LinkedIn's three-step upload — register, PUT, embed — reads the file once
 * rather than once per step. The memo is per instance and per post: a resolver
 * builds these once for a post and hands the same objects to every target, so
 * an image going to four networks is read from disk once, not four times.
 *
 * **Images only.** Nothing here streams or chunks, because nothing needs to: an
 * image is a string that fits in memory and in one request's execution budget.
 * A video does not, and a resumable upload is a different object with a
 * different lifetime — see `Networks`' media docblock and
 * `project-guaid/spec/08-postiz-parity.md`.
 */
final class MediaItem
{
    private ?string $bytes = null;

    public function __construct(
        /** The attachment id, so a failure can name a row rather than a filename. */
        public readonly int $id,
        public readonly string $name,
        public readonly string $mime,
        public readonly int $sizeBytes,
        private readonly string $disk,
        private readonly string $path,
    ) {}

    /**
     * Build one from what `AttachmentService` hands back.
     *
     * Returns null rather than throwing for anything that is not a picture:
     * a post can perfectly well carry a signed PDF as an attachment for the
     * operator's own reference, and that is not a reason to refuse to publish
     * the text. The filter belongs here because it is the same answer for every
     * network.
     *
     * @param  array<string, mixed>  $attachment
     */
    public static function fromAttachment(array $attachment): ?self
    {
        $mime = $attachment['mime'] ?? null;

        if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
            return null;
        }

        $disk = $attachment['disk'] ?? null;
        $path = $attachment['path'] ?? null;

        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
            return null;
        }

        return new self(
            id: (int) ($attachment['id'] ?? 0),
            name: is_string($attachment['name'] ?? null) ? $attachment['name'] : 'image',
            mime: $mime,
            sizeBytes: (int) ($attachment['size_bytes'] ?? 0),
            disk: $disk,
            path: $path,
        );
    }

    /**
     * The bytes, read once.
     *
     * Read through `Storage` with the disk and path the attachment array
     * carries, which is the only reason those two keys are in that array's
     * documented shape at all. It is a read, not a write — Data remains the one
     * writer to disk — but a `contents(int $id): ?string` on
     * `Modules\Data\Contracts\AttachmentService` would be the better home for
     * it, and is the one change this pipeline wants from a module Social does
     * not own.
     *
     * @throws PublishFailed when the row survived but the file behind it did not
     */
    public function contents(): string
    {
        if ($this->bytes !== null) {
            return $this->bytes;
        }

        $disk = Storage::disk($this->disk);

        if (! $disk->exists($this->path)) {
            throw new PublishFailed(
                'The image “'.$this->name.'” is recorded against this post but its file is missing from storage, so the post was not sent.',
            );
        }

        return $this->bytes = (string) $disk->get($this->path);
    }

    /** A filename safe to put in a multipart part, never the one a browser sent. */
    public function filename(): string
    {
        $extension = match ($this->mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'bin',
        };

        return 'image-'.$this->id.'.'.$extension;
    }
}
