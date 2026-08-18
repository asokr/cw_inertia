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
        $templates = $this->aiCabinetService->listTemplates()->map(function (AiCabinetAnalyzerTemplate $template) {
            return [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'system_prompt' => $template->system_prompt,
                'sort_order' => $template->sort_order,
                'is_active' => $template->is_active,
                'response_format' => $template->response_format,
                'data_sources' => $template->resolvedDataSources(),
                'credits_cost' => $template->creditsCost(),
                'created_at' => $template->created_at,
                'updated_at' => $template->updated_at,
            ];
        });

        return Inertia::render('Admin/Services/AiCabinet/Prompts/Index', [
            'templates' => $templates,
            'responseFormats' => [
                ['value' => 'json', 'label' => 'Структурированный JSON'],
                ['value' => 'markdown', 'label' => 'Markdown-отчёт'],
            ],
            'dataSources' => collect((array) config('ai_cabinet_analyzer.data_sources', []))
                ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])
                ->values()
                ->all(),
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