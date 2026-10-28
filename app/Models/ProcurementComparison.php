<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $version
 * @property list<array<string, mixed>> $selection
 * @property list<array<string, mixed>> $offers
 * @property array<string, mixed> $combined_offer
 * @property array<string, mixed>|null $confirmed_result
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $canceled_at
 */
final class ProcurementComparison extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['selection' => 'array', 'offers' => 'array', 'combined_offer' => 'array', 'confirmed_result' => 'array',
            'version' => 'integer', 'confirmed_at' => 'datetime', 'canceled_at' => 'datetime'];
    }
}
