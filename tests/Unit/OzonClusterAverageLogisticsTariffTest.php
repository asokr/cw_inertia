<?php

namespace Tests\Unit;

use App\Support\Ozon\PriceCalc\OzonClusterAverageLogisticsTariff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OzonClusterAverageLogisticsTariffTest extends TestCase
{
    #[DataProvider('volumeProvider')]
    public function test_for_volume(?float $volume, ?float $expected): void
    {
        $actual = OzonClusterAverageLogisticsTariff::forVolume($volume);

        if ($expected === null) {
            $this->assertNull($actual);

            return;
        }

        $this->assertNotNull($actual);
        $this->assertEqualsWithDelta($expected, $actual, 0.001);
    }

    /**
     * @return array<string, array{0: float|null, 1: float|null}>
     */
    public static function volumeProvider(): array
    {
        return [
            'null' => [null, null],
            'ноль' => [0.0, null],
            'отрицательный' => [-1.0, null],
            'до 1 л' => [0.5, 88.54],
            'ровно 1 л' => [1.0, 88.54],
            'свыше 1 до 2' => [1.01, 110.10],
            'ровно 2 л' => [2.0, 110.10],
            'ровно 4 л' => [4.0, 128.85],
            'свыше 4' => [4.01, 170.02],
            'ровно 800 л' => [800.0, 6588.77],
            'свыше 800 л' => [801.0, 7856.33],
        ];
    }
}
