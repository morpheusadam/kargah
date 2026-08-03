<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Database\Factories\NotificationSettingFactory;

/**
 * One person's digest frequency and quiet-hours window — one row per user,
 * never one row per user per event. See
 * `2026_01_01_000006_create_notification_preferences_table`'s docblock for
 * why that split is a second table rather than a JSON column or extra
 * columns shared with `NotificationPreference`.
 */
class NotificationSetting extends Model
{
    use HasFactory;

    protected $table = 'notification_settings';

    protected $fillable = [
        'user_id',
        'digest',
        'quiet_hours_enabled',
        'quiet_hours_from',
        'quiet_hours_to',
    ];

    protected function casts(): array
    {
        return [
            'quiet_hours_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): NotificationSettingFactory
    {
        return NotificationSettingFactory::new();
    }
}
