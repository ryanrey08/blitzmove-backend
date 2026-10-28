<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Routing\NullRoutingProvider;
use App\Services\Routing\RoutingProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Swap in a real provider (Google Routes, Mapbox, OSRM...) here once one is configured.
        $this->app->bind(RoutingProvider::class, NullRoutingProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-operations', fn (User $user) => $user->canManageOperations());
        Gate::define('manage-fleet', fn (User $user) => $user->canManageFleet());

        RateLimiter::for('auth', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            ];
        });

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        ResetPassword::createUrlUsing(function (User $user, string $token) {
            return rtrim(config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });
    }
}
