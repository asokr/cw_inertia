<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\HomeRedirect;
use App\Support\ToolLimits;
use Mockery;
use Tests\TestCase;

class ToolLimitsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_admin_can_use_plan_limits_without_consuming_them(): void
    {
        $admin = Mockery::mock(User::class)->makePartial();
        $admin->shouldReceive('hasRole')->with(['Супер-Админ', 'super-admin'])->andReturn(true);
        $admin->shouldReceive('getAllPermissions')->andReturn(collect());

        $limits = ['feedbacks_clients' => 0];

        $this->assertTrue(ToolLimits::canUsePlanLimit($admin, $limits, 'feedbacks_clients'));
        $this->assertNull(ToolLimits::applyPlanLimitConsumption($admin, $limits, 'feedbacks_clients'));
        $this->assertTrue(HomeRedirect::isAdmin($admin));
    }

    public function test_subscriber_consumes_plan_limits(): void
    {
        $subscriber = Mockery::mock(User::class)->makePartial();
        $subscriber->shouldReceive('hasRole')->with(['Супер-Админ', 'super-admin'])->andReturn(false);
        $subscriber->shouldReceive('getAllPermissions')->andReturn(collect());

        $limits = ['wb_cabinets' => 2];

        $this->assertFalse(ToolLimits::bypassesFor($subscriber));
        $this->assertTrue(ToolLimits::canUsePlanLimit($subscriber, $limits, 'wb_cabinets'));
        $this->assertSame(['wb_cabinets' => 1], ToolLimits::applyPlanLimitConsumption($subscriber, $limits, 'wb_cabinets'));
    }
}
