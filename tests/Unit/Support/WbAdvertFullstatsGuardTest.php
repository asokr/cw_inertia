<?php

namespace Tests\Unit\Support;

use App\Support\Wb\WbAdvertFullstatsGuard;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WbAdvertFullstatsGuardTest extends TestCase
{
    private WbAdvertFullstatsGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->guard = new WbAdvertFullstatsGuard();
    }

    public function test_wait_seconds_uses_retry_header_cooldown(): void
    {
        $this->guard->setCooldownAfter429('test-key', 90);

        $wait = $this->guard->waitSeconds('test-key');

        $this->assertGreaterThanOrEqual(89, $wait);
        $this->assertLessThanOrEqual(90, $wait);
    }

    public function test_cooldown_floors_tiny_retry_after_to_interval(): void
    {
        $this->guard->setCooldownAfter429('test-key', 1);

        $wait = $this->guard->waitSeconds('test-key');

        $this->assertGreaterThanOrEqual(WbAdvertFullstatsGuard::MIN_INTERVAL_SECONDS - 1, $wait);
        $this->assertLessThanOrEqual(WbAdvertFullstatsGuard::MIN_INTERVAL_SECONDS, $wait);
    }

    public function test_ready_when_no_recent_attempt(): void
    {
        $this->assertSame(0, $this->guard->waitSeconds('fresh-key'));
    }

    public function test_clamp_retry_after_never_below_interval(): void
    {
        $this->assertSame(20, WbAdvertFullstatsGuard::clampRetryAfter(1));
        $this->assertSame(20, WbAdvertFullstatsGuard::clampRetryAfter(0));
        $this->assertSame(45, WbAdvertFullstatsGuard::clampRetryAfter(45));
    }

    public function test_three_requests_in_window_leave_one_second_wait(): void
    {
        $scope = $this->guard->tokenScope('window-key');
        $now = time();
        Cache::put($this->guard->windowKey($scope), [$now - 59, $now - 40, $now - 20], 120);

        $wait = $this->guard->waitSeconds('window-key');

        $this->assertSame(1, $wait);
    }
}
