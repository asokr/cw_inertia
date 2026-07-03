<?php

namespace App\Services\Blog;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class BlogMediaService
{
    public function uploadImage(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw new RuntimeException('Некорректный файл изображения');
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $fileName = Str::uuid() . '.' . $extension;
        $path = 'blog/images/' . now()->format('Y/m') . '/' . $fileName;

        $stored = Storage::disk('public')->putFileAs(
            dirname($path),
            $file,
            basename($path)
        );

        if (! $stored) {
            throw new RuntimeException('Не удалось сохранить изображение');
        }

        return $stored;
    }
}
