<?php

namespace App\Services\Subscriber\Oz\AbTesting;

/**
 * MD5 файла и грубый отпечаток картинки.
 * Ozon пережимает кадр на CDN, поэтому точный MD5 почти никогда не совпадает —
 * тогда сравниваем dHash.
 */
class OzAbPhotoFingerprint
{
    /** Расхождение бит, при котором кадр ещё считаем тем же. */
    public const HASH_DISTANCE_MAX = 10;

    /**
     * @return array{md5: string, hash: ?string}|null
     */
    public static function fromBinary(string $binary): ?array
    {
        if ($binary === '') {
            return null;
        }

        return [
            'md5' => md5($binary),
            'hash' => self::dHash($binary),
        ];
    }

    public static function matches(
        ?string $expectedMd5,
        ?string $expectedHash,
        ?string $actualMd5,
        ?string $actualHash,
    ): bool {
        if ($expectedMd5 !== null && $expectedMd5 !== '' && $actualMd5 !== null && hash_equals($expectedMd5, $actualMd5)) {
            return true;
        }

        if ($expectedHash === null || $expectedHash === '' || $actualHash === null || $actualHash === '') {
            return false;
        }

        return self::distance($expectedHash, $actualHash) <= self::HASH_DISTANCE_MAX;
    }

    public static function distance(string $left, string $right): int
    {
        if ($left === '' || strlen($left) !== strlen($right)) {
            return 64;
        }

        $distance = 0;
        $length = strlen($left);
        for ($i = 0; $i < $length; $i++) {
            $a = hexdec($left[$i]);
            $b = hexdec($right[$i]);
            $xor = $a ^ $b;
            while ($xor > 0) {
                $distance += $xor & 1;
                $xor >>= 1;
            }
        }

        return $distance;
    }

    /**
     * 64 бита: 8 рядов по 8 сравнений соседних пикселей, строка из 16 hex-символов.
     */
    public static function dHash(string $binary): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }

        $small = imagecreatetruecolor(9, 8);
        if ($small === false) {
            imagedestroy($source);

            return null;
        }

        imagecopyresampled($small, $source, 0, 0, 0, 0, 9, 8, imagesx($source), imagesy($source));
        imagedestroy($source);

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $left = self::luma(imagecolorat($small, $x, $y));
                $right = self::luma(imagecolorat($small, $x + 1, $y));
                $bits .= $left > $right ? '1' : '0';
            }
        }
        imagedestroy($small);

        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec($nibble));
        }

        return $hex;
    }

    private static function luma(int $rgb): int
    {
        $red = ($rgb >> 16) & 0xFF;
        $green = ($rgb >> 8) & 0xFF;
        $blue = $rgb & 0xFF;

        return (int) round(($red * 299 + $green * 587 + $blue * 114) / 1000);
    }
}
