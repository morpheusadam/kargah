<?php

namespace Modules\Project\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Core\Contracts\Notifier;
use Modules\Project\Models\Card;
use Modules\Project\Services\Watching;

/**
 * Tell people a card is due soon, or overdue.
 *
 * Runs every few minutes from the scheduler, so it has to be safe to run
 * every few minutes — that is what `dedupe_key` is for. Each card gets at
 * most one `card.due_soon` notification and one `card.overdue` notification,
 * ever, per recipient: the key is `card:{id}:due_soon` / `card:{id}:overdue`,
 * scoped by user through `Notifier::notifyMany()`'s own `(user_id, dedupe_key)`
 * index. A card that was due-soon and has since gone overdue gets the second
 * one anyway, because the two keys are different — a genuinely new event, not
 * a repeat of the first.
 *
 * `DUE_SOON_DAYS` is named and documented rather than inlined into the query,
 * so changing what "soon" means is a one-line edit here rather than a search
 * for a bare `1`. It is deliberately its own constant rather than a call
 * through `Card::dueState()` — that method's "soon" window feeds a coloured
 * badge on the card front, and letting the sweep depend on it would mean a
 * change made for how a badge looks silently changing when people get
 * notified. `MAX_PER_RUN` is the "bounded amount of outstanding work" every
 * scheduled command in Kargah finds, per `01-architecture.md`.
 *
 * Recipients are the card's members — the people it is assigned to — union
 * the card's watchers, resolved through the same `Watching::recipientsForCard()`
 * every other producer uses, so a person watching the board a due card lives
 * on hears about it exactly as someone watching the card itself would. A
 * completed card is excluded by the query, not filtered afterwards:
 * `cards.completed_at` is read directly rather than assumed unset.
 */
class NotifyDueCards extends Command
{
    protected $signature = 'project:notify-due-cards';

    protected $description = 'Notify card members and watchers about cards due soon or overdue';

    public const DUE_SOON_DAYS = 1;

    public const MAX_PER_RUN = 500;

    public function handle(Notifier $notifier, Watching $watching): int
    {
        $today = Carbon::today();
        $dueSoonBy = $today->copy()->addDays(self::DUE_SOON_DAYS);

        $cards = Card::query()
            ->active()
            ->whereNull('completed_at')
            ->whereNotNull('due_on')
            ->where('due_on', '<=', $dueSoonBy)
            ->with(['members:id', 'list.board'])
            ->orderBy('due_on')
            ->limit(self::MAX_PER_RUN)
            ->get();

        $dueSoonSent = 0;
        $overdueSent = 0;

        foreach ($cards as $card) {
            $recipients = collect($watching->recipientsForCard($card))
                ->merge($card->members->pluck('id'))
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($recipients === []) {
                continue;
            }

            if ($card->due_on->lt($today)) {
                $overdueSent += $notifier->notifyMany($recipients, 'card.overdue', '"'.$card->title.'" is overdue', [
                    'subject' => $card,
                    'url' => $watching->cardUrl($card),
                    'dedupe_key' => 'card:'.$card->id.':overdue',
                ]);

                continue;
            }

            $when = $card->due_on->isToday() ? 'today' : 'tomorrow';

            $dueSoonSent += $notifier->notifyMany($recipients, 'card.due_soon', '"'.$card->title.'" is due '.$when, [
                'subject' => $card,
                'url' => $watching->cardUrl($card),
                'dedupe_key' => 'card:'.$card->id.':due_soon',
            ]);
        }

        $this->components->info(
            'Checked '.$cards->count().' '.str('card')->plural($cards->count())
                .': sent '.$dueSoonSent.' due-soon and '.$overdueSent.' overdue '.str('notification')->plural($dueSoonSent + $overdueSent).'.',
        );

        return self::SUCCESS;
    }
}
