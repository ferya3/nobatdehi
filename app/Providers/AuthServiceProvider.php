<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Appointment;
use App\Policies\AppointmentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Appointment::class, AppointmentPolicy::class);
    }
}
