<?php

namespace Modules\Data\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Company;
use Modules\Data\Database\Factories\BookmarkFactory;

/**
 * A URL worth keeping: a Telegram bot, a deployed project, a panel, a reference.
 *
 * The four kinds come from the migration's own comment and are fixed here as a
 * constant, because a kind that is not one of them has no icon, no badge and no
 * filter button — a free-text column would let one in and the page would render
 * a blank glyph rather than fail.
 *
 * `last_status` is the HTTP code from the last reachability check. It is
 * nullable and stays that way until something checks: an unchecked link and a
 * link that answered 200 are different facts and are stored differently.
 */
class Bookmark extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const KIND_TELEGRAM_BOT = 'telegram_bot';

    public const KIND_DEPLOYED_PROJECT = 'deployed_project';

    public const KIND_REFERENCE = 'reference';

    public const KIND_TOOL = 'tool';

    /** @var list<string> */
    public const KINDS = [
        self::KIND_TELEGRAM_BOT,
        self::KIND_DEPLOYED_PROJECT,
        self::KIND_REFERENCE,
        self::KIND_TOOL,
    ];

    protected $fillable = [
        'title',
        'url',
        'kind',
        'notes',
        'tags',
        'company_id',
        'last_checked_at',
        'last_status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'last_checked_at' => 'datetime',
            'last_status' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        if (trim($term) === '') {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('title', 'like', $like)
            ->orWhere('url', 'like', $like)
            ->orWhere('notes', 'like', $like));
    }

    /** The host, for the favicon placeholder and the list subtitle. */
    public function host(): ?string
    {
        return parse_url($this->url, PHP_URL_HOST) ?: null;
    }

    /** @return list<string> */
    public function tagList(): array
    {
        return array_values(array_filter((array) ($this->tags ?? [])));
    }

    protected static function newFactory(): BookmarkFactory
    {
        return BookmarkFactory::new();
    }
}
