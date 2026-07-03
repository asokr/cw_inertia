<?php

namespace App\Http\Controllers\Web\Admin\Feedbacks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAiAnswerRequest;
use App\Services\Admin\AdminFeedbacksService;
use Inertia\Inertia;
use Inertia\Response;

class AiAnswerController extends Controller
{
    public function __construct(private readonly AdminFeedbacksService $feedbacksService)
    {
    }

    public function index(IndexAiAnswerRequest $request): Response
    {
        $perPage = (int) ($request->validated('per_page') ?? 25);
        $reviews = $this->feedbacksService->aiAnswerLogs($perPage);

        return Inertia::render('Admin/Services/Feedbacks/AiAnswers/Index', [
            'reviews' => $reviews,
            'filters' => ['per_page' => $perPage],
        ]);
    }
}