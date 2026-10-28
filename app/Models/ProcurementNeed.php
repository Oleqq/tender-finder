<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $participation_id
 * @property int|null $confirmed_comparison_id
 * @property int $version
 * @property string $quantity
 * @property Carbon|null $required_on
 * @property TenderParticipation $participation
 */
final class ProcurementNeed extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'required_on' => 'date', 'version' => 'integer',
            'confirmed_comparison_id' => 'integer'];
    }

    /** @return BelongsTo<TenderParticipation, $this> */
    public function participation(): BelongsTo
    {
        return $this->belongsTo(TenderParticipation::class);
    }
}
