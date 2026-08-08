<?php

namespace App\Services\Subscriber\Oz;
use App\Jobs\Oz\AiCabinetAnalyzer\ProcessOzAiCabinetAnalyzerAiAnalysisJob;
use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerTemplate;
use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerReport;
use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerAiAnalysis;
use App\Services\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerPdfGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OzAiCabinetAnalyzerAiAnalysesService
{
    public function start(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'report_id' => 'required|integer|exists:oz_ai_cabinet_analyzer_reports,id',
            'template_id' => 'required|integer|exists:oz_ai_cabinet_analyzer_templates,id',
            'model' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $report = OzAiCabinetAnalyzerReport::with('cabinet')->find((int) $request->input('report_id'));
        if (!$report || !$report->cabinet || (int) $report->cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['Отчёт не найден'],
            ], 200);
        }

        if ((string) $report->status !== OzAiCabinetAnalyzerReport::STATUS_DONE) {
            return response()->json([
                'success' => false,
                'messages' => ['Запуск ИИ-анализа доступен только для отчётов со статусом done'],
            ], 200);
        }

        $template = OzAiCabinetAnalyzerTemplate::find((int) $request->input('template_id'));
        if (!$template || !$template->is_active) {
            return response()->json([
                'success' => false,
                'messages' => ['Шаблон анализа не найден или отключен'],
            ], 200);
        }

        $reportId = (int) $report->id;
        $templateId = (int) $template->id;

        // One in-flight analysis per report+template (same data sample + same analysis type).
        return DB::transaction(function () use ($request, $reportId, $templateId) {
            $existingForPair = OzAiCabinetAnalyzerAiAnalysis::query()
                ->where('report_id', $reportId)
                ->where('template_id', $templateId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            $processing = $existingForPair->first(
                static fn (OzAiCabinetAnalyzerAiAnalysis $row): bool => (string) $row->status === OzAiCabinetAnalyzerAiAnalysis::STATUS_PROCESSING
            );

            if ($processing) {
                return response()->json([
                    'success' => false,
                    'messages' => ['Этот анализ уже выполняется для текущей выборки данных. Дождитесь завершения.'],
                    'data' => $this->analysisPayload($processing->load('template')),
                ], 200);
            }

            $existingDone = $existingForPair->first(
                static fn (OzAiCabinetAnalyzerAiAnalysis $row): bool => (string) $row->status === OzAiCabinetAnalyzerAiAnalysis::STATUS_DONE
            );

            if ($existingDone) {
                return response()->json([
                    'success' => true,
                    'messages' => ['Возвращён ранее сформированный ИИ-анализ'],
                    'data' => $this->analysisPayload($existingDone->load('template')),
                ], 200);
            }

            $analysis = OzAiCabinetAnalyzerAiAnalysis::create([
                'report_id' => $reportId,
                'template_id' => $templateId,
                'status' => OzAiCabinetAnalyzerAiAnalysis::STATUS_PROCESSING,
                'model' => (string) ($request->input('model') ?: 'gemini'),
            ]);

            ProcessOzAiCabinetAnalyzerAiAnalysisJob::dispatch((int) $analysis->id, (int) $request->user()->id)
                ->onQueue('oz_ai_cabinet_analyzer');

            return response()->json([
                'success' => true,
                'messages' => ['ИИ-анализ запущен'],
                'data' => $this->analysisPayload($analysis->load('template')),
            ], 200);
        });
    }

    public function regenerate(Request $request, string $analysis)
    {
        $validator = Validator::make(array_merge($request->all(), ['analysis' => $analysis]), [
            'analysis' => 'required|integer|exists:oz_ai_cabinet_analyzer_ai_analyses,id',
            'model' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $entry = OzAiCabinetAnalyzerAiAnalysis::with(['template', 'report.cabinet'])->find((int) $analysis);
        if (!$entry || !$entry->report || !$entry->report->cabinet || (int) $entry->report->cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['ИИ-анализ не найден'],
            ], 200);
        }

        if ((string) $entry->report->status !== OzAiCabinetAnalyzerReport::STATUS_DONE) {
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

        $result = DB::transaction(function () use ($entry, $request) {
            $locked = OzAiCabinetAnalyzerAiAnalysis::query()
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return response()->json([
                    'success' => false,
                    'messages' => ['ИИ-анализ не найден'],
                ], 200);
            }

            if ((string) $locked->status === OzAiCabinetAnalyzerAiAnalysis::STATUS_PROCESSING) {
                return response()->json([
                    'success' => false,
                    'messages' => ['ИИ-анализ уже выполняется'],
                ], 200);
            }

            $siblingProcessing = OzAiCabinetAnalyzerAiAnalysis::query()
                ->where('report_id', (int) $locked->report_id)
                ->where('template_id', (int) $locked->template_id)
                ->where('status', OzAiCabinetAnalyzerAiAnalysis::STATUS_PROCESSING)
                ->where('id', '!=', (int) $locked->id)
                ->lockForUpdate()
                ->exists();

            if ($siblingProcessing) {
                return response()->json([
                    'success' => false,
                    'messages' => ['Этот анализ уже выполняется для текущей выборки данных. Дождитесь завершения.'],
                ], 200);
            }

            $locked->status = OzAiCabinetAnalyzerAiAnalysis::STATUS_PROCESSING;
            $locked->model = (string) ($request->input('model') ?: $locked->model ?: 'gemini');
            $locked->analysis_json = null;
            $locked->analysis_text = null;
            $locked->analysis_markdown = null;
            $locked->input_tokens = 0;
            $locked->output_tokens = 0;
            $locked->total_tokens = 0;
            $locked->error_message = null;
            $locked->started_at = null;
            $locked->finished_at = null;
            $locked->save();

            ProcessOzAiCabinetAnalyzerAiAnalysisJob::dispatch((int) $locked->id, (int) $request->user()->id)
                ->onQueue('oz_ai_cabinet_analyzer');

            return response()->json([
                'success' => true,
                'messages' => ['ИИ-анализ перезапущен'],
                'data' => $this->analysisPayload($locked->load('template')),
            ], 200);
        });

        return $result;
    }

    public function indexByReport(Request $request, string $report)
    {
        $validator = Validator::make(['report' => $report], [
            'report' => 'required|integer|exists:oz_ai_cabinet_analyzer_reports,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $reportModel = OzAiCabinetAnalyzerReport::with('cabinet')->find((int) $report);
        if (!$reportModel || !$reportModel->cabinet || (int) $reportModel->cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['Отчёт не найден'],
            ], 200);
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 15)));

        $items = OzAiCabinetAnalyzerAiAnalysis::with('template')
            ->where('report_id', (int) $reportModel->id)
            ->orderByDesc('id')
            ->paginate($perPage);

        $items->setCollection($items->getCollection()->map(fn(OzAiCabinetAnalyzerAiAnalysis $item) => $this->analysisPayload($item)));

        return response()->json([
            'success' => true,
            'messages' => ['Список ИИ-анализов отчёта'],
            'data' => $items,
        ], 200);
    }

    public function show(Request $request, string $analysis)
    {
        $validator = Validator::make(['analysis' => $analysis], [
            'analysis' => 'required|integer|exists:oz_ai_cabinet_analyzer_ai_analyses,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $entry = OzAiCabinetAnalyzerAiAnalysis::with(['template', 'report.cabinet'])->find((int) $analysis);
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
        $templates = OzAiCabinetAnalyzerTemplate::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'description', 'sort_order', 'is_active', 'response_format', 'data_sources', 'created_at', 'updated_at'])
            ->map(static function (OzAiCabinetAnalyzerTemplate $template): array {
                return [
                    'id' => (int) $template->id,
                    'name' => (string) $template->name,
                    'description' => (string) ($template->description ?? ''),
                    'sort_order' => (int) $template->sort_order,
                    'is_active' => (bool) $template->is_active,
                    'response_format' => (string) ($template->response_format ?? 'json'),
                    'data_sources' => $template->resolvedDataSources(),
                    'created_at' => $template->created_at,
                    'updated_at' => $template->updated_at,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'messages' => ['Список шаблонов ИИ-анализа'],
            'data' => $templates,
        ], 200);
    }

    private function analysisPayload(OzAiCabinetAnalyzerAiAnalysis $analysis): array
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
            'analysis' => 'required|integer|exists:oz_ai_cabinet_analyzer_ai_analyses,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $entry = OzAiCabinetAnalyzerAiAnalysis::with(['template', 'report.cabinet'])->find((int)$analysis);

        if (!$entry || !$entry->report || !$entry->report->cabinet || (int)$entry->report->cabinet->user_id !== (int)$request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['ИИ-анализ не найден или нет доступа'],
            ], 404);
        }

        if ((string)$entry->status !== OzAiCabinetAnalyzerAiAnalysis::STATUS_DONE) {
            return response()->json([
                'success' => false,
                'messages' => ['Скачивание доступно только для завершённых анализов'],
            ], 400);
        }

        $generator = app(OzAiCabinetAnalyzerPdfGenerator::class);
        $filePath = $generator->generate($entry);

        $filename = 'AI_Analysis_' . $entry->id . '_' . now()->format('Ymd_His') . '.pdf';

        return response()->download($filePath, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }
}
