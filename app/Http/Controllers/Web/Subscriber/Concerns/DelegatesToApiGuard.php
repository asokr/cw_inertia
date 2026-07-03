<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait DelegatesToApiGuard
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withApiGuard(Request $request, callable $callback): mixed
    {
        $user = $request->user();
        Auth::guard('api')->setUser($user);

        try {
            return $callback();
        } finally {
            Auth::guard('api')->forgetUser();
        }
    }
}