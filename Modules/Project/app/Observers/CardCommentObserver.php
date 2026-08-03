<?php

namespace Modules\Project\Observers;

use Illuminate\Support\Str;
use Modules\Project\Models\CardComment;
use Modules\Project\Services\Watching;

/**
 * Card commented → notify the watchers.
 *
 * The first producer, and the one every other one in this module copies the
 * shape of: an observer, not a call from the Blade component, because the
 * same comment can be created from the drawer, from a seeder, from the API
 * and later from the assistant, and a producer in the view catches only one
 * of those.
 *
 * The actor is `$comment->created_by`, read off the row rather than
 * `auth()->id()` — a comment always carries its author, and reading the
 * column keeps this correct from a console context too, where there is no
 * authenticated user to ask.
 */
class CardCommentObserver
{
    public function __construct(private readonly Watching $watching) {}

    public function created(CardComment $comment): void
    {
        $card = $comment->card;

        if ($card === null) {
            return;
        }

        $by = $comment->author?->name ?? 'Someone';

        $this->watching->notifyCardWatchers(
            $card,
            'card.commented',
            $by.' commented on "'.$card->title.'"',
            Str::limit($comment->body, 140),
            $comment->created_by,
        );
    }
}
