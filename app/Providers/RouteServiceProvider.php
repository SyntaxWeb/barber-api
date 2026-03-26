<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            if (!$request->user()) {
                return Limit::perMinute(240)->by($request->ip());
            }

            return Limit::perMinute(60)->by($request->user()->id);
        });

        RateLimiter::for('public-client-read', function (Request $request) {
            return Limit::perMinute(600)->by($request->ip());
        });

        RateLimiter::for('public-company-read', function (Request $request) {
            $companyKey = (string) $request->route('company');

            return Limit::perMinute(1200)->by(sprintf('%s|%s', $request->ip(), $companyKey));
        });

        RateLimiter::for('public-feedback-submit', function (Request $request) {
            $token = (string) $request->route('token');

            return Limit::perMinute(30)->by(sprintf('%s|%s', $request->ip(), $token));
        });

        RateLimiter::for('public-webhook', function (Request $request) {
            return Limit::perMinute(300)->by($request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
