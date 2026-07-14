<?php

namespace App\Support;

class AnalyticsScripts
{
    public static function shouldLoad(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return false;
        }

        if (request()->is('cw-page', 'cw-page/*')) {
            return false;
        }

        return true;
    }
}