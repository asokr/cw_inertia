<?php

namespace App\Http\Controllers\Web\Admin\Feedbacks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FeedbacksCabinetStatsRequest;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Services\Admin\AdminFeedbacksService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CabinetController extends Controller
{
    public function __construct(
        private readonly AdminFeedbacksService $feedbacksService,
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
        $payload = $this->feedbacksService->cabinetStats((string) $cabinet->id, $request->validated());

        if (($payload['status'] ?? 200) === 404 || ($payload['success'] ?? true) === false) {
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
        $payload = $this->feedbacksService->recalculateStats((string) $cabinet->id);

        if (($payload['status'] ?? 200) === 404) {
            return redirect()->back()->with('error', $payload['message'] ?? 'Кабинет не найден');
        }

        return redirect()->back()->with('success', $payload['message'] ?? 'Статистика пересчитана');
    }
}