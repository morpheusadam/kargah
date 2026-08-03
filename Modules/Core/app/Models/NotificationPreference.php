<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Database\Factories\NotificationPreferenceFactory;

/**
 * One person's opinion about one event: in the feed, by email, neither, both.
 *
 * Nothing outside Core may touch this class — go through
 * `Modules\Core\Contracts\NotificationPreferences`, which hands back arrays.
 *
 * A user with no row for an event has not made a decision about it, and a
 * reader must fall back to `Modules\Core\Support\NotificationEvents`'
 * default rather than treating "no row" as "off". See the migration's
 * docblock for why nothing seeds these on account creation.
 */
class NotificationPreference extends Model
{
    use HasFactory;

    protected $table = 'notification_preferences';

    protected $fillable = [
        'user_id',
        'event',
        'in_app',
        'email',
    ];

    protected function casts(): array
    {
        return [
            'in_app' => 'boolean',
            'email' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): NotificationPreferenceFactory
    {
        return NotificationPreferenceFactory::new();
    }
}
