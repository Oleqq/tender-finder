<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property bool $is_staff
 * @property string $body
 * @property Carbon $created_at
 */
class SupportTicketMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['ticket_id', 'author_id', 'is_staff', 'body', 'created_at'];

    protected function casts(): array
    {
        return ['is_staff' => 'boolean', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }
}
