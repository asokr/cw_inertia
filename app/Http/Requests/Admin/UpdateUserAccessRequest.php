<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roles' => 'present|array',
            'roles.*' => 'integer|exists:roles,id',
            'permissions' => 'present|array',
            'permissions.*' => 'integer|exists:permissions,id',
        ];
    }
}