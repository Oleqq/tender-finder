<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $version
 * @property string $creation_hash
 * @property array<string, string> $evidence
 * @property string|null $body
 * @property array<string, mixed>|null $draft_source
 * @property string $outcome
 * @property string|null $outcome_note
 * @property Carbon|null $archived_at
 */
final class WinnerOpportunity extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'draft_source' => 'array', 'version' => 'integer', 'archived_at' => 'datetime'];
    }
}
