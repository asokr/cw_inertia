<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use App\Models\Subscribers\Oz\OzCabinet;
use App\Services\Subscriber\Oz\OzCabinetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

trait ResolvesSelectedOzCabinet
{
    protected function selectedOzCabinet(Request $request): ?OzCabinet
    {
        return app(OzCabinetService::class)->selectedFor($request->user());
    }

    /**
     * @return OzCabinet|Response
     */
    protected function requireSelectedOzCabinet(Request $request, string $toolName, array $breadcrumbs = [])
    {
        $cabinet = $this->selectedOzCabinet($request);

        if ($cabinet) {
            return $cabinet;
        }

        return Inertia::render('Subscriber/Oz/Shared/NoCabinet', [
            'toolName' => $toolName,
            'breadcrumbs' => $breadcrumbs !== [] ? $breadcrumbs : [
                ['label' => 'Главная', 'href' => '/panel'],
                ['label' => $toolName],
            ],
        ]);
    }

    /**
     * @return OzCabinet|JsonResponse
     */
    protected function requireSelectedOzCabinetJson(Request $request)
    {
        $cabinet = $this->selectedOzCabinet($request);

        if ($cabinet) {
            return $cabinet;
        }

        return response()->json([
            'success' => false,
            'messages' => ['Добавьте хотя бы один кабинет Ozon.'],
        ], 422);
    }
}
