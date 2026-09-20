<?php

namespace Tests\Unit;

use App\Services\Ai\AiMediaStorageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Mp4BoxBuilder;
use Tests\TestCase;

class AiMediaStorageServiceVideoDownloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ai_media.disk' => 'private',
            'services.ai_media.video_prefix' => 'ai/generated-videos',
            'services.ai_media.max_video_bytes' => 10 * 1024 * 1024,
            'services.grok.http_verify' => false,
            'services.proxy' => null,
        ]);

        Storage::fake('private');
    }

    public function test_stores_provider_video_when_content_type_is_octet_stream(): void
    {
        $binary = Mp4BoxBuilder::build();

        Http::fake([
            'https://vidgen.x.ai/*' => Http::response($binary, 200, [
                'Content-Type' => 'application/octet-stream',
            ]),
        ]);

        $stored = app(AiMediaStorageService::class)->storeVideoByUrlAndGetSignedUrl(
            'https://vidgen.x.ai/xai-vidgen-bucket/clip.mp4',
            7,
        );

        $this->assertSame('video/mp4', $stored['mime_type']);
        $this->assertNotSame('', $stored['path']);
        Storage::disk('private')->assertExists($stored['path']);
    }

    public function test_wraps_provider_download_connection_errors(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 60: SSL certificate problem: self-signed certificate in certificate chain'
            );
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Не удалось скачать сгенерированное видео по ссылке провайдера');

        app(AiMediaStorageService::class)->storeVideoByUrlAndGetSignedUrl(
            'https://vidgen.x.ai/xai-vidgen-bucket/clip.mp4',
            7,
        );
    }
}
