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
 * @property int|null $access_entitlement_id
 * @property string|null $access_plan_code
 * @property Carbon|null $access_ends_at
 * @property Carbon $created_at
 */
class SupportTicketEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ticket_id', 'actor_id', 'action', 'old_status', 'new_status',
        'old_assignee_id', 'new_assignee_id', 'reason', 'access_entitlement_id',
        'access_plan_code', 'access_ends_at', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'access_ends_at' => 'datetime'];
    }
}
