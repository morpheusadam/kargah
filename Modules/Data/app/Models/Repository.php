<?php

namespace Modules\Data\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Data\Database\Factories\RepositoryFactory;

/**
 * A code repository, mirrored locally from its provider.
 *
 * Rows here are a cache, not a source of truth, which is why there are no soft
 * deletes: a repository that is gone from GitHub is gone, and keeping a tombstone
 * would put a dead link on a page. `synced_at` is the only column that says how
 * stale the copy is, and the page shows it rather than hiding the fact.
 *
 * The unique key is `provider` + `full_name`, so `data:sync-repos` can run on
 * cron without a missed day or a doubled run mattering.
 */
class Repository extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'full_name',
        'description',
        'language',
        'default_branch',
        'stars',
        'forks',
        'open_issues',
        'is_private',
        'is_archived',
        'html_url',
        'pushed_at',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'stars' => 'integer',
            'forks' => 'integer',
            'open_issues' => 'integer',
            'is_private' => 'boolean',
            'is_archived' => 'boolean',
            'pushed_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /** The part after the slash, which is what a person calls the project. */
    public function shortName(): string
    {
        return str_contains($this->full_name, '/')
            ? substr($this->full_name, strrpos($this->full_name, '/') + 1)
            : $this->full_name;
    }

    public function owner(): ?string
    {
        return str_contains($this->full_name, '/')
            ? substr($this->full_name, 0, strpos($this->full_name, '/'))
            : null;
    }

    public function cloneUrl(): string
    {
        return 'git@github.com:'.$this->full_name.'.git';
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        if (trim($term) === '') {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('full_name', 'like', $like)
            ->orWhere('description', 'like', $like)
            ->orWhere('language', 'like', $like));
    }

    /**
     * The sort orders the list page offers.
     *
     * A map rather than string interpolation into `orderBy`, because the value
     * arrives from a `#[Url]` property and therefore from the address bar.
     */
    public function scopeSorted(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'stars' => $query->orderByDesc('stars')->orderBy('full_name'),
            'name' => $query->orderBy('full_name'),
            'created' => $query->orderByDesc('id'),
            default => $query->orderByDesc('pushed_at')->orderBy('full_name'),
        };
    }

    protected static function newFactory(): RepositoryFactory
    {
        return RepositoryFactory::new();
    }
}
