<?php

namespace App\Services\Blog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BlogSlugService
{
    public function generateUniqueSlug(Model $model, string $source, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($source);

        if ($baseSlug === '') {
            $baseSlug = 'item';
        }

        $slug = $baseSlug;
        $suffix = 2;

        while ($this->slugExists($model, $slug, $ignoreId)) {
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function slugExists(Model $model, string $slug, ?int $ignoreId = null): bool
    {
        $query = $model->newQuery()->where('slug', $slug);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }
}
