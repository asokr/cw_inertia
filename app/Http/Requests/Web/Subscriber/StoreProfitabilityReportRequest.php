<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class StoreProfitabilityReportRequest extends FormRequest
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
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'dop_rashod' => ['nullable', 'numeric', 'min:0'],
            'nalog_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $from = $this->date('date_from');
            $to = $this->date('date_to');

            if ($from && $to && $from->diffInDays($to) > 30) {
                $validator->errors()->add('date_to', 'Максимально возможный диапазон для отчёта — 30 дней');
            }
        });
    }
}