<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TeamTenderRoutingRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'priority' => 'integer', 'min_budget' => 'decimal:2', 'version' => 'integer'];
    }
}
