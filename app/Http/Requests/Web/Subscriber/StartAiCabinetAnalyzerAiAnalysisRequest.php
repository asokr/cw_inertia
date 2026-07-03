<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class StartAiCabinetAnalyzerAiAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'report_id' => ['required', 'integer', 'exists:wb_ai_cabinet_analyzer_reports,id'],
            'template_id' => ['required', 'integer', 'exists:wb_ai_cabinet_analyzer_templates,id'],
            'model' => ['nullable', 'string', 'max:120'],
        ];
    }
}