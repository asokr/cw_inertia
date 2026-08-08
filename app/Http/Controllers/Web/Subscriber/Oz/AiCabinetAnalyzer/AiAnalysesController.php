<?php

namespace App\Http\Controllers\Web\Subscriber\Oz\AiCabinetAnalyzer;

use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresOzAiCabinetAnalyzerOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\RegenerateAiCabinetAnalyzerAiAnalysisRequest;
use App\Http\Requests\Web\Subscriber\StartOzAiCabinetAnalyzerAiAnalysisRequest;
use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerAiAnalysis;
use App\Services\Subscriber\Oz\OzAiCabinetAnalyzerAiAnalysesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AiAnalysesController extends SubscriberToolController
{
    use EnsuresOzAiCabinetAnalyzerOwnership;

    public function __construct(
        private readonly OzAiCabinetAnalyzerAiAnalysesService $aiAnalysesService,
    ) {
    }

    public function start(StartOzAiCabinetAnalyzerAiAnalysisRequest $request): RedirectResponse
    {
        $response = $this->aiAnalysesService->start($request);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось запустить ИИ-анализ'));
        }

        return back()->with('success', $this->apiMessage($payload, 'ИИ-анализ запущен'));
    }

    public function regenerate(RegenerateAiCabinetAnalyzerAiAnalysisRequest $request, OzAiCabinetAnalyzerAiAnalysis $analysis): RedirectResponse
    {
        $this->ensureAnalysisOwnership($analysis);

        $response = $this->aiAnalysesService->regenerate(
            $request->duplicate(null, $request->validated()),
            (string) $analysis->id
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось перегенерировать анализ'));
        }

        return back()->with('success', $this->apiMessage($payload, 'ИИ-анализ перезапущен'));
    }

    public function show(Request $request, OzAiCabinetAnalyzerAiAnalysis $analysis): JsonResponse
    {
        $this->ensureAnalysisOwnership($analysis);

        $response = $this->aiAnalysesService->show($request, (string) $analysis->id);
        $payload = $this->decodeApiResponse($response);

        return response()->json($payload);
    }

    public function download(Request $request, OzAiCabinetAnalyzerAiAnalysis $analysis): BinaryFileResponse|RedirectResponse|JsonResponse
    {
        $this->ensureAnalysisOwnership($analysis);

        try {
            return $this->aiAnalysesService->download($request, (string) $analysis->id);
        } catch (\Throwable) {
            return back()->with('error', 'Не удалось скачать PDF');
        }
    }
}
