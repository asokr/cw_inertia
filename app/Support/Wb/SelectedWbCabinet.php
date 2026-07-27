<?php

namespace App\Support\Wb;

use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\User;
use App\Services\Subscriber\Wb\WbCabinetService;

class SelectedWbCabinet
{
    public static function for(?User $user): ?WbCabinet
    {
        if (! $user) {
            return null;
        }

        return app(WbCabinetService::class)->selectedFor($user);
    }

    public static function requireFor(?User $user): WbCabinet
    {
        $cabinet = self::for($user);
        if (! $cabinet) {
            abort(422, 'Добавьте хотя бы один кабинет Wildberries.');
        }

        return $cabinet;
    }
}
