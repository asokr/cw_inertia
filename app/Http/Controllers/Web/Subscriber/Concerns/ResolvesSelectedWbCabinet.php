<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Subscriber\Wb\WbCabinetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

trait ResolvesSelectedWbCabinet
{
    protected function selectedWbCabinet(Request $request): ?WbCabinet
    {
        return app(WbCabinetService::class)->selectedFor($request->user());
    }

    /**
     * @return WbCabinet|Response
     */
    protected function requireSelectedWbCabinet(Request $request, string $toolName, array $breadcrumbs = [])
    {
        $cabinet = $this->selectedWbCabinet($request);

        if ($cabinet) {
            return $cabinet;
        }

        return Inertia::render('Subscriber/Wb/Shared/NoCabinet', [
            'toolName' => $toolName,
            'breadcrumbs' => $breadcrumbs !== [] ? $breadcrumbs : [
                ['label' => 'Главная', 'href' => '/panel'],
                ['label' => $toolName],
            ],
        ]);
    }

    /**
     * @return WbCabinet|JsonResponse
     */
    protected function requireSelectedWbCabinetJson(Request $request)
    {
        $cabinet = $this->selectedWbCabinet($request);

        if ($cabinet) {
            return $cabinet;
        }

        return response()->json([
            'success' => false,
            'messages' => ['Добавьте хотя бы один кабинет Wildberries.'],
        ], 422);
    }
}
