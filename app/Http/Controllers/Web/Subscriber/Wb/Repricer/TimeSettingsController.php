<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Repricer;

use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresRepricerCabinetOwnership;
use App\Services\Subscriber\Wb\RepricerTimeSettingsService;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\StoreRepricerTimeSettingRequest;
use App\Http\Requests\Web\Subscriber\UpdateRepricerTimeSettingRequest;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Support\ToolLimits;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TimeSettingsController extends SubscriberToolController
{
    use EnsuresRepricerCabinetOwnership;

    public function __construct(
        private readonly RepricerTimeSettingsService $settingsService,
    ) {
    }

    public function index(Request $request, RepricerCabinets $cabinet): Response
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->settingsService->show((string) $cabinet->id);
        $payload = $this->decodeApiResponse($response);

        $settings = ($payload['success'] ?? false) ? ($payload['data'] ?? []) : [];

        return Inertia::render('Subscriber/Wb/Repricer/Cabinet/Time/Index', [
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
            ],
            'settings' => $this->normalizeRows($settings),
            'settingsError' => ($payload['success'] ?? false) ? null : $this->apiMessage($payload, 'Не удалось загрузить номенклатуру'),
            'limits' => $this->repricerLimits($request),
        ]);
    }

    public function store(StoreRepricerTimeSettingRequest $request, RepricerCabinets $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->settingsService->store(
            $request->duplicate(null, array_merge(
                $request->validated(),
                ['cabinet_id' => $cabinet->id]
            ))
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось добавить номенклатуру'));
        }

        return redirect()
            ->route('subscriber.wb.repricer.cabinets.time.index', $cabinet->id)
            ->with('success', $this->apiMessage($payload, 'Номенклатура добавлена'));
    }

    public function update(
        UpdateRepricerTimeSettingRequest $request,
        RepricerCabinets $cabinet,
        RepricerSettings $setting,
    ): RedirectResponse {
        $this->ensureSettingBelongsToCabinet($setting, $cabinet);

        $response = $this->settingsService->update(
            $request->duplicate(null, $request->validated()),
            (string) $setting->id
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось обновить настройки'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Настройки обновлены'));
    }

    public function destroy(RepricerCabinets $cabinet, RepricerSettings $setting): RedirectResponse
    {
        $this->ensureSettingBelongsToCabinet($setting, $cabinet);

        $response = $this->settingsService->destroy((string) $setting->id);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось удалить номенклатуру'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Номенклатура удалена'));
    }

    /**
     * @param  mixed  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows($rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $item = is_array($row) ? $row : $row->toArray();
            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * @return array<string, int|null>
     */
    private function repricerLimits(Request $request): array
    {
        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $request->user()->subscriber?->id)
            ->where('status', 1)
            ->first();

        return [
            'repricer_nmid' => ToolLimits::planLimitValue($request->user(), $subscription, 'repricer_nmid'),
        ];
    }
}