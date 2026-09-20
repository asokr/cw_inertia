<?php

namespace Tests\Support;

/**
 * Собирает минимальный ISO BMFF, достаточный для Mp4MediaProbe.
 */
final class Mp4BoxBuilder
{
    /**
     * @param  array{codec?: string, timescale?: int, duration_units?: int, width?: int, height?: int, include_ftyp?: bool, handler?: string}  $options
     */
    public static function build(array $options = []): string
    {
        $codec = $options['codec'] ?? 'avc1';
        $timescale = $options['timescale'] ?? 1000;
        $durationUnits = $options['duration_units'] ?? 5000;
        $width = $options['width'] ?? 640;
        $height = $options['height'] ?? 360;
        $handler = $options['handler'] ?? 'vide';
        $includeFtyp = $options['include_ftyp'] ?? true;

        $stsd = self::box('stsd', pack('N', 0).pack('N', 1).self::visualSample($codec, $width, $height));
        $stbl = self::box('stbl', $stsd);
        $minf = self::box('minf', $stbl);
        $mdhd = self::box('mdhd', pack('N', 0).pack('N', 0).pack('N', 0).pack('N', $timescale).pack('N', $durationUnits).pack('N', 0));
        $hdlr = self::box('hdlr', pack('N', 0).pack('N', 0).$handler.str_repeat("\0", 12)."VideoHandler\0");
        $mdia = self::box('mdia', $mdhd.$hdlr.$minf);
        $trak = self::box('trak', $mdia);
        $moov = self::box('moov', $trak);

        $ftyp = $includeFtyp
            ? self::box('ftyp', 'isom'.pack('N', 0).'isom'.'mp41')
            : '';

        return $ftyp.$moov;
    }

    private static function visualSample(string $codec, int $width, int $height): string
    {
        $payload = str_repeat("\0", 6)
            .pack('n', 1)
            .str_repeat("\0", 16)
            .pack('n', $width)
            .pack('n', $height)
            .pack('N', 0x00480000)
            .pack('N', 0x00480000)
            .pack('N', 0)
            .pack('n', 1)
            .str_repeat("\0", 32)
            .pack('n', 24)
            .pack('n', 0xFFFF);

        $size = 8 + strlen($payload);

        return pack('N', $size).$codec.$payload;
    }

    private static function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)).$type.$payload;
    }
}
