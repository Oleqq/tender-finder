<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property int $user_id
 * @property int $tender_id
 * @property int $version
 * @property bool $complete
 * @property string|null $filename
 * @property list<array<string, mixed>>|null $requirements
 * @property array<int, array<string, mixed>>|null $reviews
 */
final class PriceCatalogComparison extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['version' => 0, 'complete' => false];

    protected function casts(): array
    {
        return ['requirements' => 'array', 'reviews' => 'array', 'complete' => 'boolean', 'version' => 'integer'];
    }
}
