<?php

namespace Tests\Unit;

use App\Support\Wb\WbPriceCalcOperationGuard;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WbPriceCalcOperationGuardTest extends TestCase
{
    private WbPriceCalcOperationGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->guard = new WbPriceCalcOperationGuard();
    }

    public function test_acquire_blocks_second_call(): void
    {
        $first = $this->guard->acquire(10);
        $second = $this->guard->acquire(10);

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame('busy', $second['reason']);
        $this->assertGreaterThan(0, $second['retry_after']);

        $this->guard->release(10);
        $third = $this->guard->acquire(10);
        $this->assertTrue($third['ok']);
        $this->guard->release(10);
    }

    public function test_cooldown_blocks_until_expired(): void
    {
        $this->guard->setCooldown(11, 30);
        $state = $this->guard->state(11);

        $this->assertFalse($state['busy']);
        $this->assertGreaterThan(0, $state['retry_after']);
        $this->assertSame('cooldown', $state['reason']);

        $blocked = $this->guard->acquire(11);
        $this->assertFalse($blocked['ok']);
        $this->assertSame('cooldown', $blocked['reason']);
    }

    public function test_cooldown_after_429_uses_longer_window(): void
    {
        $this->guard->setCooldownAfter429(12);
        $state = $this->guard->state(12);

        $this->assertGreaterThanOrEqual(
            WbPriceCalcOperationGuard::COOLDOWN_AFTER_429_SECONDS - 1,
            $state['retry_after']
        );
    }
}
