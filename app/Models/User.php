<?php

namespace App\Models;

use App\Enums\UserRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $name
 * @property string|null $telegram_id
 * @property UserRole $role
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $trial_used_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'telegram_id',
        'telegram_username',
        'telegram_first_name',
        'telegram_last_name',
        'telegram_language_code',
        'last_seen_at',
        'trial_used_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'last_seen_at' => 'datetime',
            'trial_used_at' => 'datetime',
        ];
    }

    /** @return HasMany<SearchQuery, $this> */
    public function searchQueries(): HasMany
    {
        return $this->hasMany(SearchQuery::class);
    }

    /** @return HasMany<ConsentEvent, $this> */
    public function consentEvents(): HasMany
    {
        return $this->hasMany(ConsentEvent::class);
    }

    /** @return HasMany<TenderUserState, $this> */
    public function tenderStates(): HasMany
    {
        return $this->hasMany(TenderUserState::class);
    }
}
