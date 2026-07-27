<?php

namespace App\Http\Middleware;

use App\Services\Subscriber\Wb\WbCabinetMigrationService;
use Closure;
use Illuminate\Http\Request;

class EnsureWbCabinetsMigrated
{
    public function __construct(
        private readonly WbCabinetMigrationService $migrationService,
    ) {
    }

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse|\Inertia\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (! $this->migrationService->needsMigration($user)) {
            return $next($request);
        }

        if ($this->isAllowedDuringMigration($request)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => false,
                'messages' => ['Необходимо завершить обновление кабинетов.'],
                'migration_required' => true,
            ], 423);
        }

        return redirect()->route('subscriber.wb.cabinets.migration');
    }

    private function isAllowedDuringMigration(Request $request): bool
    {
        if ($request->routeIs([
            'subscriber.wb.cabinets.migration',
            'subscriber.wb.cabinets.migration.*',
            'subscriber.wb.cabinets.store',
            'logout',
        ])) {
            return true;
        }

        // Allow inertia shared asset requests and verification if any slip through.
        $path = trim($request->path(), '/');

        return str_starts_with($path, 'panel/wb/cabinets/migration')
            || str_starts_with($path, 'panel/wb/cabinets');
    }
}
