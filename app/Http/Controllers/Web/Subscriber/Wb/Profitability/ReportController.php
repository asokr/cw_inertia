<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Profitability;

use App\Http\Controllers\Api\Subscriber\Wb\Profitability\ProfitabilityController as ApiProfitabilityController;
use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresWbProfitabilityCabinetOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\StoreProfitabilityReportRequest;
use App\Models\Subscribers\Wb\Profitability\ProfitabilityCabinet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends SubscriberToolController
{
    use EnsuresWbProfitabilityCabinetOwnership;

    public function __construct(
        private readonly ApiProfitabilityController $apiProfitabilityController,
    ) {
    }

    public function show(Request $request, ProfitabilityCabinet $cabinet): Response
    {
        $this->ensureCabinetOwnership($cabinet);

        $statusPayload = $this->decodeApiResponse(
            $this->apiProfitabilityController->status($request, $cabinet->id)
        );
        $jobStatus = $this->buildJobStatus($statusPayload);

        $reportPayload = $this->decodeApiResponse(
            $this->apiProfitabilityController->show($request, $cabinet->id)
        );

        $report = null;
        $groups = [];

        if (($reportPayload['success'] ?? false) === true) {
            $reportData = $reportPayload['data'] ?? null;

            if (is_array($reportData)) {
                $report = $reportData['report'] ?? null;
                $groups = $this->normalizeReportGroups($reportData['items'] ?? []);

                if ($report !== null) {
                    $jobStatus = array_merge($jobStatus, array_filter([
                        'stage' => $reportData['stage'] ?? null,
                        'batch' => $reportData['batch'] ?? null,
                        'rows_loaded' => $reportData['rows_loaded'] ?? null,
                        'waiting_for_api' => $reportData['waiting_for_api'] ?? null,
                        'started_at' => $reportData['started_at'] ?? null,
                        'progress_percent' => $reportData['progress_percent'] ?? null,
                        'status_label' => $reportData['status_label'] ?? null,
                        'status_detail' => $reportData['status_detail'] ?? null,
                    ], fn ($value) => $value !== null));

                    if (isset($reportData['status'])) {
                        $jobStatus['status'] = $reportData['status'];
                    }

                    if (array_key_exists('error', $reportData)) {
                        $jobStatus['error'] = $reportData['error'];
                    }
                }
            }
        } else {
            session()->flash('error', $this->apiMessage($reportPayload, 'Не удалось загрузить отчёт'));
        }

        $widgetPayload = $this->decodeApiResponse(
            $this->apiProfitabilityController->widget($request, $cabinet->id)
        );
        $widget = (($widgetPayload['success'] ?? false) === true)
            ? ($widgetPayload['data'] ?? null)
            : null;

        return Inertia::render('Subscriber/Wb/Profitability/Cabinet/Show', [
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
            ],
            'jobStatus' => $jobStatus,
            'report' => $report,
            'groups' => $groups,
            'widget' => $widget,
            'exportUrl' => route('subscriber.wb.profitability.cabinets.export', $cabinet),
        ]);
    }

    public function store(StoreProfitabilityReportRequest $request, ProfitabilityCabinet $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->apiProfitabilityController->store(
            $this->apiRequestWith($request, array_merge(
                $request->validated(),
                ['cabinet_id' => $cabinet->id]
            ))
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось запустить формирование отчёта'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Обновление поставлено в очередь'));
    }

    public function export(Request $request, ProfitabilityCabinet $cabinet): StreamedResponse|RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        try {
            return $this->apiProfitabilityController->exportXlsx($request, $cabinet);
        } catch (\Throwable) {
            return back()->with('error', 'Не удалось скачать отчёт');
        }
    }

    /**
     * @param  array<string, mixed>  $statusPayload
     * @return array{status: string, error: string|null}
     */
    /**
     * @param  mixed  $groups
     * @return list<array{supplier_oper_name: string, items: list<array<string, mixed>>}>
     */
    private function normalizeReportGroups(mixed $groups): array
    {
        if (! is_array($groups) || $groups === []) {
            return [];
        }

        if (array_is_list($groups)) {
            return array_values(array_map(function (mixed $group): array {
                if (! is_array($group)) {
                    return ['supplier_oper_name' => '', 'items' => []];
                }

                return [
                    'supplier_oper_name' => (string) ($group['supplier_oper_name'] ?? ''),
                    'items' => array_values(is_array($group['items'] ?? null) ? $group['items'] : []),
                ];
            }, $groups));
        }

        $normalized = [];

        foreach ($groups as $key => $group) {
            if (! is_array($group)) {
                continue;
            }

            if (isset($group['supplier_oper_name'], $group['items']) && is_array($group['items'])) {
                $normalized[] = [
                    'supplier_oper_name' => (string) $group['supplier_oper_name'],
                    'items' => array_values($group['items']),
                ];

                continue;
            }

            $normalized[] = [
                'supplier_oper_name' => is_string($key) ? $key : (string) ($group['supplier_oper_name'] ?? ''),
                'items' => array_values($group),
            ];
        }

        return $normalized;
    }

    private function buildJobStatus(array $statusPayload): array
    {
        if (($statusPayload['success'] ?? false) === true) {
            $data = $statusPayload['data'] ?? [];

            return [
                'status' => (string) ($data['status'] ?? 'done'),
                'error' => $data['error'] ?? null,
                'stage' => $data['stage'] ?? null,
                'batch' => isset($data['batch']) ? (int) $data['batch'] : null,
                'rows_loaded' => isset($data['rows_loaded']) ? (int) $data['rows_loaded'] : null,
                'waiting_for_api' => (bool) ($data['waiting_for_api'] ?? false),
                'started_at' => $data['started_at'] ?? null,
                'progress_percent' => isset($data['progress_percent']) ? (int) $data['progress_percent'] : null,
                'status_label' => $data['status_label'] ?? null,
                'status_detail' => $data['status_detail'] ?? null,
            ];
        }

        return [
            'status' => 'done',
            'error' => null,
            'stage' => null,
            'batch' => null,
            'rows_loaded' => null,
            'waiting_for_api' => false,
            'started_at' => null,
            'progress_percent' => null,
            'status_label' => null,
            'status_detail' => null,
        ];
    }
}