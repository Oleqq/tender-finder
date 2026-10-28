<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $body
 * @property int $version
 * @property array<string, mixed> $snapshot
 */
final class ProposalDraft extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['version' => 'integer', 'snapshot' => 'array'];
    }
}
