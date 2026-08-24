<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            // Vercel forwards requests to PHP internally. Force the public scheme
            // so Inertia's Vite tags never point the browser at blocked HTTP assets.
            URL::forceScheme('https');
        }

        Vite::prefetch(concurrency: 3);
    }
}
