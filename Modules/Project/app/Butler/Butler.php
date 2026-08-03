<?php

namespace Modules\Project\Butler;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Project\Models\Board;
use Modules\Project\Models\ButlerRule;
use Modules\Project\Models\Card;

/**
 * The engine. Registered as a singleton, which is the whole point: the loop
 * guard is instance state, and a guard that a caller can get a fresh copy of
 * is not a guard.
 *
 * ## The loop
 *
 * "When a label is added, add a label" is a rule somebody writes on their first
 * day, and without a guard it recurses until PHP runs out of stack. Two
 * separate mechanisms stop it, because they stop different things:
 *
 * 1. **A rule cannot re-enter itself.** `$running` holds the ids of the rules
 *    currently part-way through their chain. A rule whose own action re-fires
 *    its own trigger finds itself in that set and returns immediately. This is
 *    the direct case, and it is exact — no counting, no heuristics.
 *
 * 2. **A depth cap of `MAX_DEPTH`.** Rule A moves a card, which fires rule B,
 *    which adds a label, which fires rule C, which moves the card back into A's
 *    list. No rule re-enters itself, so (1) never sees it, and the cycle is
 *    real. Chaining is a genuine feature — Trello allows it — so the answer is
 *    a ceiling rather than a ban: three levels of cascade, then Butler stops
 *    and writes one line to the log naming the trigger that hit the ceiling.
 *
 * Both counters are instance state on a singleton, so they reset when the
 * request ends. That is exactly the scope wanted: a cascade is a property of
 * one request, and two unrelated requests must not spend each other's budget.
 *
 * ## Where the actions come from
 *
 * `Actions::run()` returns the triggers it caused rather than firing them, so
 * every entry into the recursion goes through `fire()` below and past both
 * guards. There is no second door.
 */
class Butler
{
    /**
     * How many levels of rule-fires-rule to allow. Three is Trello's own
     * practical answer and is enough for the chains people actually write
     * ("into Doing → assign me → comment"); the fourth level is where a
     * deliberate cascade and an accidental cycle stop being distinguishable.
     */
    public const MAX_DEPTH = 3;

    /** Rule ids currently part-way through their own chain. @var array<int, true> */
    private array $running = [];

    private int $depth = 0;

    /** Set while `mute()` is running: nothing fires at all. */
    private bool $muted = false;

    public function __construct(private readonly Actions $actions) {}

    /**
     * Something happened. Run every enabled rule on that board listening for it.
     *
     * `$context` narrows the trigger — `list_id`, `label_id`, `user_id`,
     * `text`, `board_id` — and is matched against each rule's own
     * `trigger_config`. A rule with an empty config matches any context, which
     * is what "any list" means in the builder.
     *
     * Returns how many rules actually ran, which is what the scratch scripts
     * and the button UI both want to know.
     */
    public function fire(string $trigger, Card $card, array $context = []): int
    {
        if ($this->muted || ! Triggers::isValid($trigger)) {
            return 0;
        }

        if ($this->depth >= self::MAX_DEPTH) {
            // One line, not one per rule: a cascade that has run away produces
            // a great many of these otherwise, and the first is the useful one.
            Log::warning('Butler stopped a cascade at depth '.self::MAX_DEPTH, [
                'trigger' => $trigger,
                'card_id' => $card->id,
            ]);

            return 0;
        }

        $boardId = $this->boardIdFor($card, $context);

        if ($boardId === null) {
            return 0;
        }

        $rules = ButlerRule::query()
            ->where('board_id', $boardId)
            ->rules()
            ->enabled()
            ->where('trigger', $trigger)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $ran = 0;
        $this->depth++;

        try {
            foreach ($rules as $rule) {
                if (! $this->triggerMatches($rule, $context)) {
                    continue;
                }

                if ($this->run($rule, $card)) {
                    $ran++;
                }
            }
        } finally {
            $this->depth--;
        }

        return $ran;
    }

