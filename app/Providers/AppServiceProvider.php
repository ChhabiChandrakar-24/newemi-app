<?php

namespace App\Providers;

use App\Contracts\DeviceCommandNotifierInterface;
use App\Contracts\PushProviderInterface;
use App\Models\User;
use App\Services\FirebaseDeviceCommandNotifier;
use App\Services\FirebasePushProvider;
use App\Services\PollingDeviceCommandNotifier;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->bind(DeviceCommandNotifierInterface::class, config('firebase.enabled') ? FirebaseDeviceCommandNotifier::class : PollingDeviceCommandNotifier::class);
        $this->app->bind(PushProviderInterface::class, FirebasePushProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(static function (User $user): ?bool {
            return ($user->hasRole('super-admin') || $user->is_platform_admin) ? true : null;
        });

        RateLimiter::for('login', static function (Request $request): Limit {
            $limit = app()->isProduction() ? 5 : 60;
            return Limit::perMinute($limit)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });
        RateLimiter::for('device-enrollment', static fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('device-heartbeat', static fn (Request $request): Limit => Limit::perMinute(120)->by(
            'device:'.($request->attributes->get('device')?->getKey() ?? $request->ip()),
        ));
        RateLimiter::for('device-commands', static fn (Request $request): Limit => Limit::perMinute(120)->by(
            'device:'.($request->attributes->get('device')?->getKey() ?? $request->ip()),
        ));
        RateLimiter::for('device-location', static fn (Request $request): Limit => Limit::perHour(24)->by('device:'.($request->attributes->get('device')?->getKey() ?? $request->ip())));
        RateLimiter::for('device-consent', static fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
    }
}
