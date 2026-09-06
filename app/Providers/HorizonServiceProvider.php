<?php

namespace App\Providers;

use App\Domain\Access\Roles;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // داشبورد Horizon صف‌ها و payload کارها را نشان می‌دهد؛ فقط مدیر ارشد
        // سامانه. لیست ایمیل دستی در کد، همان چیزی است که موقع تغییر کارکنان
        // فراموش می‌شود.
        Gate::define('viewHorizon', function (?User $user = null) {
            return $user?->hasRole(Roles::SUPER_ADMIN) ?? false;
        });
    }
}