    /**
     * Run one command's chain against one card, conditions first.
     *
     * Returns whether it ran. `false` means either the conditions did not
     * qualify the card or the rule was already running — the caller cannot
     * tell those apart, and does not need to.
     */
    public function run(ButlerRule $rule, Card $card): bool
    {
        $id = (int) $rule->getKey();

        // Guard 1: direct self re-entry. See the class docblock.
        if (isset($this->running[$id]) || ! $rule->is_enabled) {
            return false;
        }

        if (! Conditions::allPass($rule->conditionSet(), $card)) {
            return false;
        }

        $this->running[$id] = true;

        try {
            $caused = [];

            foreach ($rule->actionChain() as $action) {
                foreach ($this->actions->run($action, $card->refresh()) as $effect) {
                    $caused[] = $effect;
                }
            }

            $rule->noteRun();

            // The pivot triggers — label and member — are fired after the whole
            // chain rather than between its actions. A chain is one intention:
            // "move it, assign me, comment". Firing halfway through would let a
            // rule watching the label see a card that has not been assigned
            // yet, a state the author never wrote down.
            //
            // The model-event triggers do not get this treatment and cannot:
            // `Hooks` listens on `save()`, so they fire from inside the action
            // that caused them. Both paths still enter through `fire()` below,
            // which is what matters for the guard.
            foreach ($caused as [$trigger, $context]) {
                $this->fire($trigger, $card->refresh(), $context);
            }
        } finally {
            unset($this->running[$id]);
        }

        return true;
    }

    /**
     * A card button: this rule, this card, right now. The press is the trigger,
     * so there is no trigger to match — but the conditions still apply, which
     * is how a button says "only if it is not already complete".
     */
    public function press(ButlerRule $rule, Card $card): bool
    {
        return $rule->kind === Kind::CARD_BUTTON && $this->run($rule, $card);
    }

    /**
     * A board button: this rule over every active card on its board that its
     * conditions qualify. Returns how many cards it touched.
     *
     * Archived cards are excluded unless the rule explicitly asks for them with
     * an `is_archived` condition — otherwise "add the label Reviewed to
     * everything" would quietly reach into the archive, which nobody means.
     */
    public function pressBoard(ButlerRule $rule, ?Board $board = null): int
    {
        if ($rule->kind !== Kind::BOARD_BUTTON) {
            return 0;
        }

        $board ??= $rule->board;

        if ($board === null) {
            return 0;
        }

        $wantsArchived = collect($rule->conditionSet())
            ->contains(fn (array $c): bool => ($c['condition'] ?? null) === 'is_archived');

        /** @var Collection<int, Card> $cards */
        $cards = $board->cards()
            ->when(! $wantsArchived, fn ($q) => $q->active())
            ->orderBy('cards.id')
            ->get();

        $touched = 0;

        foreach ($cards as $card) {
            // `run()` returns false for a card the conditions reject, which is
            // exactly the filter this button needs — no second implementation.
            if ($this->run($rule, $card)) {
                $touched++;
            }
        }

        return $touched;
    }

    /**
     * Run something with Butler switched off — a seeder, an import, a bulk
     * restore. Nothing fires, and the flag is restored even if the callback
     * throws.
     */
    public function mute(callable $callback): mixed
    {
        $was = $this->muted;
        $this->muted = true;

        try {
            return $callback();
        } finally {
            $this->muted = $was;
        }
    }

    public function isMuted(): bool
    {
        return $this->muted;
    }

    /** Current cascade depth, for the scratch scripts that prove the guard. */
    public function depth(): int
    {
        return $this->depth;
    }

    /**
     * Does the rule's own qualifier accept this context?
     *
     * An empty or absent value means "any", so a rule saved with no list
     * chosen fires for every list. Each key that *is* set must match exactly —
     * except `text`, which is a case-insensitive substring, because that is
     * what "a comment containing 'blocked'" means.
     */
    private function triggerMatches(ButlerRule $rule, array $context): bool
    {
        foreach ($rule->triggerConfig() as $key => $wanted) {
            if ($wanted === null || $wanted === '' || $wanted === []) {
                continue;
            }

            if ($key === 'text') {
                if (! str_contains(mb_strtolower((string) ($context['text'] ?? '')), mb_strtolower((string) $wanted))) {
                    return false;
                }

                continue;
            }

            if (! array_key_exists($key, $context) || (int) $context[$key] !== (int) $wanted) {
                return false;
            }
        }

        return true;
    }

    /**
     * Which board's rules should see this. The context wins when it carries a
     * board — a move knows both lists and therefore the board, without a
     * second query — and the card's origin list answers otherwise.
     */
    private function boardIdFor(Card $card, array $context): ?int
    {
        if (isset($context['board_id'])) {
            return (int) $context['board_id'];
        }

        $boardId = $card->list?->board_id;

        return $boardId === null ? null : (int) $boardId;
    }
}
