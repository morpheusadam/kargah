<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A destination this campaign is allowed to send somebody to.
 *
 * This table is the whole of Kargah's answer to the open redirect. The click
 * route takes an id, looks the row up, and redirects to the `url` column — it
 * never reads a destination from the request, so there is no `?url=` to edit and
 * no way to make lavzen.com forward a person to somewhere it has not already
 * agreed to. That matters twice over: an open redirect on a sending domain is a
 * vulnerability, and it is also a spam signal, because a domain that will
 * forward anywhere is exactly what a phishing campaign looks for in somebody
 * else's reputation.
 *
 * A row is created when a message is built, which is *before* the link it
 * describes has been rewritten into anything. That ordering is the rule, not an
 * implementation detail: a link that could not be registered is left in the body
 * untracked rather than rewritten into a redirect that would 404.
 *
 * `url_hash` exists because the unique index cannot be on the URL itself — it is
 * a `text` column, and a campaign link with a long UTM tail is routinely past
 * what an index will take. The hash is what makes registration one lookup.
 */
class CampaignLink extends Model
{
    protected $fillable = [
        'campaign_id',
        'url',
        'url_hash',
    ];

    /**
     * The fingerprint a URL is registered and found by.
     *
     * SHA-256 of the exact string, with no normalisation whatsoever. Two URLs
     * that differ by a trailing slash or the order of their query parameters are
     * two rows on purpose: they may well be two different pages, and a report
     * that quietly merged them would be reporting on something the campaign
     * never contained.
     */
    public static function fingerprint(string $url): string
    {
        return hash('sha256', $url);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(CampaignLinkClick::class);
    }

    /**
     * The URL as it reads in a table, shortened from the middle.
     *
     * From the middle rather than the end because the tail of a campaign link is
     * where the UTM parameters live and the head is where the domain does, and a
     * person scanning the report needs to tell two links to the same site apart.
     */
    public function label(int $limit = 72): string
    {
        $url = (string) $this->url;

        if (mb_strlen($url) <= $limit) {
            return $url;
        }

        $head = (int) floor(($limit - 1) / 2);

        return mb_substr($url, 0, $head).'…'.mb_substr($url, -($limit - 1 - $head));
    }
}
