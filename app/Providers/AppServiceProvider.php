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
        if ($this->app->environment('production') && ! $this->app->runningInConsole()) {
            // Vercel forwards requests to PHP internally and may retain an HTTP
            // asset root. Use the public request host so Vite tags are HTTPS.
            URL::forceScheme('https');
            URL::forceRootUrl('https://'.$this->app['request']->getHost());
        }

        Vite::prefetch(concurrency: 3);
    }
}
