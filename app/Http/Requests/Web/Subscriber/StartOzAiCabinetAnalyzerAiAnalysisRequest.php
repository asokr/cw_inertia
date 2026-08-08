<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class StartOzAiCabinetAnalyzerAiAnalysisRequest extends FormRequest
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
            'report_id' => ['required', 'integer', 'exists:oz_ai_cabinet_analyzer_reports,id'],
            'template_id' => ['required', 'integer', 'exists:oz_ai_cabinet_analyzer_templates,id'],
            'model' => ['nullable', 'string', 'max:120'],
        ];
    }
}
