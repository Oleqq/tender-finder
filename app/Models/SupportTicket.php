<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $assignee_id
 * @property string $category
 * @property string $status
 * @property User $user
 */
class SupportTicket extends Model
{
    protected $fillable = ['user_id', 'assignee_id', 'category', 'status'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return HasMany<SupportTicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id');
    }

    /** @return HasMany<SupportTicketEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SupportTicketEvent::class, 'ticket_id');
    }
}
