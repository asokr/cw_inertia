<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexPaymentRequest;
use App\Services\Admin\AdminPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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

    public function lastPayments(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rows' => 'required',
            'sortField' => '',
            'sortOrder' => '',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $data = $this->paymentService->paginateForWidget(
            (int) $request->input('rows'),
            $request->input('sortField', 'id'),
            $request->input('sortOrder', '-1'),
        );

        if ($data->isEmpty()) {
            return response()->json(['success' => false, 'messages' => ['Нет оплат']], 200);
        }

        return response()->json(['success' => true, 'messages' => ['История получена'], 'data' => $data], 200);
    }
}