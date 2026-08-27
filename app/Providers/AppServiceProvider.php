<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Vite as ViteManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('telegram-session', fn (Request $request): Limit => Limit::perMinute(12)
            ->by('telegram-session:'.$request->ip()));
        RateLimiter::for('telegram-webhook', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('telegram-webhook:'.$request->ip()));
        RateLimiter::for('local-mvp-preview', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('local-mvp-preview:'.$request->user()?->id));
        RateLimiter::for('local-mvp-rss-preview', fn (Request $request): Limit => Limit::perMinute(4)
            ->by('local-mvp-rss-preview:'.$request->user()?->id));
        RateLimiter::for('remote-mvp-operator', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('remote-mvp-operator:'.$request->ip()));

        if (! $this->app->runningInConsole()) {
            $host = $this->app['request']->getHost();

            if ($this->app->environment('production')) {
                // Generate absolute HTTPS URLs from the host that received the
                // request. This works behind Railway's public proxy and on a
                // future custom domain without baking a host into config cache.
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
