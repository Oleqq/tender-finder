<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $baseline
 * @property list<array{id: string, title: string, category: string, actual: ?string, remaining: ?string, note: ?string, task_id: ?int}> $expenses
 * @property string|null $received_revenue
 * @property string|null $remaining_revenue
 * @property bool $costs_confirmed
 * @property int $version
 */
class ParticipationExecution extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['baseline' => 'array', 'expenses' => 'array', 'received_revenue' => 'decimal:2',
            'remaining_revenue' => 'decimal:2', 'costs_confirmed' => 'boolean', 'version' => 'integer'];
    }
}
