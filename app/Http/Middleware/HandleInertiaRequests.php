<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Driver;
use App\Models\Factory;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        // صریحاً نام گارد را می‌دهیم: گارد پیش‌فرض ممکن است در جریان راننده
        // عوض شده باشد و آن‌وقت user() یک Driver برمی‌گرداند.
        /** @var User|null $user */
        $user = $request->user('web');

        /** @var Driver|null $driver */
        $driver = $request->user('driver');

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ] : null,
                'driver' => $driver ? [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'mobile' => $driver->mobile,
                ] : null,
            ],
            'factory' => fn () => $this->factoryProps($user?->factory),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'otp' => fn () => $request->session()->get('otp'),
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function factoryProps(?Factory $factory): ?array
    {
        $factory ??= Factory::where('is_active', true)->orderBy('id')->first();

        if ($factory === null) {
            return null;
        }

        return [
            'id' => $factory->id,
            'name' => $factory->name,
            'slug' => $factory->slug,
            'phone' => $factory->phone,
            'logo' => $factory->logo_path,
        ];
    }
}
