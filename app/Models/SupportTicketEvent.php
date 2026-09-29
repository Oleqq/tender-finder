<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $actor_id
 * @property string $action
 * @property string|null $old_status
 * @property string|null $new_status
 * @property int|null $old_assignee_id
 * @property int|null $new_assignee_id
 * @property string|null $reason
 * @property Carbon $created_at
 */
class SupportTicketEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ticket_id', 'actor_id', 'action', 'old_status', 'new_status',
        'old_assignee_id', 'new_assignee_id', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
