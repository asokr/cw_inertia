<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Feedbacks;

use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresFeedbacksClientOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\StoreCabinetRequest;
use App\Http\Requests\Web\Subscriber\UpdateCabinetRequest;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Services\Subscriber\Wb\WbFeedbacksClientsService;
use App\Support\ToolLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientsController extends SubscriberToolController
{
    use EnsuresFeedbacksClientOwnership;

    public function __construct(
        private readonly WbFeedbacksClientsService $clientsService,
    ) {
    }

    public function index(Request $request): Response
    {
        $cabinets = $this->clientsService
            ->listForUser($request->user())
            ->map(fn (FeedbacksClients $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'brands' => $client->brands ?? '',
                'created_at' => $client->created_at,
                'apikey' => $client->apikey ?? '',
                'href' => route('subscriber.wb.feedbacks.clients.show', $client->id),
            ])
            ->values()
            ->all();

        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $request->user()->subscriber?->id)
            ->where('status', 1)
            ->first();

        $limits = [
            'feedbacks_clients' => ToolLimits::planLimitValue($request->user(), $subscription, 'feedbacks_clients'),
        ];

        return Inertia::render('Subscriber/Wb/Feedbacks/Index', [
            'cabinets' => $cabinets,
            'limits' => $limits,
        ]);
    }

    public function store(StoreCabinetRequest $request): RedirectResponse
    {
        try {
            $this->clientsService->create($request->user(), [
                'name' => $request->validated('name'),
                'apikey' => (string) $request->input('apikey', ''),
                'brands' => $request->input('brands'),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Не удалось добавить кабинет');
        }

        return redirect()
            ->route('subscriber.wb.feedbacks.index')
            ->with('success', 'Кабинет добавлен');
    }

    public function update(UpdateCabinetRequest $request, FeedbacksClients $client): RedirectResponse
    {
        $this->ensureClientOwnership($client);

        try {
            $this->clientsService->update($client, $request->validated());
        } catch (\InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Не удалось обновить кабинет');
        }

        return back()->with('success', 'Кабинет обновлён');
    }

    public function destroy(Request $request, FeedbacksClients $client): RedirectResponse
    {
        $this->ensureClientOwnership($client);

        try {
            $this->clientsService->delete($request->user(), $client);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Не удалось удалить кабинет');
        }

        return redirect()
            ->route('subscriber.wb.feedbacks.index')
            ->with('success', 'Кабинет удалён');
    }
}