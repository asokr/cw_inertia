<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $permissions = $this->input('permissions', []);
        if (! is_array($permissions)) {
            return;
        }

        $allowed = Permission::query()
            ->where(function ($query) {
                $query->where('name', 'subscriber')
                    ->orWhere('name', 'like', 'subscriber %');
            })
            ->pluck('name')
            ->all();

        $allowedSet = array_fill_keys($allowed, true);

        $normalized = [];
        foreach ($permissions as $permission) {
            if (! is_string($permission)) {
                continue;
            }

            $name = trim($permission);
            if ($name === '' || ! isset($allowedSet[$name])) {
                continue;
            }

            $normalized[] = $name;
        }

        $this->merge([
            'permissions' => array_values(array_unique($normalized)),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'duration' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'limits_plan' => 'nullable',
            'limits_month' => 'nullable',
            'permissions' => 'required|array|min:1',
            'permissions.*' => [
                'required',
                'string',
                Rule::exists('permissions', 'name')->where(function ($query) {
                    $query->where('name', 'subscriber')
                        ->orWhere('name', 'like', 'subscriber %');
                }),
            ],
            'status' => 'required|boolean',
            'hidden' => 'required|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'permissions.required' => 'Выберите хотя бы одно разрешение тарифа.',
            'permissions.min' => 'Выберите хотя бы одно разрешение тарифа.',
            'permissions.*.exists' => 'Одно из разрешений устарело или не существует. Обновите список и сохраните снова.',
        ];
    }
}