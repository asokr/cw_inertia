<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Читает контейнер MP4 без ffmpeg: длительность, кодек и размер кадра.
 */
class Mp4MediaProbe
{
    public const MAX_DURATION_SECONDS = 8.7;

    public const UNSUPPORTED_VIDEO_MESSAGE = 'Этот ролик не подходит. Загрузите обычный MP4-файл.';

    public const TOO_LONG_VIDEO_MESSAGE = 'Ролик слишком длинный. Нужен файл не длиннее 8 секунд.';

    /** @var array<int, string> */
    private const ALLOWED_CODECS = ['avc1', 'avc3', 'hvc1', 'hev1', 'av01'];

    /**
     * @return array{duration: float, codec: string, width: int, height: int}
     */
    public function probe(string $binary): array
    {
        if (strlen($binary) < 16) {
            throw new RuntimeException(self::UNSUPPORTED_VIDEO_MESSAGE);
        }

        $boxes = $this->parseBoxes($binary, 0, strlen($binary));
        $hasFtyp = false;
        $videoTrack = null;

        foreach ($boxes as $box) {
            if ($box['type'] === 'ftyp') {
                $hasFtyp = true;
                continue;
            }

            if ($box['type'] !== 'moov') {
                continue;
            }

            $videoTrack = $this->findVideoTrack($binary, $box);
        }

        if (! $hasFtyp || $videoTrack === null) {
            throw new RuntimeException(self::UNSUPPORTED_VIDEO_MESSAGE);
        }

        if ($videoTrack['duration'] > self::MAX_DURATION_SECONDS) {
            throw new RuntimeException(self::TOO_LONG_VIDEO_MESSAGE);
        }

        if ($videoTrack['duration'] <= 0) {
            throw new RuntimeException(self::UNSUPPORTED_VIDEO_MESSAGE);
        }

        return $videoTrack;
    }

    public function billedDuration(float $seconds): int
    {
        return max(1, (int) ceil($seconds - 1e-9));
    }

    public function billedResolution(int $height): string
    {
        return $height > 0 && $height <= 480 ? '480p' : '720p';
    }

    /**
     * @param  array{type: string, payload_offset: int, payload_size: int}  $moov
     * @return array{duration: float, codec: string, width: int, height: int}|null
     */
    private function findVideoTrack(string $binary, array $moov): ?array
    {
        foreach ($this->childBoxes($binary, $moov) as $child) {
            if ($child['type'] !== 'trak') {
                continue;
            }

            $track = $this->readTrack($binary, $child);
            if ($track !== null) {
                return $track;
            }
        }

        return null;
    }

    /**
     * @param  array{type: string, payload_offset: int, payload_size: int}  $trak
     * @return array{duration: float, codec: string, width: int, height: int}|null
     */
    private function readTrack(string $binary, array $trak): ?array
    {
        $mdia = $this->findChild($binary, $trak, 'mdia');
        if ($mdia === null) {
            return null;
        }

        $hdlr = $this->findChild($binary, $mdia, 'hdlr');
        if ($hdlr === null || ! $this->isVideoHandler($binary, $hdlr)) {
            return null;
        }

        $mdhd = $this->findChild($binary, $mdia, 'mdhd');
        if ($mdhd === null) {
            return null;
        }

        $duration = $this->readMdhdDuration($binary, $mdhd);
        if ($duration === null) {
            return null;
        }

        $minf = $this->findChild($binary, $mdia, 'minf');
        $stbl = $minf !== null ? $this->findChild($binary, $minf, 'stbl') : null;
        $stsd = $stbl !== null ? $this->findChild($binary, $stbl, 'stsd') : null;
        if ($stsd === null) {
            return null;
        }

        $sample = $this->readVisualSample($binary, $stsd);
        if ($sample === null) {
            return null;
        }

        return [
            'duration' => $duration,
            'codec' => $sample['codec'],
            'width' => $sample['width'],
            'height' => $sample['height'],
        ];
    }

    /**
     * @param  array{payload_offset: int, payload_size: int}  $hdlr
     */
    private function isVideoHandler(string $binary, array $hdlr): bool
    {
        if ($hdlr['payload_size'] < 12) {
            return false;
        }

        return substr($binary, $hdlr['payload_offset'] + 8, 4) === 'vide';
    }

