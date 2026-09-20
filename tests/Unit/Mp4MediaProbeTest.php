<?php

namespace Tests\Unit;

use App\Services\Ai\Mp4MediaProbe;
use RuntimeException;
use Tests\Support\Mp4BoxBuilder;
use Tests\TestCase;

class Mp4MediaProbeTest extends TestCase
{
    public function test_reads_duration_codec_and_size_from_mp4(): void
    {
        $probe = new Mp4MediaProbe();
        $result = $probe->probe(Mp4BoxBuilder::build([
            'codec' => 'avc1',
            'timescale' => 1000,
            'duration_units' => 5000,
            'width' => 640,
            'height' => 360,
        ]));

        $this->assertSame('avc1', $result['codec']);
        $this->assertEqualsWithDelta(5.0, $result['duration'], 0.001);
        $this->assertSame(640, $result['width']);
        $this->assertSame(360, $result['height']);
        $this->assertSame(5, $probe->billedDuration($result['duration']));
        $this->assertSame('480p', $probe->billedResolution($result['height']));
    }

    public function test_allows_duration_at_xai_limit(): void
    {
        $probe = new Mp4MediaProbe();
        $result = $probe->probe(Mp4BoxBuilder::build([
            'duration_units' => 8700,
        ]));

        $this->assertEqualsWithDelta(8.7, $result['duration'], 0.001);
    }

    public function test_rejects_video_longer_than_limit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(Mp4MediaProbe::TOO_LONG_VIDEO_MESSAGE);

        (new Mp4MediaProbe())->probe(Mp4BoxBuilder::build([
            'duration_units' => 8701,
        ]));
    }

    public function test_rejects_unsupported_codec(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(Mp4MediaProbe::UNSUPPORTED_VIDEO_MESSAGE);

        (new Mp4MediaProbe())->probe(Mp4BoxBuilder::build([
            'codec' => 'mp4v',
        ]));
    }

    public function test_rejects_file_without_ftyp(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(Mp4MediaProbe::UNSUPPORTED_VIDEO_MESSAGE);

        (new Mp4MediaProbe())->probe(Mp4BoxBuilder::build([
            'include_ftyp' => false,
        ]));
    }

    public function test_rejects_audio_only_track(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(Mp4MediaProbe::UNSUPPORTED_VIDEO_MESSAGE);

        (new Mp4MediaProbe())->probe(Mp4BoxBuilder::build([
            'handler' => 'soun',
        ]));
    }

    public function test_billed_resolution_caps_at_720p(): void
    {
        $probe = new Mp4MediaProbe();

        $this->assertSame('480p', $probe->billedResolution(480));
        $this->assertSame('720p', $probe->billedResolution(720));
        $this->assertSame('720p', $probe->billedResolution(1080));
    }
}
