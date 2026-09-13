<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $template_id
 * @property int $version
 * @property string $name
 * @property list<string> $items
 */
class ChecklistTemplateVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'created_at' => 'datetime'];
    }
}
