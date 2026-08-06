<?php

namespace Modules\Social\Services\Publishers;

use Modules\Data\Contracts\AttachmentService;

/**
 * One video on its way to a network.
 *
 * **Deliberately not a `MediaItem`, and the difference is the whole reason this
 * class exists.** `MediaItem` says so itself: *"an image is a string that fits in
 * memory and in one request's execution budget. A video does not."* Everything
 * about that class follows from holding the bytes — `contents(): string`, the
 * memo that lets one picture go to four networks off one read. Reusing it for
 * video would mean a hundred-megabyte string in a queue worker, and adding a
 * streaming mode to it would give every image driver a second way to read a file
 * that none of them wants.
 *
 * So the two are siblings rather than one class with a flag, which is the same
 * argument `MetaGraph`'s docblock makes about networks that only look alike.
 *
 * 🔴 **There is no memo here, and there must not be.** A stream is consumed by
 * reading it: handing the same resource to a second upload would send zero
 * bytes, and the failure would look like an empty video rather than a spent
 * handle. Each call to `stream()` opens a fresh one, and the caller closes it.
 *
 * **Only one video per post reaches a driver.** That is not a limitation Kargah
 * invented — a YouTube upload is one video, and there is no network here that
 * takes several. `PostMedia::videoForPost()` picks the first and says so.
 */
final class VideoItem
{
    /**
     * What counts as a video, and why it is a prefix test rather than a list.
     *
     * The catalogue decides which container each network actually accepts —
     * `Networks::media()['mimes']` for YouTube is a short list, and
     * `HttpPublisher::acceptableMedia()` enforces it. This is the coarser
     * question asked one step earlier: is this attachment a video at all, or is
     * it the brief and the signed PDF that `MediaItem::fromAttachment()` drops
     * for the same reason.
     */
    public static function isVideo(?string $mime): bool
    {
        return is_string($mime) && str_starts_with($mime, 'video/');
    }

    public function __construct(
        /** The attachment id, so a failure can name a row rather than a filename. */
        public readonly int $id,
        public readonly string $name,
        public readonly string $mime,
        public readonly int $sizeBytes,
    ) {}

    /**
     * Build one from what `AttachmentService` hands back, or null if it is not a
     * video.
     *
     * Mirrors `MediaItem::fromAttachment()` exactly, including returning null
     * rather than throwing: a post carrying a PDF alongside its video is not a
     * post that should fail.
     *
     * @param  array<string, mixed>  $attachment
     */
    public static function fromAttachment(array $attachment): ?self
    {
        $mime = $attachment['mime'] ?? null;

        if (! self::isVideo(is_string($mime) ? $mime : null)) {
            return null;
        }

        $id = (int) ($attachment['id'] ?? 0);

        if ($id <= 0) {
            return null;
        }

        return new self(
            id: $id,
            name: is_string($attachment['name'] ?? null) ? $attachment['name'] : 'video',
            mime: (string) $mime,
            sizeBytes: (int) ($attachment['size_bytes'] ?? 0),
        );
    }

    /**
     * A fresh readable handle on the bytes.
     *
     * Through `AttachmentService::readStream()` for the same reason `MediaItem`
     * goes through `contents()`: where the file lives is Data's business, and a
     * publisher that reached for `Storage::disk()` would be holding another
     * module's storage layout.
     *
     * @return resource
     *
     * @throws PublishFailed when the row survived but the file behind it did not
     */
    public function stream()
    {
        $stream = app(AttachmentService::class)->readStream($this->id);

        if (! is_resource($stream)) {
            throw new PublishFailed(
                'The video “'.$this->name.'” is recorded against this post but its file is missing from storage, so the post was not sent.',
            );
        }

        return $stream;
    }

    /** A filename safe to send, never the one a browser supplied. */
    public function filename(): string
    {
        $extension = match ($this->mime) {
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
            'video/webm' => 'webm',
            'video/mpeg' => 'mpeg',
            default => 'bin',
        };

        return 'video-'.$this->id.'.'.$extension;
    }
}
