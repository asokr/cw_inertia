<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Cabinets;

use App\Http\Controllers\Controller;
use App\Services\Subscriber\Wb\WbCabinetMigrationService;
use App\Services\Subscriber\Wb\WbCabinetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MigrationController extends Controller
{
    public function __construct(
        private readonly WbCabinetMigrationService $migrationService,
        private readonly WbCabinetService $cabinetService,
    ) {
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $this->migrationService->needsMigration($user)) {
            return redirect()->route('subscriber.panel');
        }

        $state = $this->migrationService->wizardState($user);

        return Inertia::render('Subscriber/Wb/Cabinets/Migration', [
            'wizard' => $state,
        ]);
    }

    public function storeCabinet(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'apikey' => ['required', 'string'],
        ], [
            'name.required' => 'Укажите название кабинета',
            'apikey.required' => 'Укажите API-ключ',
        ]);

        try {
            // During migration wizard do not consume plan limits — user is consolidating.
            $result = $this->cabinetService->create($request->user(), $data, enforceLimit: false);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        $redirect = back()->with('success', 'Кабинет создан');
        if ($result['permission_warnings'] !== []) {
            $redirect->with('success_details', implode(' ', $result['permission_warnings']));
        }

        return $redirect;
    }

    public function run(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'assignments' => ['nullable', 'array'],
            'assignments.*.wb_cabinet_id' => ['required', 'integer'],
            'assignments.*.mappings' => ['nullable', 'array'],
            'assignments.*.mappings.*.service' => ['required', 'string'],
            'assignments.*.mappings.*.old_cabinet_id' => ['required', 'integer'],
            'deletions' => ['nullable', 'array'],
            'deletions.*.service' => ['required', 'string'],
            'deletions.*.old_cabinet_id' => ['required', 'integer'],
        ]);

        try {
            $this->migrationService->migrate(
                $request->user(),
                $data['assignments'] ?? [],
                $data['deletions'] ?? []
            );
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()
            ->route('subscriber.panel')
            ->with('success', 'Перенос кабинетов завершён');
    }
}
