<?php

namespace App\Http\Controllers\Web\Subscriber\Oz\Feedbacks;

use App\Http\Controllers\Api\Subscriber\Ozon\Feedbacks\FeedbacksClientsController as ApiFeedbacksClientsController;
use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresOzonFeedbacksCabinetOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\StoreCabinetRequest;
use App\Http\Requests\Web\Subscriber\UpdateOzCabinetRequest;
use App\Models\Subscribers\Oz\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\SubscribersSubscriptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientsController extends SubscriberToolController
{
    use EnsuresOzonFeedbacksCabinetOwnership;

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
                    'client_id' => $row['client_id'] ?? '',
                    'created_at' => $row['created_at'] ?? null,
                    'apikey' => $row['apikey'] ?? '',
                    'empty_answer' => (int) ($row['empty_answer'] ?? 0),
                    'signature' => $row['signature'] ?? '',
                    'href' => route('subscriber.oz.feedbacks.cabinets.show', $row['id']),
                ];
            }
        }

        $limits = ['oz_feedbacks_clients' => null];
        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $request->user()->subscriber?->id)
            ->where('status', 1)
            ->first();

        if ($subscription && isset($subscription->limits_plan['oz_feedbacks_clients'])) {
            $limits['oz_feedbacks_clients'] = (int) $subscription->limits_plan['oz_feedbacks_clients'];
        }

        return Inertia::render('Subscriber/Oz/Feedbacks/Index', [
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
            ->route('subscriber.oz.feedbacks.index')
            ->with('success', $this->apiMessage($payload, 'Кабинет добавлен'));
    }

    public function update(UpdateOzCabinetRequest $request, FeedbacksClients $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->apiClientsController->update(
            $request->duplicate(null, [
                'name' => $request->validated('name'),
                'apikey' => $request->validated('apikey'),
                'empty_answer' => $request->input('empty_answer', $cabinet->empty_answer),
                'signature' => $request->input('signature'),
            ]),
            (string) $cabinet->id
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось обновить кабинет'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Кабинет обновлён'));
    }

    public function destroy(FeedbacksClients $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->apiClientsController->destroy((string) $cabinet->id);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось удалить кабинет'));
        }

        return redirect()
            ->route('subscriber.oz.feedbacks.index')
            ->with('success', $this->apiMessage($payload, 'Кабинет удалён'));
    }
}