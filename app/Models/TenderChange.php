<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array<string, array{before: string|null, after: string|null}> $changes */
class TenderChange extends Model
{
    protected $fillable = ['tender_id', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }
}
