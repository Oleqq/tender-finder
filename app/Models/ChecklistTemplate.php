<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $team_id
 * @property string $name
 * @property list<string> $items
 * @property int $version
 */
class ChecklistTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'version' => 'integer'];
    }

    /** @return HasMany<ChecklistTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ChecklistTemplateVersion::class, 'template_id')->latest('version');
    }
}
