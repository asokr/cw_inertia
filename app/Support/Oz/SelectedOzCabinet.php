<?php

namespace App\Support\Oz;

use App\Models\Subscribers\Oz\OzCabinet;
use App\Models\User;
use App\Services\Subscriber\Oz\OzCabinetService;

class SelectedOzCabinet
{
    public static function for(?User $user): ?OzCabinet
    {
        if (! $user) {
            return null;
        }

        return app(OzCabinetService::class)->selectedFor($user);
    }

    public static function requireFor(?User $user): OzCabinet
    {
        $cabinet = self::for($user);
        if (! $cabinet) {
            abort(422, 'Добавьте хотя бы один кабинет Ozon.');
        }

        return $cabinet;
    }
}
