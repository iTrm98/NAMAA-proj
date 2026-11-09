<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Login: 5 attempts per minute per email + IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(config('namaa.login_attempts_per_minute'))
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        // Device readings: per node (the token's node), so one noisy board cannot block the others.
        RateLimiter::for('device', fn (Request $request) => Limit::perMinute(config('namaa.device_readings_per_minute'))
            ->by('node:'.($request->user()?->getKey() ?? $request->ip())));

        // One-click demo sign-in: 10 per minute per IP.
        RateLimiter::for('demo-login', fn (Request $request) => Limit::perMinute(config('namaa.demo_login_per_minute'))
            ->by('demo-login|'.$request->ip()));
    }
}
