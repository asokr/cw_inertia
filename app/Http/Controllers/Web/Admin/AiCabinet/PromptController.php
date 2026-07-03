<?php

namespace App\Http\Controllers\Web\Admin\AiCabinet;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAiCabinetTemplateRequest;
use App\Http\Requests\Admin\UpdateAiCabinetTemplateRequest;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerTemplate;
use App\Services\Admin\AdminAiCabinetService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PromptController extends Controller
{
    public function __construct(private readonly AdminAiCabinetService $aiCabinetService)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Admin/Services/AiCabinet/Prompts/Index', [
            'templates' => $this->aiCabinetService->listTemplates(),
            'responseFormats' => [
                ['value' => 'json', 'label' => 'Структурированный JSON'],
                ['value' => 'markdown', 'label' => 'Markdown-отчёт'],
            ],
        ]);
    }

    public function store(StoreAiCabinetTemplateRequest $request): RedirectResponse
    {
        $this->aiCabinetService->createTemplate($request->validated());

        return redirect()->back()->with('success', 'Промпт добавлен');
    }

    public function update(UpdateAiCabinetTemplateRequest $request, AiCabinetAnalyzerTemplate $template): RedirectResponse
    {
        $this->aiCabinetService->updateTemplate($template, $request->validated());

        return redirect()->back()->with('success', 'Промпт обновлён');
    }

    public function destroy(AiCabinetAnalyzerTemplate $template): RedirectResponse
    {
        $this->aiCabinetService->deleteTemplate($template);

        return redirect()->back()->with('success', 'Промпт удалён');
    }
}