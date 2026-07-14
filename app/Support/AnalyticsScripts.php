<?php

namespace App\Support;

class AnalyticsScripts
{
    public static function shouldLoad(): bool
    {
        if (self::isDisabledEnvironment()) {
            return false;
        }

        if (self::isAdminRequest()) {
            return false;
        }

        return true;
    }

    public static function shouldLoadJivo(): bool
    {
        if (! self::shouldLoad()) {
            return false;
        }

        if (self::isSubscriberPanelRequest()) {
            return false;
        }

        return true;
    }

    private static function isDisabledEnvironment(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    private static function isAdminRequest(): bool
    {
        return request()->is('cw-page', 'cw-page/*');
    }

    private static function isSubscriberPanelRequest(): bool
    {
        return request()->is('panel', 'panel/*');
    }
}