<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * How many times one person followed one link.
 *
 * One row per (link, recipient) pair rather than one per click, for the reason
 * `DeliveryEvent` gives for not modelling opens at all: a per-event table would
 * be the largest thing in the database inside a month, and no report in this
 * module asks a question that needs it. What is kept is what is asked for — how
 * many people followed each link, how many times in total, and when a given
 * person first and last did.
 *
 * The pair is unique, so recording a click is an `UPDATE` that hits one row.
 * That matters more than it looks: a click arrives on a public HTTP endpoint
 * that anybody who has been sent the message can call as fast as they like, and
 * the cost of one of those has to stay flat rather than growing with how often
 * it has already happened.
 */
class CampaignLinkClick extends Model
{
    protected $fillable = [
        'campaign_link_id',
        'campaign_recipient_id',
        'clicks',
        'first_clicked_at',
        'last_clicked_at',
    ];

    protected function casts(): array
    {
        return [
            'clicks' => 'integer',
            'first_clicked_at' => 'datetime',
            'last_clicked_at' => 'datetime',
        ];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(CampaignLink::class, 'campaign_link_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }

    /**
     * Add one to this pair's tally, creating the row if this is the first time.
     *
     * Written update-first rather than find-then-write. Two requests for the
     * same pair arriving together — a person double-clicking, or a mail client
     * that fetches the link before handing it to the browser — would otherwise
     * both find nothing and both insert, and one of them would hit the unique
     * index and throw on a path where the person is waiting for a redirect.
     *
     * The insert therefore catches that collision and retries the update, which
     * is the branch the loser of the race takes. Nothing is retried twice: by
     * the time the exception is raised the winning row exists, so the second
     * update cannot fail for the same reason.
     */
    public static function record(int $linkId, int $recipientId): void
    {
        if (self::addOne($linkId, $recipientId)) {
            return;
        }

        try {
            self::query()->create([
                'campaign_link_id' => $linkId,
                'campaign_recipient_id' => $recipientId,
                'clicks' => 1,
                'first_clicked_at' => now(),
                'last_clicked_at' => now(),
            ]);
        } catch (QueryException) {
            self::addOne($linkId, $recipientId);
        }
    }

    /**
     * True when a row was there to be incremented.
     *
     * Not called `increment`: `Model` already has one, it is not static, and PHP
     * refuses to load a class that redeclares it as such — a fatal error rather
     * than anything a test could report.
     */
    private static function addOne(int $linkId, int $recipientId): bool
    {
        return self::query()
            ->where('campaign_link_id', $linkId)
            ->where('campaign_recipient_id', $recipientId)
            ->update([
                'clicks' => DB::raw('clicks + 1'),
                'last_clicked_at' => now(),
                'updated_at' => now(),
            ]) > 0;
    }
}
