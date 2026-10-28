<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property int $price_catalog_id
 * @property string $name
 * @property string|null $characteristics
 * @property string|null $price
 * @property string|null $quantity
 * @property string|null $unit
 * @property string|null $region
 */
final class PriceCatalogItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'quantity' => 'decimal:3'];
    }
}
