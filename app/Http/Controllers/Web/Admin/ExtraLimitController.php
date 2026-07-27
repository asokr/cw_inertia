<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreExtraLimitRequest;
use App\Http\Requests\Admin\UpdateExtraLimitRequest;
use App\Models\ExtraLimits;
use App\Services\Admin\AdminExtraLimitService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ExtraLimitController extends Controller
{
    public function __construct(private readonly AdminExtraLimitService $extraLimitService)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Admin/ExtraLimits/Index', [
            'extraLimits' => $this->extraLimitService->all(),
        ]);
    }

    public function store(StoreExtraLimitRequest $request): RedirectResponse
    {
        $this->extraLimitService->create($request->validated());

        return redirect()
            ->route('admin.extra-limits.index')
            ->with('success', 'Лимит добавлен');
    }

    public function update(UpdateExtraLimitRequest $request, int $extraLimit): RedirectResponse
    {
        $model = ExtraLimits::query()->findOrFail($extraLimit);

        $this->extraLimitService->update($model, $request->validated());

        return redirect()
            ->route('admin.extra-limits.index')
            ->with('success', 'Лимит обновлён');
    }

    public function destroy(int $extraLimit): RedirectResponse
    {
        $model = ExtraLimits::query()->findOrFail($extraLimit);

        $this->extraLimitService->delete($model);

        return redirect()
            ->route('admin.extra-limits.index')
            ->with('success', 'Лимит удалён');
    }
}