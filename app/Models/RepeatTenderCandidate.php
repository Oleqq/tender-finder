<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $watch_id
 * @property int $tender_id
 * @property int $version
 * @property string $status
 * @property array<string, mixed> $snapshot
 * @property array<string, mixed> $basis
 * @property array<string, string>|null $evidence
 * @property string|null $decision_note
 * @property Carbon|null $read_at
 */
final class RepeatTenderCandidate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'basis' => 'array', 'evidence' => 'array', 'version' => 'integer', 'read_at' => 'datetime'];
    }
}
