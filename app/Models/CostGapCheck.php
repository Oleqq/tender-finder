<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property int $participation_id
 * @property int $version
 * @property array<string, array<string, mixed>> $sources
 * @property list<array<string, mixed>> $rows
 * @property array<string, array<string, mixed>>|null $decisions
 * @property string|null $manual_requirements
 * @property string $source_hash
 * @property string $tender_hash
 */
final class CostGapCheck extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['sources' => 'array', 'rows' => 'array', 'decisions' => 'array', 'version' => 'integer'];
    }
}
