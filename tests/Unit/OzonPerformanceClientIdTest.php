<?php

namespace Tests\Unit;

use App\Services\Ozon\OzonPerformanceApiService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OzonPerformanceClientIdTest extends TestCase
{
    #[DataProvider('clientIdProvider')]
    public function test_normalize_client_id(string $input, string $expected): void
    {
        $this->assertSame($expected, OzonPerformanceApiService::normalizeClientId($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function clientIdProvider(): array
    {
        $full = '12345@advertising.performance.ozon.ru';

        return [
            'только цифры' => ['12345', $full],
            'полный id' => [$full, $full],
            'пробелы вокруг цифр' => ['  12345  ', $full],
            'пробелы вокруг полного id' => ['  '.$full.'  ', $full],
            'суффикс в другом регистре' => ['12345@Advertising.Performance.Ozon.ru', $full],
            'пустая строка' => ['', ''],
            'только пробелы' => ['   ', ''],
        ];
    }
}
