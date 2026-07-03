<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\AiCabinetAnalyzer;

use App\Http\Controllers\Api\Subscriber\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerAiAnalysesController as ApiAiAnalysesController;
use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresAiCabinetAnalyzerOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\RegenerateAiCabinetAnalyzerAiAnalysisRequest;
use App\Http\Requests\Web\Subscriber\StartAiCabinetAnalyzerAiAnalysisRequest;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerAiAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AiAnalysesController extends SubscriberToolController
{
    use EnsuresAiCabinetAnalyzerOwnership;

    public function __construct(
        private readonly ApiAiAnalysesController $apiAiAnalysesController,
    ) {
    }

    public function start(StartAiCabinetAnalyzerAiAnalysisRequest $request): RedirectResponse
    {
        $response = $this->apiAiAnalysesController->start($request);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось запустить ИИ-анализ'));
        }

        return back()->with('success', $this->apiMessage($payload, 'ИИ-анализ запущен'));
    }

    public function regenerate(RegenerateAiCabinetAnalyzerAiAnalysisRequest $request, AiCabinetAnalyzerAiAnalysis $analysis): RedirectResponse
    {
        $this->ensureAnalysisOwnership($analysis);

        $response = $this->apiAiAnalysesController->regenerate(
            $request->duplicate(null, $request->validated()),
            (string) $analysis->id
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось перегенерировать анализ'));
        }

        return back()->with('success', $this->apiMessage($payload, 'ИИ-анализ перезапущен'));
    }

    public function show(Request $request, AiCabinetAnalyzerAiAnalysis $analysis): JsonResponse
    {
        $this->ensureAnalysisOwnership($analysis);

        $response = $this->apiAiAnalysesController->show($request, (string) $analysis->id);
        $payload = $this->decodeApiResponse($response);

        return response()->json($payload);
    }

    public function download(Request $request, AiCabinetAnalyzerAiAnalysis $analysis): BinaryFileResponse|RedirectResponse|JsonResponse
    {
        $this->ensureAnalysisOwnership($analysis);

        try {
            return $this->apiAiAnalysesController->download($request, (string) $analysis->id);
        } catch (\Throwable) {
            return back()->with('error', 'Не удалось скачать PDF');
        }
    }
}