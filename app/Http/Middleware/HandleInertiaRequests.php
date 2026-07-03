<?php

namespace App\Http\Middleware;

use App\Services\Admin\AiCostService;
use App\Services\Subscriber\SubscriberContextService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'verified' => $user->hasVerifiedEmail(),
                ] : null,
                'permissions' => $user
                    ? $user->getAllPermissions()->pluck('name')->values()->all()
                    : [],
                'roles' => $user
                    ? $user->getRoleNames()->values()->all()
                    : [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'messages' => fn () => $request->session()->get('messages', []),
                'verification_email' => fn () => $request->session()->get('verification_email'),
            ],
            'aiCostsToday' => function () use ($request, $user) {
                if (! $user || ! $request->is('cw-page', 'cw-page/*')) {
                    return null;
                }

                $isSuperAdmin = $user->hasRole(['Супер-Админ', 'super-admin']) || $user->can('super admin');

                if (! $isSuperAdmin) {
                    return null;
                }

                return app(AiCostService::class)->today();
            },
            'subscriber' => function () use ($request, $user) {
                if (! $user || ! $request->is('panel', 'panel/*')) {
                    return null;
                }

                if (! \App\Support\HomeRedirect::canAccessPanel($user)) {
                    return null;
                }

                return app(SubscriberContextService::class)->forUser($user);
            },
        ];
    }
}
