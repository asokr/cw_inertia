<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class FeedbacksCabinetStatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stat_type' => 'sometimes|string|in:weekly,monthly,half_year,yearly',
            'limit' => 'sometimes|integer|min:1|max:52',
            'date' => 'sometimes|date',
        ];
    }
}