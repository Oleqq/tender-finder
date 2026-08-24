<?php

namespace App\Models;

use App\Enums\QueryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property list<string> $keywords
 * @property list<string>|null $minus_keywords
 * @property string|null $region
 * @property string|null $budget_min
 * @property string|null $budget_max
 * @property Carbon|null $deadline_from
 * @property Carbon|null $deadline_to
 * @property QueryStatus $status
 * @property Carbon|null $monitoring_started_at
 * @property User $user
 */
class SearchQuery extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'keywords',
        'minus_keywords',
        'region',
        'budget_min',
        'budget_max',
        'deadline_from',
        'deadline_to',
        'status',
        'filters',
        'monitoring_started_at',
        'paused_at',
        'frozen_at',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'minus_keywords' => 'array',
            'filters' => 'array',
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'deadline_from' => 'date',
            'deadline_to' => 'date',
            'status' => QueryStatus::class,
            'monitoring_started_at' => 'datetime',
            'paused_at' => 'datetime',
            'frozen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<TenderQueryMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(TenderQueryMatch::class);
    }
}
