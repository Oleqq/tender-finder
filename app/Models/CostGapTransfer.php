<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property int $cost_gap_check_id
 * @property string $requirement_key
 * @property string $category
 * @property string $amount
 * @property string $previous_amount
 * @property string $result_amount
 * @property int $economics_version
 * @property array<string, mixed> $snapshot
 */
final class CostGapTransfer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'previous_amount' => 'decimal:2', 'result_amount' => 'decimal:2',
            'economics_version' => 'integer', 'snapshot' => 'array'];
    }
}
