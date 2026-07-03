<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Feedbacks;

use App\Http\Controllers\Api\Subscriber\Wb\Feedbacks\FeedbacksClientsController as ApiFeedbacksClientsController;
use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresFeedbacksClientOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\StoreCabinetRequest;
use App\Http\Requests\Web\Subscriber\UpdateCabinetRequest;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientsController extends SubscriberToolController
{
    use EnsuresFeedbacksClientOwnership;

    public function __construct(
        private readonly ApiFeedbacksClientsController $apiClientsController,
    ) {
    }

    public function index(Request $request): Response
    {
        $response = $this->apiClientsController->index();
        $payload = $this->decodeApiResponse($response);

        $cabinets = [];
        if (($payload['success'] ?? false) === true) {
            foreach ($payload['data'] ?? [] as $client) {
                $row = is_array($client) ? $client : $client->toArray();
                $cabinets[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'brands' => $row['brands'] ?? '',
                    'created_at' => $row['created_at'] ?? null,
                    'apikey' => $row['apikey'] ?? '',
                    'href' => route('subscriber.wb.feedbacks.clients.show', $row['id']),
                ];
            }
        }

        $limits = ['feedbacks_clients' => null];
        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $request->user()->subscriber?->id)
            ->where('status', 1)
            ->first();

        if ($subscription && isset($subscription->limits_plan['feedbacks_clients'])) {
            $limits['feedbacks_clients'] = (int) $subscription->limits_plan['feedbacks_clients'];
        }

        return Inertia::render('Subscriber/Wb/Feedbacks/Index', [
            'cabinets' => $cabinets,
            'limits' => $limits,
        ]);
    }

    public function store(StoreCabinetRequest $request): RedirectResponse
    {
        $response = $this->apiClientsController->store($request);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось добавить кабинет'));
        }

        return redirect()
            ->route('subscriber.wb.feedbacks.index')
            ->with('success', $this->apiMessage($payload, 'Кабинет добавлен'));
    }

    public function update(UpdateCabinetRequest $request, FeedbacksClients $client): RedirectResponse
    {
        $this->ensureClientOwnership($client);

        $response = $this->apiClientsController->update($request, (string) $client->id);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось обновить кабинет'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Кабинет обновлён'));
    }

    public function destroy(FeedbacksClients $client): RedirectResponse
    {
        $this->ensureClientOwnership($client);

        $response = $this->apiClientsController->destroy((string) $client->id);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось удалить кабинет'));
        }

        return redirect()
            ->route('subscriber.wb.feedbacks.index')
            ->with('success', $this->apiMessage($payload, 'Кабинет удалён'));
    }
}