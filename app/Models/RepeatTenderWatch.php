<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $tender_id
 * @property int $version
 * @property string $reason
 * @property array<string, mixed> $original
 * @property Carbon $scan_after
 * @property Carbon|null $checked_at
 * @property Carbon|null $archived_at
 * @property bool $scan_limited
 */
final class RepeatTenderWatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['original' => 'array', 'version' => 'integer', 'scan_after' => 'datetime', 'checked_at' => 'datetime', 'archived_at' => 'datetime', 'scan_limited' => 'boolean'];
    }
}
