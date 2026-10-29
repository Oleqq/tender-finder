<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $participation_id
 * @property int $version
 * @property int $source_revision
 * @property string $creation_hash
 * @property string $title
 * @property string $topic
 * @property string|null $context
 * @property string $body
 * @property array<string, mixed> $source
 * @property string $tender_hash
 * @property Carbon|null $marked_sent_at
 * @property Carbon|null $archived_at
 * @property string|null $answer
 * @property string|null $answer_source
 * @property Carbon|null $answer_received_on
 * @property Carbon|null $answer_updated_at
 */
final class TenderQuestion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source' => 'array', 'version' => 'integer', 'source_revision' => 'integer',
            'marked_sent_at' => 'datetime', 'archived_at' => 'datetime', 'answer_updated_at' => 'datetime', 'answer_received_on' => 'date'];
    }
}
