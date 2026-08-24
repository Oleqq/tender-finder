<?php

namespace App\Models;

use App\Enums\ConsentAction;
use App\Enums\ConsentDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ConsentDocument $document
 * @property ConsentAction $action
 * @property string $document_version
 */
class ConsentEvent extends Model
{
    protected $fillable = [
        'user_id',
        'document',
        'document_version',
        'action',
        'ip_hash',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'document' => ConsentDocument::class,
            'action' => ConsentAction::class,
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<ConsentEvent> $query */
    public function scopeLatestForDocument(Builder $query, ConsentDocument $document): void
    {
        $query->where('document', $document->value)->latest('occurred_at');
    }
}
