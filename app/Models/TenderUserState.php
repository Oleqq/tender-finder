<?php

namespace App\Models;

use App\Enums\TenderUserStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $tender_id
 * @property TenderUserStatus $status
 * @property User $user
 * @property Tender $tender
 */
class TenderUserState extends Model
{
    protected $fillable = [
        'user_id',
        'tender_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenderUserStatus::class,
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
