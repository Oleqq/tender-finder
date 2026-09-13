<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $participation_id
 * @property int|null $author_id
 * @property string|null $body
 * @property int $version
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property User|null $author
 * @property TenderParticipation $participation
 */
class ParticipationComment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'edited_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    /** @return BelongsTo<TenderParticipation, $this> */
    public function participation(): BelongsTo
    {
        return $this->belongsTo(TenderParticipation::class, 'participation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
