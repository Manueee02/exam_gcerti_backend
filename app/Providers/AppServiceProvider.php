<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

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
        RateLimiter::for('answer-submit', fn ($request) =>
        Limit::perMinute(30)->by($request->user()?->id ?: $request->ip())
        );

        RateLimiter::for('log-events', fn ($request) =>
        Limit::perMinute(120)->by($request->user()?->id ?: $request->ip())
        );

        RateLimiter::for('heartbeat', fn ($request) =>
        Limit::perMinute(20)->by($request->user()?->id ?: $request->ip())
        );
    }
}
