<?php

namespace Tests\Unit;

use App\Services\Grok\GrokVideoApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GrokVideoApiClientTest extends TestCase
{
    private const TINY_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.grok.api_key' => 'test-key',
            'services.grok.base_url' => 'https://api.x.ai',
            'services.grok.video_model' => 'grok-imagine-video',
            'services.grok.max_video_json_bytes' => 3 * 1024 * 1024,
            'services.grok.files_expires_after' => 3600,
            'services.ai_media.max_image_bytes' => 10 * 1024 * 1024,
            'services.ai_media.disk' => 'private',
            'services.proxy' => null,
        ]);
    }

    public function test_text_to_video_does_not_upload_files(): void
    {
        Http::fake([
            'https://api.x.ai/v1/videos/generations' => Http::response(['request_id' => 'req-text'], 200),
        ]);

        $result = $this->client()->startGeneration('generate_video', 'Короткое видео товара', [
            'duration' => 5,
            'resolution' => '480p',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('req-text', $result['data']['request_id']);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.x.ai/v1/videos/generations'
                && ! isset($request['image'])
                && ! isset($request['reference_images']);
        });
    }

    public function test_image_data_uri_is_uploaded_and_sent_as_file_id(): void
    {
        Http::fake([
            'https://api.x.ai/v1/files' => Http::response([
                'id' => 'file_abc',
                'filename' => 'video-input.png',
                'bytes' => 70,
            ], 200),
            'https://api.x.ai/v1/videos/generations' => Http::response(['request_id' => 'req-image'], 200),
        ]);

        $result = $this->client()->startGeneration('generate_video_from_image', 'Анимация товара', [
            'duration' => 5,
            'resolution' => '480p',
            'image' => 'data:image/png;base64,'.self::TINY_PNG_BASE64,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('req-image', $result['data']['request_id']);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.x.ai/v1/files' || ! $request->isMultipart()) {
                return false;
            }

            $names = [];
            foreach ($request->data() as $part) {
                if (is_array($part) && isset($part['name'])) {
                    $names[] = $part['name'];
                }
            }

            return in_array('expires_after', $names, true)
                && in_array('file', $names, true)
                && array_search('expires_after', $names, true) < array_search('file', $names, true);
        });
        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://api.x.ai/v1/videos/generations'
                && ($data['image']['file_id'] ?? null) === 'file_abc'
                && ! isset($data['image']['url']);
        });
    }

    public function test_panel_media_image_is_uploaded_as_file_id(): void
    {
        Storage::fake('private');
        $png = base64_decode(self::TINY_PNG_BASE64);
        $this->assertNotFalse($png);

        $path = 'ai/source-images/user-1/2026/history.png';
        Storage::disk('private')->put($path, $png);

        Http::fake([
            'https://api.x.ai/v1/files' => Http::response([
                'id' => 'file_hist',
                'filename' => 'history.png',
                'bytes' => strlen($png),
            ], 200),
            'https://api.x.ai/v1/videos/generations' => Http::response(['request_id' => 'req-hist'], 200),
        ]);

        $result = $this->client()->startGeneration('generate_video_from_image', 'Повтор из истории', [
            'image' => '/panel/ai/media/source-images/user-1/2026/history.png',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('req-hist', $result['data']['request_id']);
        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://api.x.ai/v1/videos/generations'
                && ($data['image']['file_id'] ?? null) === 'file_hist'
                && ! isset($data['image']['url']);
        });
    }

    public function test_public_image_url_is_passed_without_files_upload(): void
    {
        Http::fake([
            'https://api.x.ai/v1/videos/generations' => Http::response(['request_id' => 'req-url'], 200),
        ]);

        $result = $this->client()->startGeneration('generate_video_from_image', 'Анимация товара', [
            'image' => 'https://cdn.example.test/product.png',
        ]);

        $this->assertTrue($result['success']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://api.x.ai/v1/videos/generations'
                && ($data['image']['url'] ?? null) === 'https://cdn.example.test/product.png';
        });
    }

    public function test_reference_images_are_uploaded_as_file_ids(): void
    {
        $fileIds = ['file_one', 'file_two'];
        $index = 0;

        Http::fake(function ($request) use (&$index, $fileIds) {
            if (str_ends_with($request->url(), '/v1/files')) {
                $id = $fileIds[$index] ?? 'file_extra';
                $index++;

                return Http::response(['id' => $id], 200);
            }

            return Http::response(['request_id' => 'req-scene'], 200);
        });

        $result = $this->client()->startGeneration('generate_video_from_image', 'Сцена по референсам', [
            'duration' => 8,
            'reference_images' => [
                'data:image/png;base64,'.self::TINY_PNG_BASE64,
                'data:image/png;base64,'.self::TINY_PNG_BASE64,
            ],
        ]);

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://api.x.ai/v1/videos/generations'
                && ($data['reference_images'][0]['file_id'] ?? null) === 'file_one'
                && ($data['reference_images'][1]['file_id'] ?? null) === 'file_two';
        });
    }

    public function test_plain_text_payload_too_large_is_extracted(): void
    {
        Http::fake([
            'https://api.x.ai/v1/videos/generations' => Http::response('The POST data is too large', 413),
        ]);

        $result = $this->client()->startGeneration('generate_video', 'Короткое видео товара', [
            'duration' => 5,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(413, $result['status']);
        $this->assertSame(GrokVideoApiClient::PAYLOAD_TOO_LARGE_MESSAGE, $result['messages'][0] ?? null);
    }

    public function test_ssl_connection_error_on_file_upload_is_returned(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 60: SSL certificate problem: self-signed certificate in certificate chain'
            );
        });

        $result = $this->client()->startGeneration('generate_video_from_image', 'Анимация товара', [
            'image' => 'data:image/png;base64,'.self::TINY_PNG_BASE64,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(503, $result['status']);
        $this->assertTrue($this->client()->isConnectionError($result['messages'][0] ?? null));
    }

    public function test_oversized_json_is_rejected_before_provider_call(): void
    {
        config(['services.grok.max_video_json_bytes' => 80]);

        Http::fake();

        $result = $this->client()->startGeneration('generate_video', str_repeat('а', 200), [
            'duration' => 5,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(413, $result['status']);
        $this->assertSame(GrokVideoApiClient::PAYLOAD_TOO_LARGE_MESSAGE, $result['messages'][0] ?? null);
        Http::assertNothingSent();
    }

    public function test_edit_public_url_posts_to_edits_without_duration(): void
    {
        Http::fake([
            'https://api.x.ai/v1/videos/edits' => Http::response(['request_id' => 'req-edit'], 200),
        ]);

        $result = $this->client()->startEdit('Добавь дождь', [
            'video' => 'https://cdn.example.test/clip.mp4',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('req-edit', $result['data']['request_id']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://api.x.ai/v1/videos/edits'
                && ($data['video']['url'] ?? null) === 'https://cdn.example.test/clip.mp4'
                && ! isset($data['duration'])
                && ! isset($data['resolution'])
                && ! isset($data['aspect_ratio']);
        });
    }

    public function test_edit_panel_media_is_uploaded_as_file_id(): void
    {
        Storage::fake('private');
        $binary = "\0\0\0\x18ftypisom\0\0\0\0isommp41";
        $path = 'ai/source-videos/user-1/2026/clip.mp4';
        Storage::disk('private')->put($path, $binary);

        Http::fake([
            'https://api.x.ai/v1/files' => Http::response([
                'id' => 'file_video',
                'filename' => 'video-input.mp4',
            ], 200),
            'https://api.x.ai/v1/videos/edits' => Http::response(['request_id' => 'req-edit-file'], 200),
        ]);

        $result = $this->client()->startEdit('Добавь шляпу', [
            'video' => '/panel/ai/media/source-videos/user-1/2026/clip.mp4',
        ]);

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://api.x.ai/v1/videos/edits'
                && ($data['video']['file_id'] ?? null) === 'file_video'
                && ! isset($data['video']['url']);
        });
    }

    private function client(): GrokVideoApiClient
    {
        return new GrokVideoApiClient(
            apiKey: 'test-key',
            baseUrl: 'https://api.x.ai',
            videoModel: 'grok-imagine-video',
        );
    }
}
