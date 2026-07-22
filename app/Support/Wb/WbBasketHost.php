<?php

namespace App\Support\Wb;

/**
 * Resolves Wildberries basket host / vol / part for CDN media URLs.
 *
 * Range table aligned with wgen Container.cs:
 * https://github.com/vkorotenko/wgen/blob/master/Container.cs
 *
 * Update RANGES when WB adds new basket hosts (or re-scan via wgen).
 */
final class WbBasketHost
{
    /**
     * Upper vol bounds for baskets 01..N (index 0 => basket 01).
     *
     * @var list<int>
     */
    private const RANGES = [
        143,    // 01
        287,    // 02
        431,    // 03
        719,    // 04
        1007,   // 05
        1061,   // 06
        1115,   // 07
        1169,   // 08
        1313,   // 09
        1601,   // 10
        1655,   // 11
        1919,   // 12
        2045,   // 13
        2189,   // 14
        2405,   // 15
        2621,   // 16
        2837,   // 17
        3053,   // 18
        3269,   // 19
        3484,   // 20 (wgen: 3484)
        3701,   // 21
        3917,   // 22
        4133,   // 23
        4349,   // 24
        4565,   // 25
        4877,   // 26
        5143,   // 27
        5500,   // 28
        5813,   // 29
        6125,   // 30
        6435,   // 31
        6749,   // 32
        7061,   // 33
        7373,   // 34
        7685,   // 35
        7997,   // 36
        8309,   // 37
        8740,   // 38
        9173,   // 39
        9603,   // 40
        10373,  // 41
        11141,  // 42
    ];

    public static function vol(int|string $nmId): int
    {
        return (int) floor((int) $nmId / 1e5);
    }

    public static function part(int|string $nmId): int
    {
        return (int) floor((int) $nmId / 1e3);
    }

    /**
     * Basket host number as zero-padded two-digit string (e.g. "37").
     */
    public static function number(int $vol): string
    {
        foreach (self::RANGES as $index => $limit) {
            if ($vol <= $limit) {
                return str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            }
        }

        return str_pad((string) (count(self::RANGES) + 1), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Basket host for a product nmId.
     */
    public static function numberForNmId(int|string $nmId): string
    {
        return self::number(self::vol($nmId));
    }
}
