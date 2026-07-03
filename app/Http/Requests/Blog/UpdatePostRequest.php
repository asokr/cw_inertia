<?php

namespace App\Http\Requests\Blog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowedPrefix = preg_quote((string) config('services.blog_media.allowed_prefix', 'blog/images/'), '/');

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'content' => ['sometimes', 'string'],
            'excerpt' => ['sometimes', 'nullable', 'string'],
            'cover_image' => [
                'sometimes',
                'nullable',
                'string',
                'max:2048',
                'regex:/^' . $allowedPrefix . '[A-Za-z0-9_\/.\-]+$/',
                'not_regex:/\.\./',
            ],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'hidden'])],
            'published_at' => ['sometimes', 'nullable', 'date'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string'],
            'seo_keywords' => ['sometimes', 'nullable', 'array'],
            'seo_keywords.*' => ['string', 'max:255'],
            'author_id' => ['sometimes', 'nullable', 'exists:users,id'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['integer', 'exists:categories,id'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['integer', 'exists:tags,id'],
        ];
    }
}
