<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|exists:users,id',
            'status' => 'nullable|integer|in:0,1',
            'plan_id' => 'nullable|integer|exists:subscribers_plans,id',
            'user.name' => 'required|string|max:255',
            'user.email' => 'required|email|max:255',
            'user.phone' => 'nullable|string|max:50',
            'subscriptions' => 'nullable|array',
            'subscriptions.*.id' => 'required|integer',
            'subscriptions.*.extra_limits_month' => 'nullable|array',
        ];
    }
}