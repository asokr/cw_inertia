<?php

namespace App\Services\Subscriber\Wb;
use App\Jobs\Wb\AiCabinetAnalyzer\ProcessAiCabinetAnalyzerAiAnalysisJob;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerTemplate;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerReport;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerAiAnalysis;
use App\Services\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerPdfGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WbAiCabinetAnalyzerAiAnalysesService
{
    public function start(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'report_id' => 'required|integer|exists:wb_ai_cabinet_analyzer_reports,id',
            'template_id' => 'required|integer|exists:wb_ai_cabinet_analyzer_templates,id',
            'model' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $report = AiCabinetAnalyzerReport::with('cabinet')->find((int) $request->input('report_id'));
        if (!$report || !$report->cabinet || (int) $report->cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['Отчёт не найден'],
            ], 200);
        }

        if ((string) $report->status !== AiCabinetAnalyzerReport::STATUS_DONE) {
            return response()->json([
                'success' => false,
                'messages' => ['Запуск ИИ-анализа доступен только для отчётов со статусом done'],
            ], 200);
        }

        $template = AiCabinetAnalyzerTemplate::find((int) $request->input('template_id'));
        if (!$template || !$template->is_active) {
            return response()->json([
                'success' => false,
                'messages' => ['Шаблон анализа не найден или отключен'],
            ], 200);
        }

        $existing = AiCabinetAnalyzerAiAnalysis::where('report_id', (int) $report->id)
            ->where('template_id', (int) $template->id)
            ->where('status', AiCabinetAnalyzerAiAnalysis::STATUS_DONE)
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'messages' => ['Возвращён ранее сформированный ИИ-анализ'],
                'data' => $this->analysisPayload($existing->load('template')),
            ], 200);
        }

        $analysis = AiCabinetAnalyzerAiAnalysis::create([
            'report_id' => (int) $report->id,
            'template_id' => (int) $template->id,
            'status' => AiCabinetAnalyzerAiAnalysis::STATUS_PROCESSING,
            'model' => (string) ($request->input('model') ?: 'gemini'),
        ]);

        ProcessAiCabinetAnalyzerAiAnalysisJob::dispatch((int) $analysis->id, (int) $request->user()->id)
            ->onQueue('wb_ai_cabinet_analyzer');

        return response()->json([
            'success' => true,
            'messages' => ['ИИ-анализ запущен'],
            'data' => $this->analysisPayload($analysis->load('template')),
        ], 200);
    }

    public function regenerate(Request $request, string $analysis)
    {
        $validator = Validator::make(array_merge($request->all(), ['analysis' => $analysis]), [
            'analysis' => 'required|integer|exists:wb_ai_cabinet_analyzer_ai_analyses,id',
            'model' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $entry = AiCabinetAnalyzerAiAnalysis::with(['template', 'report.cabinet'])->find((int) $analysis);
        if (!$entry || !$entry->report || !$entry->report->cabinet || (int) $entry->report->cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['ИИ-анализ не найден'],
            ], 200);
        }

        if ((string) $entry->status === AiCabinetAnalyzerAiAnalysis::STATUS_PROCESSING) {
            return response()->json([
                'success' => false,
                'messages' => ['ИИ-анализ уже выполняется'],
            ], 200);
        }

        if ((string) $entry->report->status !== AiCabinetAnalyzerReport::STATUS_DONE) {
            return response()->json([
                'success' => false,
                'messages' => ['Перегенерация доступна только для отчётов со статусом done'],
            ], 200);
        }

        $template = $entry->template;
        if (!$template || !$template->is_active) {
            return response()->json([
                'success' => false,
                'messages' => ['Шаблон анализа не найден или отключен'],
            ], 200);
        }

        DB::transaction(function () use ($entry, $request): void {
            $entry->status = AiCabinetAnalyzerAiAnalysis::STATUS_PROCESSING;
            $entry->model = (string) ($request->input('model') ?: $entry->model ?: 'gemini');
            $entry->analysis_json = null;
            $entry->analysis_text = null;
            $entry->analysis_markdown = null;
            $entry->input_tokens = 0;
            $entry->output_tokens = 0;
            $entry->total_tokens = 0;
            $entry->error_message = null;
            $entry->started_at = null;
            $entry->finished_at = null;
            $entry->save();
        });

        ProcessAiCabinetAnalyzerAiAnalysisJob::dispatch((int) $entry->id, (int) $request->user()->id)
            ->onQueue('wb_ai_cabinet_analyzer');

        return response()->json([
            'success' => true,
            'messages' => ['ИИ-анализ перезапущен'],
            'data' => $this->analysisPayload($entry->fresh()->load('template')),
        ], 200);
    }

    public function indexByReport(Request $request, string $report)
    {
        $validator = Validator::make(['report' => $report], [
            'report' => 'required|integer|exists:wb_ai_cabinet_analyzer_reports,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $reportModel = AiCabinetAnalyzerReport::with('cabinet')->find((int) $report);
        if (!$reportModel || !$reportModel->cabinet || (int) $reportModel->cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['Отчёт не найден'],
            ], 200);
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 15)));

        $items = AiCabinetAnalyzerAiAnalysis::with('template')
            ->where('report_id', (int) $reportModel->id)
            ->orderByDesc('id')
            ->paginate($perPage);

        $items->setCollection($items->getCollection()->map(fn(AiCabinetAnalyzerAiAnalysis $item) => $this->analysisPayload($item)));

        return response()->json([
            'success' => true,
            'messages' => ['Список ИИ-анализов отчёта'],
            'data' => $items,
        ], 200);
    }

    public function show(Request $request, string $analysis)
    {
        $validator = Validator::make(['analysis' => $analysis], [
            'analysis' => 'required|integer|exists:wb_ai_cabinet_analyzer_ai_analyses,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $entry = AiCabinetAnalyzerAiAnalysis::with(['template', 'report.cabinet'])->find((int) $analysis);
        if (!$entry || !$entry->report || !$entry->report->cabinet || (int) $entry->report->cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['ИИ-анализ не найден'],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'messages' => ['Данные ИИ-анализа'],
            'data' => $this->analysisPayload($entry),
        ], 200);
    }

    public function templates()
    {
        $templates = AiCabinetAnalyzerTemplate::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'description', 'sort_order', 'is_active', 'response_format', 'created_at', 'updated_at']);

        return response()->json([
            'success' => true,
            'messages' => ['Список шаблонов ИИ-анализа'],
            'data' => $templates,
        ], 200);
    }

    private function analysisPayload(AiCabinetAnalyzerAiAnalysis $analysis): array
    {
        $template = $analysis->template;
        $responseFormat = $template?->response_format ?? 'json';

        $payload = [
            'id' => (int) $analysis->id,
            'report_id' => (int) $analysis->report_id,
            'template_id' => (int) $analysis->template_id,
            'template' => $template ? [
                'id' => (int) $template->id,
                'name' => (string) $template->name,
                'description' => (string) ($template->description ?? ''),
            ] : null,
            'status' => (string) $analysis->status,
            'response_format' => (string) $responseFormat,
            'input_tokens' => (int) ($analysis->input_tokens ?? 0),
            'output_tokens' => (int) ($analysis->output_tokens ?? 0),
            'total_tokens' => (int) ($analysis->total_tokens ?? 0),
            'started_at' => $analysis->started_at,
            'finished_at' => $analysis->finished_at,
            'error_message' => (string) ($analysis->error_message ?? ''),
            'created_at' => $analysis->created_at,
            'updated_at' => $analysis->updated_at,
        ];

        if ($responseFormat === 'markdown') {
            $payload['analysis_markdown'] = (string) ($analysis->analysis_markdown ?? '');
            // For markdown we do not populate the old analysis_text (keep current structure for json)
        } else {
            // Keep exact previous structure for json
            $payload['analysis_text'] = $this->decodeAnalysisText((string) ($analysis->analysis_text ?? ''));
        }

        return $payload;
    }

    private function decodeAnalysisText(string $analysisText): mixed
    {
        $trimmed = trim($analysisText);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        if (str_starts_with($trimmed, '```')) {
            $clean = preg_replace('/^```[a-zA-Z]*\s*/', '', $trimmed) ?? $trimmed;
            $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
            $clean = trim($clean);

            $decoded = json_decode($clean, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return [
            'raw' => $analysisText,
        ];
    }

    public function download(Request $request, string $analysis)
    {
        $validator = Validator::make(['analysis' => $analysis], [
            'analysis' => 'required|integer|exists:wb_ai_cabinet_analyzer_ai_analyses,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $entry = AiCabinetAnalyzerAiAnalysis::with(['template', 'report.cabinet'])->find((int)$analysis);

        if (!$entry || !$entry->report || !$entry->report->cabinet || (int)$entry->report->cabinet->user_id !== (int)$request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['ИИ-анализ не найден или нет доступа'],
            ], 404);
        }

        if ((string)$entry->status !== AiCabinetAnalyzerAiAnalysis::STATUS_DONE) {
            return response()->json([
                'success' => false,
                'messages' => ['Скачивание доступно только для завершённых анализов'],
            ], 400);
        }

        $generator = app(AiCabinetAnalyzerPdfGenerator::class);
        $filePath = $generator->generate($entry);

        $filename = 'AI_Analysis_' . $entry->id . '_' . now()->format('Ymd_His') . '.pdf';

        return response()->download($filePath, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }
}
