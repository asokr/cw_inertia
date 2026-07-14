<?php

namespace Tests\Unit;

use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use App\Support\HomeRedirect;
use App\Support\ToolLimits;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ToolLimitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('subscribers_subscriptions');

        Schema::create('subscribers_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscribers_id')->default(1);
            $table->unsignedBigInteger('plan_id')->default(1);
            $table->json('limits_plan')->nullable();
            $table->json('extra_limits_plan')->nullable();
            $table->json('limits_month')->nullable();
            $table->json('extra_limits_month')->nullable();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_admin_bypasses_monthly_limits(): void
    {
        $admin = Mockery::mock(User::class)->makePartial();
        $admin->shouldReceive('hasRole')->with(['Супер-Админ', 'super-admin'])->andReturn(true);
        $admin->shouldReceive('getAllPermissions')->andReturn(collect());

        Auth::login($admin);

        $subscription = SubscribersSubscriptions::create([
            'limits_month' => ['ai_text_query' => 0],
            'extra_limits_month' => ['ai_text_query' => 0],
        ]);

        $this->assertTrue(ToolLimits::bypassesFor($admin));
        $this->assertSame(ToolLimits::UNLIMITED_VALUE, $subscription->getMonthLimit('ai_text_query'));
        $this->assertTrue($subscription->minusMonthLimit('ai_text_query'));

        $subscription->refresh();
        $this->assertSame(0, $subscription->limits_month['ai_text_query']);
    }

    public function test_subscriber_still_uses_real_monthly_limits(): void
    {
        $subscriber = Mockery::mock(User::class)->makePartial();
        $subscriber->shouldReceive('hasRole')->with(['Супер-Админ', 'super-admin'])->andReturn(false);
        $subscriber->shouldReceive('getAllPermissions')->andReturn(collect());

        Auth::login($subscriber);

        $subscription = SubscribersSubscriptions::create([
            'limits_month' => ['ai_text_query' => 1],
            'extra_limits_month' => [],
        ]);

        $this->assertFalse(ToolLimits::bypassesFor($subscriber));
        $this->assertSame(1, $subscription->getMonthLimit('ai_text_query'));
        $this->assertTrue($subscription->minusMonthLimit('ai_text_query'));

        $subscription->refresh();
        $this->assertSame(0, $subscription->limits_month['ai_text_query']);
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
}