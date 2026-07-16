<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DepositSubscriberRequest;
use App\Http\Requests\Admin\IndexSubscriberRequest;
use App\Http\Requests\Admin\ReverseTransactionRequest;
use App\Http\Requests\Admin\UpdateSubscriberRequest;
use App\Http\Requests\Admin\WithdrawSubscriberRequest;
use App\Models\Subscribers\Subscribers;
use App\Services\Admin\AdminPlanService;
use App\Services\Admin\AdminSubscriberService;
use App\Support\SubscriberLimitLabels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use O21\LaravelWallet\Exception\InsufficientFundsException;

class SubscriberController extends Controller
{
    public function __construct(
        private readonly AdminSubscriberService $subscriberService,
        private readonly AdminPlanService $planService,
    ) {
    }

    public function index(IndexSubscriberRequest $request): Response
    {
        $filters = $request->validated();
        $subscribers = $this->subscriberService->paginate($filters);

        return Inertia::render('Admin/Subscribers/Index', [
            'subscribers' => $subscribers,
            'plans' => $this->planService->available(),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'plan_id' => $filters['plan_id'] ?? null,
                'per_page' => (int) ($filters['per_page'] ?? 15),
                'sort_field' => $filters['sort_field'] ?? 'id',
                'sort_order' => $filters['sort_order'] ?? 'desc',
            ],
        ]);
    }

    public function search(Request $request): Response
    {
        $query = trim((string) $request->query('q', ''));
        $results = strlen($query) >= 2
            ? $this->subscriberService->search($query)
            : collect();

        return Inertia::render('Admin/Subscribers/Index', [
            'subscribers' => [
                'data' => $results->values()->all(),
                'total' => $results->count(),
                'per_page' => $results->count() ?: 15,
                'current_page' => 1,
                'last_page' => 1,
            ],
            'plans' => $this->planService->available(),
            'filters' => [
                'search' => $query,
                'plan_id' => null,
                'per_page' => 15,
                'sort_field' => 'id',
                'sort_order' => 'desc',
            ],
            'searchMode' => true,
        ]);
    }

    public function edit(Subscribers $subscriber): Response
    {
        $detail = $this->subscriberService->getDetail($subscriber);

        return Inertia::render('Admin/Subscribers/Edit', [
            'subscriber' => $detail['subscriber'],
            'payments' => $detail['payments'],
            'totalDeposits' => $detail['total_deposits'],
            'plans' => $this->planService->available(),
            'limitKeys' => array_keys(SubscriberLimitLabels::all()),
        ]);
    }

    public function update(UpdateSubscriberRequest $request, Subscribers $subscriber): RedirectResponse
    {
        try {
            $this->subscriberService->updateProfile($subscriber, $request->validated());
        } catch (\InvalidArgumentException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Подписчик обновлён');
    }

    public function deposit(DepositSubscriberRequest $request, Subscribers $subscriber): RedirectResponse
    {
        try {
            $this->subscriberService->deposit(
                $subscriber,
                (float) $request->validated('amount'),
                $request->user(),
            );
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->back()->with('error', 'Не удалось пополнить баланс.');
        }

        return redirect()->back()->with('success', 'Баланс пополнен');
    }

    public function withdraw(WithdrawSubscriberRequest $request, Subscribers $subscriber): RedirectResponse
    {
        try {
            $this->subscriberService->withdraw(
                $subscriber,
                (float) $request->validated('amount'),
                $request->validated('comment'),
                $request->user(),
            );
        } catch (InsufficientFundsException) {
            return redirect()->back()->with('error', 'Недостаточно средств для списания');
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->back()->with('error', 'Не удалось списать баланс.');
        }

        return redirect()->back()->with('success', 'Баланс списан');
    }

    public function reverseTransaction(
        ReverseTransactionRequest $request,
        Subscribers $subscriber,
        string $transaction,
    ): RedirectResponse {
        try {
            $this->subscriberService->reverseTransaction(
                $subscriber,
                $transaction,
                $request->validated('comment'),
                $request->user(),
            );
        } catch (\InvalidArgumentException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        } catch (InsufficientFundsException) {
            return redirect()->back()->with('error', 'Недостаточно средств для отмены операции');
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->back()->with('error', 'Не удалось выполнить отмену.');
        }

        return redirect()->back()->with('success', 'Операция отменена');
    }
}