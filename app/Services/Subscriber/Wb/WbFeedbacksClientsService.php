<?php

namespace App\Services\Subscriber\Wb;

use App\Http\Traits\SubscriptionsTrait;
use App\Http\Traits\WBFeedbacksTrait;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\User;
use App\Support\ToolLimits;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WbFeedbacksClientsService
{
    use SubscriptionsTrait;
    use WBFeedbacksTrait;

    public function listForUser(User $user): Collection
    {
        $subscriberId = $user->subscriber?->id;

        if (! $subscriberId) {
            return FeedbacksClients::query()->whereRaw('0 = 1')->get();
        }

        return FeedbacksClients::query()
            ->where('subscriber_id', $subscriberId)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array{name: string, apikey: string, brands?: ?string}  $data
     */
    public function create(User $user, array $data): FeedbacksClients
    {
        $subscriber = $user->subscriber;

        if (! $subscriber) {
            throw new \InvalidArgumentException('Подписчик не найден');
        }

        $name = trim($data['name']);
        $apikey = trim($data['apikey']);
        $brands = trim((string) ($data['brands'] ?? ''));

        if ($name === '') {
            throw new \InvalidArgumentException('Укажите название кабинета');
        }

        if ($apikey === '') {
            throw new \InvalidArgumentException('Укажите API-ключ');
        }

        if ($this->apiKeyAlreadyRegistered($apikey)) {
            throw new \InvalidArgumentException('Кабинет с таким Api ключом уже есть в системе.');
        }

        $checkApiKey = $this->parseApiResponse($this->apiGetFeedbacks($apikey));
        if (! $checkApiKey['success'] && ($checkApiKey['code'] ?? null) === 401) {
            throw new \InvalidArgumentException('Не удалось авторизоваться с указанным API ключом. Проверьте ключ.');
        }

        $this->assertCanCreateCabinet($user, $subscriber->id);

        $client = FeedbacksClients::query()->create([
            'subscriber_id' => $subscriber->id,
            'name' => $name,
            'brands' => $brands,
            'apikey' => $apikey,
        ]);

        if (! $client) {
            throw new \RuntimeException('Не удалось добавить кабинет');
        }

        return $client;
    }

    /**
     * @param  array{name: string, apikey: string, brands?: ?string}  $data
     */
    public function update(FeedbacksClients $client, array $data): FeedbacksClients
    {
        $name = trim($data['name']);
        $apikey = trim($data['apikey']);
        $brands = trim((string) ($data['brands'] ?? ''));

        if ($name === '') {
            throw new \InvalidArgumentException('Укажите название кабинета');
        }

        if ($apikey === '') {
            throw new \InvalidArgumentException('Укажите API-ключ');
        }

        if ($this->apiKeyAlreadyRegistered($apikey, $client->id)) {
            throw new \InvalidArgumentException('Кабинет с таким Api ключом уже есть в системе.');
        }

        $checkApiKey = $this->parseApiResponse($this->apiGetFeedbacks($apikey));
        if (! $checkApiKey['success'] && ($checkApiKey['code'] ?? null) === 401) {
            throw new \InvalidArgumentException('Не удалось авторизоваться с указанным API ключом. Проверьте ключ.');
        }

        $client->name = $name;
        $client->apikey = $apikey;
        $client->brands = $brands;
        $client->save();

        return $client;
    }

    public function delete(User $user, FeedbacksClients $client): void
    {
        $subscriberId = $user->subscriber?->id;

        if (! $subscriberId) {
            throw new \InvalidArgumentException('Подписчик не найден');
        }

        $client->delete();
        $this->syncLimits($subscriberId, 'feedbacks_clients');
    }

    private function apiKeyAlreadyRegistered(string $apikey, ?int $exceptId = null): bool
    {
        $query = FeedbacksClients::query()->where('apikey', $apikey);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    private function assertCanCreateCabinet(User $user, int $subscriberId): void
    {
        $limits = null;
        $planId = null;

        $userSubscriptions = SubscribersSubscriptions::query()
            ->where([
                'subscribers_id' => $subscriberId,
                'status' => 1,
            ])
            ->get();

        foreach ($userSubscriptions as $subscription) {
            if (isset($subscription->limits_plan['feedbacks_clients'])) {
                $planId = $subscription->plan_id;
                $limits = $subscription->limits_plan;
            }
        }

        if (isset($limits['feedbacks_clients']) && ! ToolLimits::canUsePlanLimit($user, $limits, 'feedbacks_clients')) {
            throw new \InvalidArgumentException('Вы исчерпали лимит на количество кабинетов по тарифу.');
        }

        $updatedLimits = isset($limits['feedbacks_clients'])
            ? ToolLimits::applyPlanLimitConsumption($user, $limits, 'feedbacks_clients')
            : null;

        if ($updatedLimits !== null) {
            SubscribersSubscriptions::query()
                ->where([
                    'subscribers_id' => $subscriberId,
                    'plan_id' => $planId,
                    'status' => 1,
                ])
                ->update([
                    'limits_plan' => $updatedLimits,
                ]);
        }
    }

    public function updateBotStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:subs_wb_feedbacks_clients,id',
            'bot_status' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->client_id);
        if (! $client) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        $subscriberId = auth()->user()->subscriber?->id;
        if (! $subscriberId || $client->subscriber_id != $subscriberId) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        $client->bot_status = $request->bot_status;
        $client->save();

        return response()->json([
            'success' => true,
            'messages' => ['Статус автоответов изменён'],
            'data' => $client->bot_status,
        ], 200);
    }

    public function getBotStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:subs_wb_feedbacks_clients,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->client_id);
        if (! $client) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        $subscriberId = auth()->user()->subscriber?->id;
        if (! $subscriberId || $client->subscriber_id != $subscriberId) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        return response()->json([
            'success' => true,
            'messages' => ['Статус автоматических отзывов'],
            'data' => $client->bot_status,
        ], 200);
    }

    public function updateAiData(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:subs_wb_feedbacks_clients,id',
            'status' => 'required',
            'review_type' => '',
            'ratings' => 'present|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->client_id);
        if (! $client) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        $subscriberId = auth()->user()->subscriber?->id;
        if (! $subscriberId || $client->subscriber_id != $subscriberId) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        $client->ai_status = $request->status;
        $client->ai_ratings = $request->ratings;
        $client->review_type = $request->review_type;
        $client->save();

        return response()->json(['success' => true, 'messages' => ['Данные автоответов изменены']], 200);
    }

    public function getAiData(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:subs_wb_feedbacks_clients,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->client_id);
        if (! $client) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        $subscriberId = auth()->user()->subscriber?->id;
        if (! $subscriberId || $client->subscriber_id != $subscriberId) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        return response()->json([
            'success' => true,
            'messages' => ['Данные автоответов получены'],
            'data' => [
                'status' => $client->ai_status,
                'ratings' => $client->ai_ratings,
                'review_type' => $client->review_type,
            ],
        ], 200);
    }
}