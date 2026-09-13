<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $team_id
 * @property string $name
 * @property list<string> $items
 */
class ChecklistTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }
}
