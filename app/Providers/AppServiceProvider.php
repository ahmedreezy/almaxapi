<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
    public function boot(): void
    {
        // Force HTTPS in production
        if ($this->app->environment('production')) {
            URL::forceHttps();
        }

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // General API: 60 requests/minute per IP
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // Auth endpoints: 5 requests/minute per IP — prevents brute force
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip())->response(
                fn () => response()->json(['error' => 'Too many attempts. Please wait 1 minute.'], 429)
            );
        });

        // Subscription creation: 3 per 5 minutes per IP — prevents spam
        RateLimiter::for('subscription', function (Request $request) {
            return Limit::perMinutes(5, 3)->by($request->ip())->response(
                fn () => response()->json(['error' => 'Too many subscription requests. Please wait.'], 429)
            );
        });
    }
}
