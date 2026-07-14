<?php

namespace App\Providers;

use App\Models\User;
use App\Support\HomeRedirect;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;


class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Gate::before(function (User $user, string $ability) {
            if (HomeRedirect::hasFullAdminAccess($user)) {
                return true;
            }

            if (HomeRedirect::hasPanelAdminAccess($user) && self::isPanelAbility($ability)) {
                return true;
            }

            return null;
        });

    }

    private static function isPanelAbility(string $ability): bool
    {
        return str_starts_with($ability, 'subscriber');
    }
}
