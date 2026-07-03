<?php

namespace App\Support;

use App\Models\User;

class HomeRedirect
{
    public static function forUser(?User $user): string
    {
        if (! $user) {
            return '/login';
        }

        if ($user->hasRole('Подписчик')) {
            return '/panel';
        }

        if (self::isAdmin($user)) {
            return '/cw-page';
        }

        return '/login';
    }

    public static function afterLogin(User $user): string
    {
        $intended = session()->pull('url.intended');

        if (is_string($intended) && self::canAccessIntended($user, $intended)) {
            return $intended;
        }

        return self::forUser($user);
    }

    public static function isAdmin(User $user): bool
    {
        return self::hasFullAdminAccess($user) || self::hasPanelAdminAccess($user);
    }

    public static function hasFullAdminAccess(User $user): bool
    {
        if ($user->hasRole(['Супер-Админ', 'super-admin'])) {
            return true;
        }

        return $user->getAllPermissions()->pluck('name')->contains('super admin');
    }

    public static function hasPanelAdminAccess(User $user): bool
    {
        if (self::hasFullAdminAccess($user)) {
            return true;
        }

        $permissions = $user->getAllPermissions()->pluck('name');

        return $permissions->contains('blog.view')
            || $permissions->contains('administrator');
    }

    public static function canAccessPanel(User $user): bool
    {
        return $user->hasRole('Подписчик') || self::isAdmin($user);
    }

    public static function cabinetLabel(?User $user): string
    {
        if (! $user) {
            return 'В кабинет';
        }

        if ($user->hasRole('Подписчик')) {
            return 'К инструментам';
        }

        if (self::isAdmin($user)) {
            return 'В админку';
        }

        return 'В кабинет';
    }

    private static function canAccessIntended(User $user, string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';

        if ($path === '/dashboard' || str_starts_with($path, '/dashboard/')) {
            return false;
        }

        if (str_starts_with($path, '/panel')) {
            return self::canAccessPanel($user);
        }

        if (str_starts_with($path, '/cw-page')) {
            return self::isAdmin($user);
        }

        if (str_starts_with($path, '/blog') || str_starts_with($path, '/media')) {
            return true;
        }

        return false;
    }
}