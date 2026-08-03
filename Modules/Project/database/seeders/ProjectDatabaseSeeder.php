<?php

namespace Modules\Project\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardComment;
use Modules\Project\Models\Checklist;
use Modules\Project\Models\ChecklistItem;
use Modules\Project\Models\Label;
use Modules\Project\Support\Position;

/**
 * The three boards the front end has been drawing from a fixture.
 *
 * Everything here reproduces `⚡boards.blade.php` and `⚡card-detail.blade.php`
 * row for row, so the page reads the database and looks no different. Two
 * things are deliberately not copied verbatim:
 *
 * - Due dates. The fixture carried a fixed label ('Aug 05') and a state
 *   ('soon'). A fixed date is 'overdue' by September and the board stops
 *   demonstrating anything, so the offsets below are counted from `now()` and
 *   keep the same spacing the fixture had around its own early-August today.
 * - Nothing is archived and nothing is completed, because the fixture had no
 *   card in either condition. `Q3 expense reconciliation` is ticked 8 of 8 but
 *   still sits in Review and still reads as overdue, which is the point of it.
 *
 * Idempotent. Every write is an `updateOrCreate` keyed on something a person
 * would recognise — a board's slug, a list's name on its board, a card's title
 * in its list — so running it twice leaves the same rows with the same ids.
 */
class ProjectDatabaseSeeder extends Seeder
{
    /**
     * The board palette, in the order the label row is drawn.
     *
     * Keys are the fixture's own label names; values are palette keys, never
     * class strings. Every board carries the full set, as the fixture did.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'Copywriting' => 'primary',
        'Outreach' => 'success',
        'Development' => 'info',
        'Bug' => 'destructive',
        'Finance' => 'warning',
        'Admin' => 'neutral',
    ];

    public function run(): void
    {
        $user = $this->developer();

        DB::transaction(function () use ($user): void {
            foreach ($this->boards() as $index => $board) {
                $this->seedBoard($board, $index, $user);
            }
        });
    }

    /**
     * Whoever this database belongs to.
     *
     * The seeder needs one real user to hang card members, comment authorship
     * and `created_by` off. An existing user is preferred over inventing a
     * second one on a database that already has somebody in it.
     */
    private function developer(): User
    {
        return User::query()->first() ?? User::query()->create([
            'name' => 'Nima Fazlipour',
            'email' => 'nima@kargah.test',
            'password' => 'password',
        ]);
    }

    private function seedBoard(array $data, int $index, User $user): void
    {
        $board = Board::query()->updateOrCreate(
            ['slug' => $data['slug']],
            [
                'name' => $data['name'],
                'colour' => $data['colour'],
                'description' => $data['description'],
                'company_id' => null,
                'position' => $index + 1,
                'created_by' => $user->id,
            ],
        );

        $labels = $this->seedLabels($board);

        $positions = Position::spread(count($data['lists']));

        foreach ($data['lists'] as $i => $list) {
            $this->seedList($board, $list, $positions[$i], $labels, $user);
        }
    }

    /**
     * A board's labels, keyed by name.
     *
     * A plain support collection, not an Eloquent one: `Collection::only()` is
     * overridden there to select by primary key, which would quietly return
     * nothing when asked for 'Outreach'.
     *
     * @return Collection<string, Label>
     */
    private function seedLabels(Board $board): Collection
    {
        $labels = new Collection;
        $position = 0;

        foreach (self::LABELS as $name => $colour) {
            $labels->put($name, Label::query()->updateOrCreate(
                ['board_id' => $board->id, 'name' => $name],
                ['colour' => $colour, 'position' => $position++],
            ));
        }

        return $labels;
    }

    /**
     * @param  Collection<string, Label>  $labels
     */
    private function seedList(Board $board, array $data, string $position, Collection $labels, User $user): void
    {
        $list = BoardList::query()->updateOrCreate(
            ['board_id' => $board->id, 'name' => $data['name']],
            ['position' => $position, 'created_by' => $user->id],
        );

        $positions = Position::spread(count($data['cards']));

        foreach ($data['cards'] as $i => $card) {
            $this->seedCard($list, $card, $positions[$i], $labels, $user);
        }
    }

