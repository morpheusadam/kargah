<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\Linkable;
use Modules\Core\Models\Company;
use Modules\Data\Contracts\AttachmentService;
use Modules\Project\Database\Factories\BoardFactory;
use Modules\Project\Support\Palette;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Board extends Model
{
    use HasFactory;
    use Linkable;
    use LogsActivity;
    use SoftDeletes;

    /** `background_type` values. A photo carries no `background_key` — see the migration. */
    public const BACKGROUND_COLOUR = 'colour';

    public const BACKGROUND_GRADIENT = 'gradient';

    public const BACKGROUND_PHOTO = 'photo';

    protected $fillable = [
        'slug',
        'name',
        'colour',
        'description',
        'company_id',
        'position',
        'archived_at',
        'created_by',
        'background_type',
        'background_key',
        'background_attachment_id',
        'background_text_tone',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'background_attachment_id' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function lists(): HasMany
    {
        return $this->hasMany(BoardList::class)->orderBy('position');
    }

    public function labels(): HasMany
    {
        return $this->hasMany(Label::class)->orderBy('position');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Every placement on every list of this board. A real relation. */
    public function placements(): HasManyThrough
    {
        return $this->hasManyThrough(CardPlacement::class, BoardList::class, 'board_id', 'board_list_id');
    }

    /**
     * The distinct cards this board shows. **This is not a relation.**
     *
     * It cannot be. Boards reach cards over three hops — board, list,
     * placement — and Eloquent's `hasManyThrough` spans two; and a card
     * mirrored onto two lists of the same board must count once, which no
     * relation type deduplicates. So it returns a plain query builder, and the
     * card ids come from a subquery, which is what makes it distinct.
     *
     * The consolation for the missing relation is that `$board->cards` as a
     * *property* throws a `LogicException` from Eloquent rather than silently
     * returning something wrong. Call it, do not read it.
     *
     * @return Builder<Card>
     */
    public function cards(): Builder
    {
        return Card::query()->whereIn(
            'id',
            CardPlacement::query()
                ->select('card_id')
                ->whereIn(
                    'board_list_id',
                    BoardList::query()->select('id')->where('board_id', $this->id),
                ),
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function dotClass(): string
    {
        return Palette::dot($this->colour);
    }

    /* Background --------------------------------------------------------------
     *
     * Three kinds, one row. `background_key` is null for a photo and for a
     * fresh board that has not chosen one — both read the same way: nothing to
     * override, so the canvas keeps its default surface.
     */

    /**
     * The whole class string a canvas needs for a colour or gradient
     * background. Empty for a photo (that one is an inline `background-image`,
     * see `backgroundStyle()`) and for a board with nothing chosen yet.
     */
    public function backgroundClass(): string
    {
        if ($this->background_key === null) {
            return '';
        }

        return match ($this->background_type) {
            self::BACKGROUND_GRADIENT => Palette::hasGradient($this->background_key)
                ? Palette::gradientClass($this->background_key)
                : '',
            self::BACKGROUND_COLOUR => Palette::has($this->background_key)
                // The dot's own solid fill, reused rather than duplicated —
                // the same reasoning that keeps card covers on these keys too.
                ? Palette::dot($this->background_key)
                : '',
            default => '',
        };
    }

    /**
     * An inline `background-image` declaration for a photo background, or
     * null for anything else — including a photo type whose attachment has
     * since been deleted, which must read as "no background" rather than a
     * broken image request.
     */
    public function backgroundStyle(): ?string
    {
        $photo = $this->backgroundPhoto();

        if ($photo === null) {
            return null;
        }

        // `inline_url`, never `download_url`: the latter sends
        // `Content-Disposition: attachment`, which asks the browser to save
        // the picture rather than show it — right for a paperclip, wrong for
        // a background. Same bytes, same `auth`, no expiry either way.
        return "background-image:url('".e($photo['inline_url'])."');background-size:cover;background-position:center;";
    }

    /**
     * The stored attachment behind a photo background, resolved through the
     * contract on every read — never through `Modules\Data`'s own model. A
     * miss (the attachment was deleted, or the id was cleared) reads as no
     * photo rather than throwing, which is what keeps a board renderable
     * after its background photo is removed.
     *
     * @return array{download_url: string}|null
     */
    public function backgroundPhoto(): ?array
    {
        if ($this->background_type !== self::BACKGROUND_PHOTO || $this->background_attachment_id === null) {
            return null;
        }

        return app(AttachmentService::class)->find($this->background_attachment_id);
    }

    /** The whole class string the light/dark toggle resolves to. */
    public function textToneClass(): string
    {
        return Palette::textTone($this->background_text_tone);
    }

    /**
     * The list-column surface for the board canvas. Trello's own list columns
     * turn translucent over a photo or a vivid colour or gradient, so a card's
     * text keeps a stable surface to sit on rather than the raw background
     * showing straight through it. A board with nothing chosen yet keeps the
     * canvas's ordinary muted card surface — today's `bg-muted/40`.
     */
    public function canvasSurfaceClass(): string
    {
        if ($this->backgroundClass() === '' && $this->backgroundStyle() === null) {
            return 'bg-muted/40';
        }

        // Light background text means a vivid or dark background under it, so
        // the list surface goes dark-translucent to match; dark text means a
        // pale background, so the surface stays light.
        return $this->background_text_tone === 'light'
            ? 'bg-black/30 backdrop-blur-sm'
            : 'bg-white/80 backdrop-blur-sm';
    }

    /**
     * The tone a freshly chosen background should default to, before a person
     * overrides it. A photo defaults light because a busy image more often
     * wants a light overlay than a dark one; a colour or gradient has its own
     * recommended tone in `Palette`.
     */
    public function defaultTextToneFor(string $type, ?string $key): string
    {
        return match ($type) {
            self::BACKGROUND_GRADIENT => $key !== null ? Palette::gradientTextTone($key) : 'light',
            self::BACKGROUND_COLOUR => $key !== null ? Palette::defaultTextToneForColour($key) : 'light',
            default => 'light',
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'colour', 'company_id', 'archived_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('board');
    }

    protected static function newFactory(): BoardFactory
    {
        return BoardFactory::new();
    }
}
