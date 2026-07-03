<?php

namespace Tests\Feature\Web\Admin;

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

        Permission::firstOrCreate([
            'name' => 'blog.view',
            'guard_name' => 'web',
        ]);

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