<?php

namespace Modules\Project\Observers;

use Illuminate\Support\Str;
use Modules\Core\Contracts\Notifier;
use Modules\Project\Models\CardComment;
use Modules\Project\Services\Watching;
use Modules\Project\Support\Mentions;

/**
 * Card commented → notify the watchers, and notify anybody the comment names.
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
 *
 * **A mention always notifies, watching or not.** That is 06's rule, in the
 * same sentence as "being added to a card", and it is why the mention pass
 * below does not go through `recipientsForCard()` at all — being named is the
 * point of naming somebody, and making it depend on whether they had thought
 * to watch the card first would make `@` useless for the one job it has.
 * Mentioning yourself notifies nobody.
 *
 * A watcher who is also mentioned gets both rows, deliberately: they say
 * different things, and collapsing them would mean either dropping "you were
 * mentioned" or suppressing the thread notification for everybody else.
 */
class CardCommentObserver
{
    public function __construct(
        private readonly Watching $watching,
        private readonly Notifier $notifier,
    ) {}

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

        $mentioned = Mentions::recipients($comment->body, $comment->created_by);

        foreach ($mentioned as $userId) {
            // One key per person per comment. A comment is created once, so
            // this is belt and braces rather than a fix for a known replay —
            // but every producer in this project is safe to run twice, and a
            // second "you were mentioned" for the same comment is exactly the
            // kind of duplicate `dedupe_key` exists to refuse.
            $this->notifier->notify(
                $userId,
                'card.mentioned',
                $by.' mentioned you on "'.$card->title.'"',
                [
                    'subject' => $card,
                    'body' => Str::limit($comment->body, 140),
                    'url' => $this->watching->cardUrl($card),
                    'actor_id' => $comment->created_by,
                    'dedupe_key' => 'card_comment:'.$comment->getKey().':mention',
                ],
            );
        }
    }
}
