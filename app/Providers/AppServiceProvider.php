<?php

namespace App\Providers;

use Illuminate\Foundation\Vite as ViteManager;
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
        if (! $this->app->runningInConsole()) {
            $host = $this->app['request']->getHost();
            $isVercelRequest = str_ends_with($host, '.vercel.app');

            if ($this->app->environment('production') || $isVercelRequest) {
                // Vercel invokes PHP over HTTP and can retain that asset root in
                // its config cache. Resolve Vite assets from the public HTTPS host.
                URL::forceScheme('https');
                URL::forceRootUrl('https://'.$host);
                $this->app->make(ViteManager::class)->createAssetPathsUsing(
                    fn (string $path): string => 'https://'.$host.'/'.ltrim($path, '/'),
                );
            }
        }

        Vite::prefetch(concurrency: 3);
    }
}
