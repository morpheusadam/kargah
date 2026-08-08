<?php

namespace Modules\Social\Services\Publishers;

use Modules\Core\Support\ImageTranscoder;
use Modules\Data\Contracts\AttachmentService;

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

    /** Set only by `convertedToJpeg()` — asks `publicUrl()` to serve a re-encode rather than the stored bytes. */
    private ?string $urlMime = null;

    public function __construct(
        /** The attachment id, so a failure can name a row rather than a filename. */
        public readonly int $id,
        public readonly string $name,
        public readonly string $mime,
        public readonly int $sizeBytes,
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

        // The id is the whole handle now that the bytes come back through the
        // contract. An attachment array without one is malformed, and a
        // MediaItem built from it could never fetch anything.
        $id = (int) ($attachment['id'] ?? 0);

        if ($id <= 0) {
            return null;
        }

        return new self(
            id: $id,
            name: is_string($attachment['name'] ?? null) ? $attachment['name'] : 'image',
            mime: $mime,
            sizeBytes: (int) ($attachment['size_bytes'] ?? 0),
        );
    }

    /**
     * The bytes, read once.
     *
     * Through `AttachmentService::contents()`, not through `Storage::disk()`:
     * where the bytes live is Data's business, and reaching for the `disk` and
     * `path` keys directly put another module's storage layout in this one's
     * hands. A null answer means the row outlived its file.
     *
     * @throws PublishFailed when the row survived but the file behind it did not
     */
    public function contents(): string
    {
        if ($this->bytes !== null) {
            return $this->bytes;
        }

        $bytes = app(AttachmentService::class)->contents($this->id);

        if ($bytes === null) {
            throw new PublishFailed(
                'The image “'.$this->name.'” is recorded against this post but its file is missing from storage, so the post was not sent.',
            );
        }

        return $this->bytes = $bytes;
    }

    /**
     * A link Meta (or Slack) can fetch the picture from — this item's mime,
     * unless `convertedToJpeg()` built it, in which case the link asks Data to
     * serve the re-encode rather than the stored file.
     *
     * `AttachmentService::publicUrl()`'s bytes and this item's `contents()` are
     * two independent reads of the same conversion decision: a network that
     * takes bytes directly (Facebook Page) gets the JPEG this instance already
     * holds in memory, and a network that only takes a URL (Instagram,
     * Threads, Slack) gets Data's file-share route re-encoding the original on
     * its way out. Neither path downloads the other's copy.
     */
    public function publicUrl(int $minutes = 30): ?string
    {
        return app(AttachmentService::class)->publicUrl($this->id, $minutes, $this->urlMime);
    }

    /**
     * The same picture, re-encoded as the JPEG a network that refused its
     * original mime will actually take.
     *
     * Null rather than a throw for the same reason `ImageTranscoder::toJpeg()`
     * is: a source GD cannot decode is a reason to fall back to the ordinary
     * mime rejection, not a reason to fail the whole publish differently than
     * an unconvertible file would have.
     */
    public function convertedToJpeg(): ?self
    {
        $jpeg = ImageTranscoder::toJpeg($this->contents(), $this->mime);

        if ($jpeg === null) {
            return null;
        }

        $clone = new self(id: $this->id, name: $this->name, mime: 'image/jpeg', sizeBytes: strlen($jpeg));
        $clone->bytes = $jpeg;
        $clone->urlMime = 'image/jpeg';

        return $clone;
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
