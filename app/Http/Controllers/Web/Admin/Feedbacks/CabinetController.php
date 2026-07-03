<?php

namespace App\Http\Controllers\Web\Admin\Feedbacks;

use App\Http\Controllers\Api\Admin\services\feedbacks\AdminFeedbacksController as ApiAdminFeedbacksController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FeedbacksCabinetStatsRequest;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Services\Admin\AdminFeedbacksService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class CabinetController extends Controller
{
    public function __construct(
        private readonly AdminFeedbacksService $feedbacksService,
        private readonly ApiAdminFeedbacksController $apiFeedbacksController,
    ) {
    }

    public function index(): Response
    {
        return Inertia::render('Admin/Services/Feedbacks/Cabinets/Index', [
            'cabinets' => $this->feedbacksService->listCabinets(),
        ]);
    }

    public function stats(FeedbacksCabinetStatsRequest $request, FeedbacksClients $cabinet): Response|RedirectResponse
    {
        $response = $this->apiFeedbacksController->cabinetStats($request, (string) $cabinet->id);
        $payload = $this->decodeApiResponse($response);

        if ($response->getStatusCode() === 404 || ($payload['success'] ?? true) === false) {
            return redirect()->route('admin.services.feedbacks.cabinets.index')
                ->with('error', $payload['message'] ?? 'Кабинет не найден');
        }

        return Inertia::render('Admin/Services/Feedbacks/Cabinets/Stats', [
            'cabinet' => $payload['cabinet'] ?? ['id' => $cabinet->id, 'name' => $cabinet->name],
            'stats' => $payload['data'] ?? null,
            'statDate' => $payload['stat_date'] ?? null,
            'availableDates' => $payload['available_dates'] ?? [],
            'filters' => [
                'stat_type' => $request->input('stat_type', 'weekly'),
                'date' => $request->input('date'),
            ],
        ]);
    }

    public function recalculate(FeedbacksClients $cabinet): RedirectResponse
    {
        $response = $this->apiFeedbacksController->recalculateStats((string) $cabinet->id);
        $payload = $this->decodeApiResponse($response);

        if ($response->getStatusCode() === 404) {
            return redirect()->back()->with('error', $payload['message'] ?? 'Кабинет не найден');
        }

        return redirect()->back()->with('success', $payload['message'] ?? 'Статистика пересчитана');
    }

    private function decodeApiResponse(HttpResponse $response): array
    {
        return json_decode($response->getContent(), true) ?? [];
    }
}