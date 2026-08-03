<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\Linkable;
use Modules\Core\Models\Customer;
use Modules\Mailbox\Database\Factories\ContactFactory;

/**
 * Somebody a campaign can go to.
 *
 * `email` is unique across the whole table rather than per list, because the
 * question every send asks is 'have I already got this person', and a duplicate
 * would mean two rows disagreeing about whether they are still subscribed.
 * Lists are tags rather than a table: a contact belongs to as many as the
 * import gave them, and a tag costs nothing to add or drop.
 *
 * `is_subscribed` is this contact's own preference and is separate from the
 * suppression list, which is global and outranks it. An address can be
 * subscribed here and still blocked, and the send has to check both — the
 * contact record says what the person asked for, the suppression list says what
 * the provider reported.
 */
class Contact extends Model
{
    use HasFactory;
    use Linkable;
    use SoftDeletes;

    public const IMPORT = 'import';

    public const MANUAL = 'manual';

    public const INBOX = 'inbox';

    protected $fillable = [
        'email',
        'name',
        'company_name',
        'customer_id',
        'tags',
        'meta',
        'is_subscribed',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'meta' => 'array',
            'is_subscribed' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /** How the contact reads in a table when the import carried no name. */
    public function label(): string
    {
        return $this->name ?: (string) $this->email;
    }

    /** @return list<string> */
    public function tagList(): array
    {
        return is_array($this->tags) ? array_values(array_filter($this->tags, 'is_string')) : [];
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tagList(), true);
    }

    public function scopeSubscribed(Builder $query): Builder
    {
        return $query->where('is_subscribed', true);
    }

    /**
     * Contacts carrying a tag.
     *
     * `like` on a JSON column rather than a JSON path expression, because the
     * two supported databases spell the path differently and a list of a few
     * thousand contacts is not where the query planner is going to matter. The
     * quotes are part of the pattern so `agencies` does not match
     * `agencies-uk`.
     */
    public function scopeTagged(Builder $query, string $tag): Builder
    {
        return $query->where('tags', 'like', '%"'.$tag.'"%');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('email', 'like', '%'.$term.'%')
            ->orWhere('name', 'like', '%'.$term.'%')
            ->orWhere('company_name', 'like', '%'.$term.'%'));
    }

    /** The order every page lists contacts in: newest first, then by address so it never reshuffles. */
    public function scopeInReadingOrder(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }

    /**
     * Every distinct tag in use, with how many contacts carry it.
     *
     * Counted in PHP rather than in SQL because the tags live in a JSON column
     * and neither MySQL nor SQLite will group by an array element portably. The
     * page that calls this shows a sidebar of lists, so the row count is the
     * contact count — a few thousand at most, which is a single query and a
     * loop rather than a schema change.
     *
     * @return array<string, int> Tag to count, most used first.
     */
    public static function tagCounts(): array
    {
        $counts = [];

        foreach (self::query()->pluck('tags') as $tags) {
            $decoded = is_string($tags) ? json_decode($tags, true) : $tags;

            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                }
            }
        }

        arsort($counts);

        return $counts;
    }

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }
}
