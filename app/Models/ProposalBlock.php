<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $category
 * @property string $body
 * @property int $version
 */
final class ProposalBlock extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
