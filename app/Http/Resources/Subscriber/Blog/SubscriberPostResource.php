<?php

namespace App\Http\Resources\Subscriber\Blog;

use App\Http\Resources\CategoryResource;
use App\Http\Resources\TagResource;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriberPostResource extends JsonResource
{
    public function toArray($request): array
    {
        $coverImageKey = $this->cover_image;
        $basePath = '/' . trim((string) config('services.blog_media.public_base_path', '/media'), '/');
        $coverImageUrl = $coverImageKey ? $basePath . '/' . ltrim((string) $coverImageKey, '/') : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'content' => $this->content,
            'excerpt' => $this->excerpt,
            'cover_image' => $coverImageKey,
            'cover_image_key' => $coverImageKey,
            'cover_image_url' => $coverImageUrl,
            'views_count' => $this->views_count,
            'published_at' => $this->published_at,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'seo_keywords' => $this->seo_keywords,
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'categories_count' => $this->whenCounted('categories'),
            'tags_count' => $this->whenCounted('tags'),
            'updated_at' => $this->updated_at,
        ];
    }
}