    /**
     * @param  array{payload_offset: int, payload_size: int}  $mdhd
     */
    private function readMdhdDuration(string $binary, array $mdhd): ?float
    {
        if ($mdhd['payload_size'] < 20) {
            return null;
        }

        $offset = $mdhd['payload_offset'];
        $version = ord($binary[$offset]);

        if ($version === 1) {
            if ($mdhd['payload_size'] < 32) {
                return null;
            }

            $timescale = unpack('N', substr($binary, $offset + 20, 4))[1];
            $durationHi = unpack('N', substr($binary, $offset + 24, 4))[1];
            $durationLo = unpack('N', substr($binary, $offset + 28, 4))[1];
            $durationUnits = ($durationHi * 4294967296) + $durationLo;
        } else {
            $timescale = unpack('N', substr($binary, $offset + 12, 4))[1];
            $durationUnits = unpack('N', substr($binary, $offset + 16, 4))[1];
        }

        if ($timescale <= 0) {
            return null;
        }

        return $durationUnits / $timescale;
    }

    /**
     * @param  array{payload_offset: int, payload_size: int}  $stsd
     * @return array{codec: string, width: int, height: int}|null
     */
    private function readVisualSample(string $binary, array $stsd): ?array
    {
        if ($stsd['payload_size'] < 16) {
            return null;
        }

        $entriesOffset = $stsd['payload_offset'] + 8;
        $entriesEnd = $stsd['payload_offset'] + $stsd['payload_size'];
        $entries = $this->parseBoxes($binary, $entriesOffset, $entriesEnd);

        foreach ($entries as $entry) {
            $codec = $entry['type'];
            if (! in_array($codec, self::ALLOWED_CODECS, true)) {
                continue;
            }

            if ($entry['size'] < 40) {
                return null;
            }

            $width = unpack('n', substr($binary, $entry['offset'] + 32, 2))[1];
            $height = unpack('n', substr($binary, $entry['offset'] + 34, 2))[1];

            return [
                'codec' => $codec,
                'width' => $width,
                'height' => $height,
            ];
        }

        return null;
    }

    /**
     * @param  array{payload_offset: int, payload_size: int}  $parent
     * @return array{type: string, offset: int, header: int, size: int, payload_offset: int, payload_size: int}|null
     */
    private function findChild(string $binary, array $parent, string $type): ?array
    {
        foreach ($this->childBoxes($binary, $parent) as $child) {
            if ($child['type'] === $type) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @param  array{payload_offset: int, payload_size: int}  $parent
     * @return array<int, array{type: string, offset: int, header: int, size: int, payload_offset: int, payload_size: int}>
     */
    private function childBoxes(string $binary, array $parent): array
    {
        return $this->parseBoxes(
            $binary,
            $parent['payload_offset'],
            $parent['payload_offset'] + $parent['payload_size'],
        );
    }

    /**
     * @return array<int, array{type: string, offset: int, header: int, size: int, payload_offset: int, payload_size: int}>
     */
    private function parseBoxes(string $binary, int $offset, int $end): array
    {
        $boxes = [];
        $pos = $offset;

        while ($pos + 8 <= $end) {
            $size = unpack('N', substr($binary, $pos, 4))[1];
            $type = substr($binary, $pos + 4, 4);
            $header = 8;

            if ($size === 1) {
                if ($pos + 16 > $end) {
                    break;
                }

                $sizeHi = unpack('N', substr($binary, $pos + 8, 4))[1];
                $sizeLo = unpack('N', substr($binary, $pos + 12, 4))[1];
                $size = ($sizeHi * 4294967296) + $sizeLo;
                $header = 16;
            } elseif ($size === 0) {
                $size = $end - $pos;
            }

            if ($size < $header || $pos + $size > $end) {
                break;
            }

            $boxes[] = [
                'type' => $type,
                'offset' => $pos,
                'header' => $header,
                'size' => $size,
                'payload_offset' => $pos + $header,
                'payload_size' => $size - $header,
            ];

            $pos += $size;
        }

        return $boxes;
    }
}
