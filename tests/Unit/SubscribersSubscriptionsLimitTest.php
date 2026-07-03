<?php

namespace Tests\Unit;

use App\Models\Subscribers\SubscribersSubscriptions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscribersSubscriptionsLimitTest extends TestCase
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
        Schema::dropIfExists('subscribers_subscriptions');

        parent::tearDown();
    }

    public function test_get_month_limit_sums_base_and_extra_when_base_is_zero(): void
    {
        $subscription = new SubscribersSubscriptions([
            'limits_month' => ['ai_video_query' => 0],
            'extra_limits_month' => ['ai_video_query' => 5000],
        ]);

        $this->assertSame(5000, $subscription->getMonthLimit('ai_video_query'));
    }

    public function test_get_month_limit_sums_base_and_extra(): void
    {
        $subscription = new SubscribersSubscriptions([
            'limits_month' => ['ai_text_query' => 5],
            'extra_limits_month' => ['ai_text_query' => 10],
        ]);

        $this->assertSame(15, $subscription->getMonthLimit('ai_text_query'));
    }

    public function test_get_month_limit_returns_extra_when_base_key_missing(): void
    {
        $subscription = new SubscribersSubscriptions([
            'limits_month' => [],
            'extra_limits_month' => ['ai_video_query' => 5000],
        ]);

        $this->assertSame(5000, $subscription->getMonthLimit('ai_video_query'));
    }

    public function test_get_month_limit_returns_false_when_both_are_zero(): void
    {
        $subscription = new SubscribersSubscriptions([
            'limits_month' => ['ai_video_query' => 0],
            'extra_limits_month' => ['ai_video_query' => 0],
        ]);

        $this->assertFalse($subscription->getMonthLimit('ai_video_query'));
    }

    public function test_minus_month_limit_spends_from_extra_when_base_is_zero(): void
    {
        $subscription = SubscribersSubscriptions::create([
            'limits_month' => ['ai_video_query' => 0],
            'extra_limits_month' => ['ai_video_query' => 5],
        ]);

        $this->assertTrue($subscription->minusMonthLimit('ai_video_query'));

        $subscription->refresh();

        $this->assertSame(0, $subscription->limits_month['ai_video_query']);
        $this->assertSame(4, $subscription->extra_limits_month['ai_video_query']);
    }

    public function test_minus_month_limit_spends_from_base_before_extra(): void
    {
        $subscription = SubscribersSubscriptions::create([
            'limits_month' => ['ai_text_query' => 2],
            'extra_limits_month' => ['ai_text_query' => 5],
        ]);

        $this->assertTrue($subscription->minusMonthLimit('ai_text_query'));

        $subscription->refresh();

        $this->assertSame(1, $subscription->limits_month['ai_text_query']);
        $this->assertSame(5, $subscription->extra_limits_month['ai_text_query']);
    }

    public function test_minus_month_limit_spends_from_extra_when_base_key_missing(): void
    {
        $subscription = SubscribersSubscriptions::create([
            'limits_month' => [],
            'extra_limits_month' => ['ai_video_query' => 3],
        ]);

        $this->assertTrue($subscription->minusMonthLimit('ai_video_query'));

        $subscription->refresh();

        $this->assertSame(2, $subscription->extra_limits_month['ai_video_query']);
    }
}