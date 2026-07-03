<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexSentEmailRequest;
use App\Models\SentEmail;
use App\Services\Admin\AdminSentEmailService;
use Inertia\Inertia;
use Inertia\Response;

class SentEmailController extends Controller
{
    public function __construct(private readonly AdminSentEmailService $sentEmailService)
    {
    }

    public function index(IndexSentEmailRequest $request): Response
    {
        $filters = $request->validated();
        $emails = $this->sentEmailService->paginate($filters);

        return Inertia::render('Admin/SentEmails/Index', [
            'emails' => $emails,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'per_page' => (int) ($filters['per_page'] ?? 20),
                'sort' => $filters['sort'] ?? 'created_at',
                'order' => $filters['order'] ?? 'desc',
            ],
        ]);
    }

    public function show(SentEmail $sentEmail): Response
    {
        return Inertia::render('Admin/SentEmails/Show', [
            'email' => $this->sentEmailService->show($sentEmail),
        ]);
    }
}