<?php

namespace Modules\Social\Services;

use Modules\Data\Contracts\AttachmentService;
use Modules\Social\Models\Post;
use Modules\Social\Services\Publishers\MediaItem;
use Modules\Social\Services\Publishers\VideoItem;

/**
 * What is actually attached to a post, at the moment it is sent.
 *
 * **The attachment rows are the truth, and there is no second copy of it.**
 * `posts.media` is a JSON column that nothing writes and nothing may read —
 * see the note on `Modules\Social\Models\Post`. Everything that needs to know
 * what pictures a post carries — the publisher, the composer, the post page —
 * comes through here, so there is one answer rather than a column and a table
 * that agree until the day they do not.
 *
 * Order matters and is the order the files were attached in. A carousel is a
 * sequence, not a set, and `AttachmentService::forTarget()` returns newest
 * first, so it is reversed here rather than left to each caller to remember.
 *
 * Non-images are dropped, not refused. A post may carry a brief or a signed PDF
 * for the operator's own reference; that is not a reason to fail the publish,
 * and no network here would know what to do with it.
 *
 * **Video is the one exception, and it has its own method.** `forPost()` still
 * answers images only, because thirteen of the fourteen drivers take images and
 * nothing else. YouTube takes a video and nothing else, so it asks
 * `videoForPost()` instead — see `Publishers\PublishesVideo` for why these are
 * two operations rather than one list with two kinds in it.
 */
class PostMedia
{
    public function __construct(private readonly AttachmentService $attachments) {}

    /**
     * Every image attached to a post, oldest first.
     *
     * @return list<MediaItem>
     */
    public function forPost(Post $post): array
    {
        if (! $post->exists) {
            return [];
        }

        $items = [];

        foreach ($this->attachments->forTarget($post)->reverse() as $attachment) {
            $item = MediaItem::fromAttachment($attachment);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * The one video attached to a post, or null.
     *
     * **The first one, in attach order, and only one.** A YouTube upload is one
     * video and there is no network here that takes several, so "the first" is a
     * complete answer rather than a simplification waiting to be fixed. Picking
     * the first rather than the last matches `forPost()`'s ordering, which is
     * the order the person attached them in — the same reason a carousel is a
     * sequence.
     *
     * Kept beside `forPost()` rather than inside it because the two answer
     * different questions for different callers, and a single method returning
     * both would give thirteen image drivers a video they must remember to
     * ignore. `PostPublisher` asks whichever one the driver can use.
     */
    public function videoForPost(Post $post): ?VideoItem
    {
        if (! $post->exists) {
            return null;
        }

        foreach ($this->attachments->forTarget($post)->reverse() as $attachment) {
            $item = VideoItem::fromAttachment($attachment);

            if ($item !== null) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Everything attached, images and not, for a page that lists files.
     *
     * Separate from `forPost()` on purpose: the publisher must never see a
     * non-image, and a page that shows a paperclip must never hide one.
     *
     * @return list<array<string, mixed>>
     */
    public function attachmentsFor(Post $post): array
    {
        return $post->exists
            ? $this->attachments->forTarget($post)->reverse()->values()->all()
            : [];
    }
}
