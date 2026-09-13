<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $name
 */
class Team extends Model
{
    protected $guarded = ['id'];
}
