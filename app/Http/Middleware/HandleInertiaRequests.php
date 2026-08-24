<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AccessService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => function () use ($request): array {
                /** @var User|null $user */
                $user = $request->user();

                if ($user === null) {
                    return ['user' => null, 'access' => null];
                }

                return [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'role' => $user->role->value,
                    ],
                    'access' => app(AccessService::class)->snapshotFor($user)->toArray(),
                ];
            },
        ];
    }
}
