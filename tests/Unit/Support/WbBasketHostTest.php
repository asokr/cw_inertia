<?php

namespace Tests\Unit\Support;

use App\Support\Wb\WbBasketHost;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WbBasketHostTest extends TestCase
{
    public function test_vol_and_part_for_user_reported_nmid(): void
    {
        $nmid = 806756474;

        $this->assertSame(8067, WbBasketHost::vol($nmid));
        $this->assertSame(806756, WbBasketHost::part($nmid));
    }

    public function test_user_reported_nmid_maps_to_basket_37(): void
    {
        // Live WB: basket-37.wbbasket.ru returns 200 for this product; 35/36 return 404.
        $this->assertSame('37', WbBasketHost::numberForNmId(806756474));
        $this->assertSame('37', WbBasketHost::number(8067));
    }

    #[DataProvider('basketBoundariesProvider')]
    public function test_basket_boundaries(int $vol, string $expectedBasket): void
    {
        $this->assertSame($expectedBasket, WbBasketHost::number($vol));
    }

    public static function basketBoundariesProvider(): array
    {
        return [
            'first basket start' => [0, '01'],
            'first basket end' => [143, '01'],
            'second basket start' => [144, '02'],
            'basket 20 end (wgen 3484)' => [3484, '20'],
            'basket 21 after 3484' => [3485, '21'],
            'basket 35 end' => [7685, '35'],
            'basket 36 start' => [7686, '36'],
            'basket 36 end' => [7997, '36'],
            'basket 37 start' => [7998, '37'],
            'basket 37 end' => [8309, '37'],
            'basket 38 start' => [8310, '38'],
            'last known range end' => [11141, '42'],
            'beyond last range' => [11142, '43'],
        ];
    }
}
