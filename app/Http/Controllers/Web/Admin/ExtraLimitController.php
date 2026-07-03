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

        return redirect()->back()->with('success', 'Лимит добавлен');
    }

    public function update(UpdateExtraLimitRequest $request, ExtraLimits $extraLimit): RedirectResponse
    {
        $this->extraLimitService->update($extraLimit, $request->validated());

        return redirect()->back()->with('success', 'Лимит обновлён');
    }

    public function destroy(ExtraLimits $extraLimit): RedirectResponse
    {
        $this->extraLimitService->delete($extraLimit);

        return redirect()->back()->with('success', 'Лимит удалён');
    }
}