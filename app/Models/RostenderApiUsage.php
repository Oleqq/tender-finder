<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RostenderApiUsage extends Model
{
    protected $fillable = ['usage_date', 'successful_requests', 'in_flight_requests'];

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'successful_requests' => 'integer',
            'in_flight_requests' => 'integer',
        ];
    }
}