    /**
     * @param  Collection<string, Label>  $labels
     */
    private function seedCard(BoardList $list, array $data, string $position, Collection $labels, User $user): void
    {
        $card = Card::query()->updateOrCreate(
            ['board_list_id' => $list->id, 'title' => $data['title']],
            [
                'description' => $data['description'],
                'position' => $position,
                'customer_id' => null,
                'company_id' => null,
                // An integer offset in days, so the board always holds a card
                // that is genuinely late and one that is genuinely close.
                'due_on' => isset($data['due']) ? now()->startOfDay()->addDays($data['due'])->toDateString() : null,
                'completed_at' => null,
                'created_by' => $user->id,
            ],
        );

        $card->labels()->sync(
            $labels->only($data['labels'] ?? [])->pluck('id')->all(),
        );

        // The fixture named four people but only one of them can be a real row
        // here. Any card the fixture had assigned gets the seeder's user; a
        // card with no assignee keeps none, which is what the avatar slot on
        // 'Scope the Bluepeak booking widget' is there to show.
        $card->members()->sync(($data['assigned'] ?? false) ? [$user->id] : []);

        $this->seedChecklist($card, $data['checklist'] ?? [], $user);
        $this->seedComments($card, $data['comments'] ?? [], $user);
    }

    /**
     * @param  list<array{text: string, done?: bool}>  $items
     */
    private function seedChecklist(Card $card, array $items, User $user): void
    {
        if ($items === []) {
            return;
        }

        $checklist = Checklist::query()->updateOrCreate(
            ['card_id' => $card->id, 'name' => 'Checklist'],
            ['position' => Position::format(Position::STEP)],
        );

        $positions = Position::spread(count($items));

        foreach ($items as $i => $item) {
            $done = $item['done'] ?? false;

            ChecklistItem::query()->updateOrCreate(
                ['checklist_id' => $checklist->id, 'text' => $item['text']],
                [
                    'is_done' => $done,
                    'position' => $positions[$i],
                    // Midnight rather than the moment of seeding, so a second
                    // run finds the value it wrote and writes nothing.
                    'completed_at' => $done ? now()->startOfDay()->subDays(2) : null,
                    'created_by' => $user->id,
                ],
            );
        }
    }

    /**
     * @param  list<string>  $bodies  Oldest first, as the drawer reads them.
     */
    private function seedComments(Card $card, array $bodies, User $user): void
    {
        $total = count($bodies);

        foreach ($bodies as $i => $body) {
            $comment = CardComment::query()->updateOrCreate(
                ['card_id' => $card->id, 'body' => $body],
                ['created_by' => $user->id],
            );

            // The thread is ordered by `created_at`, and every row inserted in
            // one pass would otherwise share a timestamp. One day apart, oldest
            // first, anchored to midnight so the second run matches exactly.
            $comment->forceFill([
                'created_at' => now()->startOfDay()->subDays($total - $i),
            ])->save();
        }
    }

