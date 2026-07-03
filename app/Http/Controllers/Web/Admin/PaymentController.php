<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexPaymentRequest;
use App\Services\Admin\AdminPaymentService;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function __construct(private readonly AdminPaymentService $paymentService)
    {
    }

    public function index(IndexPaymentRequest $request): Response
    {
        $filters = $request->validated();
        $payments = $this->paymentService->paginate($filters);

        return Inertia::render('Admin/Payments/Index', [
            'payments' => $payments,
            'filters' => [
                'per_page' => (int) ($filters['per_page'] ?? 15),
                'sort_field' => $filters['sort_field'] ?? 'id',
                'sort_order' => $filters['sort_order'] ?? 'desc',
            ],
        ]);
    }
}