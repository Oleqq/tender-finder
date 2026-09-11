<?php

namespace App\Models;

use App\Enums\TenderUserStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $tender_id
 * @property TenderUserStatus $status
 * @property string|null $note
 * @property list<string>|null $tags
 * @property Carbon|null $next_action_on
 * @property bool $deadline_reminders_enabled
 * @property bool $action_reminder_enabled
 * @property bool $watch_changes
 * @property Carbon|null $watch_started_at
 * @property User $user
 * @property Tender $tender
 */
class TenderUserState extends Model
{
    protected $fillable = [
        'user_id',
        'tender_id',
        'status',
        'note',
        'tags',
        'next_action_on',
        'deadline_reminders_enabled', 'action_reminder_enabled', 'watch_changes', 'watch_started_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenderUserStatus::class,
            'tags' => 'array',
            'next_action_on' => 'date',
            'deadline_reminders_enabled' => 'boolean',
            'action_reminder_enabled' => 'boolean',
            'watch_changes' => 'boolean',
            'watch_started_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Tender, $this> */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }
}