    /**
     * The fixture, board by board.
     *
     * `due` is a day offset from today. `assigned` says the fixture had a
     * person on the card. `checklist` reproduces the `[done, total]` pair the
     * board face showed — where the drawer listed fewer items than the face
     * counted, the drawer's wording is kept and the remainder is written out
     * rather than invented at run time.
     */
    private function boards(): array
    {
        return [
            [
                'slug' => 'client-work',
                'name' => 'Client Work',
                'colour' => 'primary',
                'description' => 'Everything billable, from the first scoping note to the invoice.',
                'lists' => [
                    [
                        'name' => 'Backlog',
                        'cards' => [
                            [
                                'title' => 'Rewrite portfolio landing copy',
                                'description' => "The current page reads like a CV. Rewrite it around the three services that actually sell:\n\n- retainer development\n- one-off audits\n- migration work\n\nKeep it under 400 words and end on the booking link.",
                                'labels' => ['Copywriting'],
                                'assigned' => true,
                                'due' => null,
                                'checklist' => [
                                    ['text' => 'Pull the three highest-earning services from the 2026 invoices'],
                                    ['text' => 'Draft the hero paragraph'],
                                    ['text' => 'Rewrite the services section'],
                                    ['text' => 'Proofread and publish'],
                                ],
                                'comments' => [
                                    'The old headline still mentions WordPress. We stopped taking that work in March.',
                                    'Good catch. Dropping it in the rewrite.',
                                ],
                            ],
                            [
                                'title' => 'Collect testimonials from past clients',
                                'description' => 'Ask the five clients from the last two quarters for two sentences each. Offer to draft something they can edit — it doubles the reply rate.',
                                'labels' => ['Outreach', 'Admin'],
                                'assigned' => true,
                                'due' => 10,
                                'checklist' => [
                                    ['text' => 'Northwind Ltd', 'done' => true],
                                    ['text' => 'Acme Studio'],
                                    ['text' => 'Bluepeak'],
                                ],
                                'comments' => [],
                            ],
                            [
                                'title' => 'Scope the Bluepeak booking widget',
                                'description' => 'Embeddable widget for their existing site. Needs to work without a build step, so plain JS and a single stylesheet.',
                                'labels' => ['Development'],
                                'assigned' => false,
                                'due' => null,
                                'checklist' => [],
                                'comments' => [],
                            ],
                        ],
                    ],
                    [
                        'name' => 'To Do',
                        'cards' => [
                            [
                                'title' => 'Send the Northwind retainer proposal',
                                'description' => 'Twelve months, four days a month, invoiced on the first. Reuse the Acme Studio structure but drop the on-call clause.',
                                'labels' => ['Outreach'],
                                'assigned' => true,
                                'due' => 3,
                                'checklist' => [
                                    ['text' => 'Confirm the day rate for 2027', 'done' => true],
                                    ['text' => "Pull last year's hours from the timesheet"],
                                    ['text' => 'Write the scope section', 'done' => true],
                                    ['text' => 'List the systems the retainer covers'],
                                    ['text' => 'Name the escalation contact'],
                                    ['text' => 'Set the response window for urgent work'],
                                    ['text' => 'Agree the notice period'],
                                    ['text' => 'Decide what happens to unused days'],
                                    ['text' => 'Add the payment terms', 'done' => true],
                                    ['text' => 'Fix the invoice date to the first of the month'],
                                    ['text' => 'Check the late payment terms against the last contract'],
                                    ['text' => 'Add the expenses policy'],
                                    ['text' => 'Attach the 2026 case study'],
                                    ['text' => 'Update the cover letter'],
                                    ['text' => 'Internal read-through', 'done' => true],
                                    ['text' => 'Have Sara read the scope section'],
                                    ['text' => 'Export to PDF', 'done' => true],
                                    ['text' => 'Send to Helen at Northwind'],
                                    ['text' => 'Chase Helen if there is no reply by Friday'],
                                    ['text' => 'File the signed copy in the client folder'],
                                ],
                                'comments' => [
                                    'Rate looks low next to what we quoted Bluepeak for the same work.',
                                ],
                            ],
                            [
                                'title' => 'Fix invoice PDF margins',
                                'description' => 'The footer overlaps the last table row when an invoice runs past fifteen line items. Only shows up on A4.',
                                'labels' => ['Bug'],
                                'assigned' => true,
                                'due' => null,
                                'checklist' => [],
                                'comments' => [],
                            ],
                        ],
                    ],
                    [
                        'name' => 'In Progress',
                        'cards' => [
                            [
                                'title' => 'Build the Acme Studio mail module',
                                'description' => 'Inbox, campaigns, contacts and provider settings. The provider layer has to stay swappable — Acme want to move off Postmark next year.',
                                'labels' => ['Development'],
                                'assigned' => true,
                                'due' => 18,
                                'checklist' => [
                                    ['text' => 'Inbox list and reading pane', 'done' => true],
                                    ['text' => 'Campaign composer', 'done' => true],
                                    ['text' => 'Contact import', 'done' => true],
                                    ['text' => 'Provider credentials screen'],
                                    ['text' => 'Bounce handling'],
                                    ['text' => 'Unsubscribe page'],
                                    ['text' => 'Sending domain checks'],
                                    ['text' => 'Rate limiting'],
                                    ['text' => 'Hand-over notes'],
                                ],
                                'comments' => [
                                    'Acme asked whether campaign stats can be exported. Adding it here so it is not forgotten.',
                                    'Out of scope for the first release. I will quote it separately.',
                                    'Provider screen is blocked on the credentials store landing first.',
                                    'Credentials store merged this morning, so that one is unblocked.',
                                ],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Review',
                        'cards' => [
                            [
                                'title' => 'Q3 expense reconciliation',
                                'description' => 'Match every card payment against a receipt before the quarter closes. Anything without a receipt goes on the personal side.',
                                'labels' => ['Finance'],
                                'assigned' => true,
                                'due' => -1,
                                'checklist' => [
                                    ['text' => 'Export the card statement', 'done' => true],
                                    ['text' => 'Match hosting invoices', 'done' => true],
                                    ['text' => 'Match software subscriptions', 'done' => true],
                                    ['text' => 'Match travel', 'done' => true],
                                    ['text' => 'Match equipment', 'done' => true],
                                    ['text' => 'Flag anything unmatched', 'done' => true],
                                    ['text' => 'File the receipts', 'done' => true],
                                    ['text' => 'Hand to the accountant', 'done' => true],
                                ],
                                'comments' => [],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Done',
                        'cards' => [
                            [
                                'title' => 'Register the kargah.dev domain',
                                'description' => 'Registered for five years with privacy on. Renewal is in the calendar.',
                                'labels' => ['Admin'],
                                'assigned' => true,
                                'due' => null,
                                'checklist' => [],
                                'comments' => [],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'outreach',
                'name' => 'Outreach',
                'colour' => 'success',
                'description' => 'Names, conversations and the ones that turned into work.',
                'lists' => [
                    [
                        'name' => 'Leads',
                        'cards' => [
                            [
                                'title' => 'Orbit Studio — referred by Bluepeak',
                                'description' => 'Small design studio, six people, no developer in house. They want someone on call for the sites they build.',
                                'labels' => ['Outreach'],
                                'assigned' => true,
                                'due' => 2,
                                'checklist' => [
                                    ['text' => 'Read the Bluepeak referral note', 'done' => true],
                                    ['text' => 'Look at the sites they have shipped this year'],
                                    ['text' => 'Draft the first email'],
                                    ['text' => 'Book a twenty-minute call'],
                                ],
                                'comments' => [
                                    'Bluepeak passed the name on directly, so the introduction is warm.',
                                ],
                            ],
                            [
                                'title' => 'Follow up with Harbour & Finch',
                                'description' => 'Third contact. They asked for a quote in June and have not answered since the budget question.',
                                'labels' => ['Outreach'],
                                'assigned' => true,
                                'due' => -5,
                                'checklist' => [],
                                'comments' => [
                                    'Second email sent, still nothing back.',
                                    'They went quiet right after the budget question, which usually means it moved.',
                                    'Trying the office number on Monday, then leaving it.',
                                ],
                            ],
                        ],
                    ],
                    [
                        'name' => 'In Conversation',
                        'cards' => [
                            [
                                'title' => 'Northwind Ltd — retainer renewal call',
                                'description' => 'The first twelve months end in September. Go in with the hours already totalled rather than arguing the rate from memory.',
                                'labels' => ['Finance'],
                                'assigned' => true,
                                'due' => 12,
                                'checklist' => [
                                    ['text' => 'Pull the hours logged since January', 'done' => true],
                                    ['text' => 'Work out what the current rate really covers', 'done' => true],
                                    ['text' => 'Decide the new day rate'],
                                    ['text' => 'Write the one-page summary'],
                                    ['text' => 'Send the calendar invitation'],
                                ],
                                'comments' => [
                                    'Helen wants the call before their board meeting on the 20th.',
                                    'Sending the summary a day early so nobody has to read it on the call.',
                                ],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Won',
                        'cards' => [
                            [
                                'title' => 'Acme Studio — signed for Q3',
                                'description' => 'Signed on the mail module, three months, with the provider work quoted separately.',
                                'labels' => ['Admin'],
                                'assigned' => true,
                                'due' => null,
                                'checklist' => [],
                                'comments' => [],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'personal',
                'name' => 'Personal',
                'colour' => 'warning',
                'description' => 'The unbillable half: paperwork, reading, and the things that only bite once a year.',
                'lists' => [
                    [
                        'name' => 'Admin',
                        'cards' => [
                            [
                                'title' => 'File the Q2 self-assessment',
                                'description' => 'Everything is already in the accounting module. This is mostly a matter of checking it against the bank and pressing submit.',
                                'labels' => ['Finance'],
                                'assigned' => true,
                                'due' => 5,
                                'checklist' => [
                                    ['text' => 'Download the bank statements', 'done' => true],
                                    ['text' => 'Total the invoices raised', 'done' => true],
                                    ['text' => 'Total the allowable expenses'],
                                    ['text' => 'Work out the home office share'],
                                    ['text' => "Check last year's figures for anything missed"],
                                    ['text' => 'Submit and save the receipt'],
                                ],
                                'comments' => [],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Learning',
                        'cards' => [
                            [
                                'title' => 'Finish the Livewire 4 upgrade notes',
                                'description' => 'Notes for the modules that have not moved yet. Written for the version of me who does the upgrade in six months and remembers none of this.',
                                'labels' => ['Development'],
                                'assigned' => true,
                                'due' => null,
                                'checklist' => [
                                    ['text' => 'Read the upgrade guide end to end', 'done' => true],
                                    ['text' => 'List every breaking change that touches us', 'done' => true],
                                    ['text' => 'Note what happened to the old lifecycle hooks', 'done' => true],
                                    ['text' => 'Work out how islands change partial rendering', 'done' => true],
                                    ['text' => 'Decide what to do about the wire:model defaults'],
                                    ['text' => 'Test the file upload path'],
                                    ['text' => 'Rewrite the toast component against the new API'],
                                    ['text' => 'Check which Alpine version ships with it'],
                                    ['text' => 'Time a board render before and after'],
                                    ['text' => 'Write the migration steps for each module'],
                                    ['text' => 'Publish the notes where the team can find them'],
                                ],
                                'comments' => [
                                    'The islands work is the part worth reading twice — the rest is mechanical.',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
