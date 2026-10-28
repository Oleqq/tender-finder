<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Subscriber,
            'remember_token' => Str::random(10),
        ];
    }

    /** Active Basic access for workspace behavior tests; preview remains the default. */
    public function withAccess(): static
    {
        return $this->afterCreating(function (User $user): void {
            $plan = app(PlanCatalog::class)->basic();
            $subscription = Subscription::query()->create([
                'user_id' => $user->id, 'plan_id' => $plan->id, 'source' => 'admin_grant',
                'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
            ]);
            Entitlement::query()->create([
                'user_id' => $user->id, 'subscription_id' => $subscription->id, 'plan_id' => $plan->id,
                'code' => 'active_queries', 'status' => 'active', 'value' => 3,
                'starts_at' => $subscription->starts_at, 'ends_at' => $subscription->ends_at,
            ]);
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
