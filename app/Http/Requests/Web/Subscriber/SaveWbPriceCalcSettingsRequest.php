<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class SaveWbPriceCalcSettingsRequest extends FormRequest
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
            'maintenance_type' => ['required', 'in:transfer,sales'],
            'buyout_scope' => ['required', 'in:cabinet,article'],
            'hide_sizes' => ['boolean'],
            'use_localization_index' => ['boolean'],
            'use_storage' => ['boolean'],
            'use_irp' => ['boolean'],
            'commission_source' => ['required', 'in:fbs,fbo,reports,manual'],
            'acquiring_source' => ['required', 'in:reports,manual'],
        ];
    }
}