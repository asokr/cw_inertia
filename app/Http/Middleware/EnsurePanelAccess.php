<?php

namespace App\Http\Middleware;

use App\Support\HomeRedirect;
use Closure;
use Illuminate\Http\Request;

class EnsurePanelAccess
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || ! HomeRedirect::canAccessPanel($user)) {
            abort(403);
        }

        return $next($request);
    }
}