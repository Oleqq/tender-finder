<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id
 * @property int $user_id
 * @property int $version
 * @property string|null $filename
 */
final class PriceCatalog extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['version' => 0];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /** @return HasMany<PriceCatalogItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceCatalogItem::class);
    }
}
