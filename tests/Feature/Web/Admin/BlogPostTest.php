<?php

namespace Tests\Feature\Web\Admin;

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class BlogPostTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupBlogSchema();

        foreach (['blog.view', 'blog.update'] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function test_blog_posts_page_requires_permission(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user)
            ->get('/cw-page/blog/posts')
            ->assertForbidden();
    }

    public function test_user_with_blog_view_can_open_posts_page(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $user->givePermissionTo('blog.view');

        $this->actingAs($user)
            ->get('/cw-page/blog/posts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Blog/Posts/Index'));
    }

    public function test_user_with_blog_update_can_open_post_edit_page(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $user->givePermissionTo(['blog.view', 'blog.update']);

        $category = Category::query()->create([
            'name' => 'Guides',
            'slug' => 'guides',
        ]);

        $tag = Tag::query()->create([
            'name' => 'WB',
            'slug' => 'wb',
        ]);

        $post = Post::query()->create([
            'title' => 'Editable post',
            'slug' => 'editable-post',
            'content' => "![cover](blog/images/2026/07/sample.jpg)\n\n~~strike~~",
            'excerpt' => 'Excerpt',
            'cover_image' => 'blog/images/2026/07/cover.jpg',
            'status' => 'draft',
            'published_at' => now()->subDay(),
            'seo_keywords' => ['guide', 'wb'],
        ]);

        $post->categories()->sync([$category->id]);
        $post->tags()->sync([$tag->id]);

        $this->actingAs($user)
            ->get("/cw-page/blog/posts/{$post->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Blog/Posts/Form')
                ->where('post.id', $post->id)
                ->where('post.content', $post->content)
                ->where('post.cover_image', 'blog/images/2026/07/cover.jpg')
                ->has('post.categories', 1)
                ->where('post.categories.0.id', $category->id)
                ->has('post.tags', 1)
                ->where('post.tags.0.id', $tag->id)
                ->has('categories', 1)
                ->has('tags', 1));
    }

    private function setupBlogSchema(): void
    {
        if (! Schema::hasTable('posts')) {
            Schema::create('posts', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->longText('content')->nullable();
                $table->text('excerpt')->nullable();
                $table->string('cover_image')->nullable();
                $table->string('status')->default('draft');
                $table->timestamp('published_at')->nullable();
                $table->unsignedInteger('views_count')->default(0);
                $table->string('seo_title')->nullable();
                $table->text('seo_description')->nullable();
                $table->json('seo_keywords')->nullable();
                $table->unsignedBigInteger('author_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tags')) {
            Schema::create('tags', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('post_category')) {
            Schema::create('post_category', function (Blueprint $table) {
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('category_id');
            });
        }

        if (! Schema::hasTable('post_tag')) {
            Schema::create('post_tag', function (Blueprint $table) {
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('tag_id');
            });
        }
    }
}