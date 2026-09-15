<?php

namespace Tests\Feature\Web\Webhook;

use App\Enums\PaymentStatusEnum;
use App\Models\PaymentsTransaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class WebhookRoutesTest extends WebAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setupWebhookSchema();
    }

    public function test_yookassa_webhook_route_is_registered(): void
    {
        $this->post('/api/payments/yoo/callback', [])
            ->assertNoContent();
    }

    public function test_yookassa_succeeded_confirms_payment_and_credits_balance(): void
    {
        $logs = $this->captureLogs();

        $user = User::factory()->create();
        deposit(100, 'RUB')->to($user)->overcharge()->commit();
        $transaction = $this->createPendingTransaction($user, 500);

        $this->postJson('/api/payments/yoo/callback', $this->succeededPayload($transaction, $user, [
            'id' => 'yoo-success-1',
            'amount' => '500.00',
        ]))->assertNoContent();

        $transaction->refresh();
        $user->balance('RUB')->refresh();
        $this->assertSame(PaymentStatusEnum::CONFIRMED, $transaction->status);
        $this->assertSame('yoo-success-1', $transaction->system_id);
        $this->assertEqualsWithDelta(600.0, (float) (string) $user->balance('RUB')->value, 0.01);

        $depositLog = $logs->first(
            fn (MessageLogged $event) => $event->message === 'YooKassa deposit completed'
        );
        $this->assertNotNull($depositLog);
        $this->assertSame($user->id, $depositLog->context['user_id']);
        $this->assertSame($transaction->id, $depositLog->context['transaction_id']);
        $this->assertSame('yoo-success-1', $depositLog->context['payment_id']);
        $this->assertEqualsWithDelta(500.0, $depositLog->context['amount'], 0.01);
        $this->assertEqualsWithDelta(100.0, $depositLog->context['balance_before'], 0.01);
        $this->assertEqualsWithDelta(600.0, $depositLog->context['balance_after'], 0.01);
        $this->assertFalse($logs->contains(
            fn (MessageLogged $event) => str_contains($event->message, 'YooKassa дёргает колбек')
        ));
    }

    public function test_yookassa_succeeded_is_idempotent(): void
    {
        $logs = $this->captureLogs();

        $user = User::factory()->create();
        $transaction = $this->createPendingTransaction($user, 250);
        $payload = $this->succeededPayload($transaction, $user, [
            'id' => 'yoo-dup-1',
            'amount' => '250.00',
        ]);

        $this->postJson('/api/payments/yoo/callback', $payload)->assertNoContent();
        $this->postJson('/api/payments/yoo/callback', $payload)->assertNoContent();

        $transaction->refresh();
        $this->assertSame(PaymentStatusEnum::CONFIRMED, $transaction->status);
        $this->assertEqualsWithDelta(250.0, (float) (string) $user->balance('RUB')->value, 0.01);
        $this->assertCount(
            1,
            $logs->filter(fn (MessageLogged $event) => $event->message === 'YooKassa deposit completed')
        );
    }

    public function test_yookassa_canceled_marks_transaction_canceled(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createPendingTransaction($user, 100);

        $this->postJson('/api/payments/yoo/callback', [
            'type' => 'notification',
            'event' => 'payment.canceled',
            'object' => [
                'id' => 'yoo-cancel-1',
                'status' => 'canceled',
                'paid' => false,
                'amount' => ['value' => '100.00', 'currency' => 'RUB'],
                'metadata' => [
                    'transaction_id' => (string) $transaction->id,
                    'user_id' => (string) $user->id,
                ],
            ],
        ])->assertNoContent();

        $transaction->refresh();
        $this->assertSame(PaymentStatusEnum::CANCELED, $transaction->status);
        $this->assertSame('yoo-cancel-1', $transaction->system_id);
        $this->assertEqualsWithDelta(0.0, (float) (string) $user->balance('RUB')->value, 0.01);
    }

    public function test_yookassa_waiting_for_capture_does_not_change_status(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createPendingTransaction($user, 100);

        $this->postJson('/api/payments/yoo/callback', [
            'type' => 'notification',
            'event' => 'payment.waiting_for_capture',
            'object' => [
                'id' => 'yoo-wait-1',
                'status' => 'waiting_for_capture',
                'paid' => true,
                'amount' => ['value' => '100.00', 'currency' => 'RUB'],
                'metadata' => [
                    'transaction_id' => (string) $transaction->id,
                    'user_id' => (string) $user->id,
                ],
            ],
        ])->assertNoContent();

        $transaction->refresh();
        $this->assertSame(PaymentStatusEnum::CREATE, $transaction->status);
        $this->assertEqualsWithDelta(0.0, (float) (string) $user->balance('RUB')->value, 0.01);
    }

    public function test_yookassa_succeeded_accepts_numeric_metadata_and_incomplete_payment_method(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createPendingTransaction($user, 300);

        $this->postJson('/api/payments/yoo/callback', [
            'type' => 'notification',
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'yoo-raw-1',
                'status' => 'succeeded',
                'paid' => true,
                'amount' => ['value' => '300.00', 'currency' => 'RUB'],
                'payment_method' => [
                    'type' => 'bank_card',
                    'id' => 'method-1',
                    'saved' => false,
                ],
                'metadata' => [
                    'transaction_id' => $transaction->id,
                    'user_id' => $user->id,
                ],
            ],
        ])->assertNoContent();

        $transaction->refresh();
        $this->assertSame(PaymentStatusEnum::CONFIRMED, $transaction->status);
        $this->assertEqualsWithDelta(300.0, (float) (string) $user->balance('RUB')->value, 0.01);
    }

    public function test_yookassa_succeeded_finds_transaction_by_system_id(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createPendingTransaction($user, 150, 'yoo-by-system');

        $this->postJson('/api/payments/yoo/callback', [
            'type' => 'notification',
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'yoo-by-system',
                'status' => 'succeeded',
                'paid' => true,
                'amount' => ['value' => '150.00', 'currency' => 'RUB'],
                'metadata' => [],
            ],
        ])->assertNoContent();

        $transaction->refresh();
        $this->assertSame(PaymentStatusEnum::CONFIRMED, $transaction->status);
        $this->assertEqualsWithDelta(150.0, (float) (string) $user->balance('RUB')->value, 0.01);
    }

    public function test_wb_search_webhook_route_is_removed(): void
    {
        $this->postJson('/api/services/wb-search/webhook', [])
            ->assertNotFound();
    }

    /**
     * @return Collection<int, MessageLogged>
     */
    private function captureLogs(): Collection
    {
        $events = collect();

        Event::listen(MessageLogged::class, function (MessageLogged $event) use ($events) {
            $events->push($event);
        });

        return $events;
    }

    private function createPendingTransaction(User $user, float $amount, ?string $systemId = null): PaymentsTransaction
    {
        return PaymentsTransaction::query()->create([
            'user_id' => $user->id,
            'amount' => $amount,
            'description' => 'Пополнение баланса',
            'status' => PaymentStatusEnum::CREATE,
            'system' => 'YooKassa',
            'system_id' => $systemId,
        ]);
    }

    /**
     * @param  array{id: string, amount: string}  $overrides
     * @return array<string, mixed>
     */
    private function succeededPayload(PaymentsTransaction $transaction, User $user, array $overrides): array
    {
        return [
            'type' => 'notification',
            'event' => 'payment.succeeded',
            'object' => [
                'id' => $overrides['id'],
                'status' => 'succeeded',
                'paid' => true,
                'amount' => ['value' => $overrides['amount'], 'currency' => 'RUB'],
                'metadata' => [
                    'transaction_id' => (string) $transaction->id,
                    'user_id' => (string) $user->id,
                ],
            ],
        ];
    }

    private function setupWebhookSchema(): void
    {
        if (! Schema::hasTable('payments_transactions')) {
            Schema::create('payments_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->decimal('amount', 12, 2)->default(0);
                $table->unsignedBigInteger('plan_id')->nullable();
                $table->string('description')->nullable();
                $table->string('status')->nullable();
                $table->string('system')->nullable();
                $table->string('system_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('balances')) {
            Schema::create('balances', function (Blueprint $table) {
                $table->id();
                $table->morphs('payable');
                $table->decimal('value', 16, 8)->default(0);
                $table->decimal('value_pending', 16, 8)->default(0);
                $table->decimal('value_on_hold', 16, 8)->default(0);
                $table->string('currency', 10)->index();
                $table->unique(['payable_id', 'payable_type', 'currency'], 'unique_balance');
            });
        }

        if (! Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->nullableMorphs('from');
                $table->nullableMorphs('to');
                $table->decimal('amount', 64, 0)->default(0);
                $table->decimal('commission', 64, 0)->default(0);
                $table->decimal('received', 64, 0)->default(0);
                $table->string('currency', 10)->index();
                $table->string('status')->nullable();
                $table->string('processor_id')->nullable();
                $table->json('meta')->nullable();
                $table->boolean('archived')->default(false);
                $table->boolean('invisible')->default(false);
                $table->unsignedBigInteger('batch')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }
}
