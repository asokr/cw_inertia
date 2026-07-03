<?php

namespace Tests\Feature\Web\Blog;

use App\Models\Post;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicBlogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupBlogSchema();
    }

    public function test_blog_index_renders_inertia_page(): void
    {
        $this->createPublishedPost('first-post', 'First Post');

        $this->get('/blog')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Blog/Index')
                ->has('posts', 1)
                ->where('posts.0.slug', 'first-post'));
    }

    public function test_blog_show_renders_published_post(): void
    {
        $this->createPublishedPost('my-slug', 'My Title');

        $this->get('/blog/my-slug')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Blog/Show')
                ->where('post.slug', 'my-slug')
                ->where('post.title', 'My Title'));
    }

    public function test_blog_show_returns_404_for_missing_post(): void
    {
        $this->get('/blog/missing-slug')->assertNotFound();
    }

    public function test_blog_show_returns_404_for_draft_post(): void
    {
        Post::query()->create([
            'title' => 'Draft',
            'slug' => 'draft-post',
            'content' => 'Hidden',
            'status' => 'draft',
            'published_at' => now()->subDay(),
        ]);

        $this->get('/blog/draft-post')->assertNotFound();
    }

    public function test_blog_view_increment_endpoint_works(): void
    {
        $post = $this->createPublishedPost('views-post', 'Views Post');

        $this->postJson('/blog/views-post/view')
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, $post->fresh()->views_count);
    }

    public function test_blog_sitemap_returns_xml(): void
    {
        $this->createPublishedPost('sitemap-post', 'Sitemap Post');

        $response = $this->get('/blog/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $this->assertStringContainsString('<loc>', $response->getContent());
        $this->assertStringContainsString('/blog/sitemap-post', $response->getContent());
    }

    public function test_blog_media_rejects_invalid_prefix(): void
    {
        $this->get('/media/ai/generated-videos/test.png')->assertNotFound();
    }

    private function createPublishedPost(string $slug, string $title): Post
    {
        return Post::query()->create([
            'title' => $title,
            'slug' => $slug,
            'content' => "# {$title}\n\nBody",
            'excerpt' => 'Excerpt',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'views_count' => 0,
        ]);
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