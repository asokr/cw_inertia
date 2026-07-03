<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePlanRequest;
use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Models\Subscribers\SubscribersPlans;
use App\Services\Admin\AdminPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function __construct(private readonly AdminPlanService $planService)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Admin/Plans/Index', [
            'plans' => $this->planService->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Plans/Form', [
            'plan' => null,
            'permissions' => $this->planService->subscriberPermissionOptions(),
        ]);
    }

    public function edit(SubscribersPlans $plan): Response
    {
        return Inertia::render('Admin/Plans/Form', [
            'plan' => $plan,
            'permissions' => $this->planService->subscriberPermissionOptions(),
        ]);
    }

    public function store(StorePlanRequest $request): RedirectResponse
    {
        $this->planService->create($request->validated());

        return redirect()->route('admin.plans.index')->with('success', 'Тариф создан');
    }

    public function update(UpdatePlanRequest $request, SubscribersPlans $plan): RedirectResponse
    {
        $this->planService->update($plan, $request->validated());

        return redirect()->route('admin.plans.index')->with('success', 'Тариф обновлён');
    }

    public function toggleStatus(Request $request, SubscribersPlans $plan): RedirectResponse
    {
        $request->validate(['status' => 'required|boolean']);
        $this->planService->toggleStatus($plan, (bool) $request->input('status'));

        return redirect()->back()->with('success', 'Статус изменён');
    }

}