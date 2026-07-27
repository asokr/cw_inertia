<?php

namespace App\Services\Subscriber\Wb;

use App\Http\Traits\SubscriptionsTrait;
use App\Http\Traits\WBFeedbacksTrait;
use App\Models\Subscribers\Wb\Feedbacks\WbFeedbacksSettings;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WbFeedbacksClientsService
{
    use SubscriptionsTrait;
    use WBFeedbacksTrait;

    public function __construct(
        private readonly WbCabinetService $wbCabinets,
    ) {
    }

    /**
     * @return Collection<int, WbCabinet>
     */
    public function listForUser(User $user): Collection
    {
        return $this->wbCabinets->listForUser($user);
    }

    /**
     * Cabinet create is handled by unified WbCabinetService.
     *
     * @param  array{name: string, apikey: string, brands?: ?string}  $data
     */
    public function create(User $user, array $data): WbCabinet
    {
        $result = $this->wbCabinets->create($user, [
            'name' => $data['name'] ?? '',
            'apikey' => $data['apikey'] ?? '',
        ]);

        $cabinet = $result['cabinet'];
        $brands = trim((string) ($data['brands'] ?? ''));

        WbFeedbacksSettings::query()->updateOrCreate(
            ['cabinet_id' => $cabinet->id],
            [
                'brands' => $brands !== '' ? $brands : null,
                'bot_status' => false,
                'ai_status' => false,
                'ai_ratings' => null,
                'review_type' => null,
            ]
        );

        return $cabinet->fresh(['feedbacksSettings']);
    }

    /**
     * Brands-only update on settings. Name/apikey via unified cabinets page.
     *
     * @param  array{name?: string, apikey?: string, brands?: ?string}  $data
     */
    public function update(WbCabinet $client, array $data): WbCabinet
    {
        if (array_key_exists('brands', $data)) {
            $brands = trim((string) ($data['brands'] ?? ''));
            WbFeedbacksSettings::query()->updateOrCreate(
                ['cabinet_id' => $client->id],
                ['brands' => $brands !== '' ? $brands : null]
            );
        }

        return $client->fresh(['feedbacksSettings']);
    }

    public function delete(User $user, WbCabinet $client): void
    {
        $this->wbCabinets->delete($user, $client);
    }

    public function updateBotStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:wb_cabinets,id',
            'bot_status' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $client = $this->findOwnedCabinet((int) $request->client_id);
        if (! $client) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        $settings = $this->settingsFor($client);
        $settings->bot_status = (bool) $request->bot_status;
        $settings->save();

        return response()->json([
            'success' => true,
            'messages' => ['Статус автоответов изменён'],
            'data' => $settings->bot_status ? 1 : 0,
        ], 200);
    }

    public function getBotStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:wb_cabinets,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $client = $this->findOwnedCabinet((int) $request->client_id);
        if (! $client) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        $settings = $this->settingsFor($client);

        return response()->json([
            'success' => true,
            'messages' => ['Статус автоматических отзывов'],
            'data' => $settings->bot_status ? 1 : 0,
        ], 200);
    }

    public function updateAiData(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:wb_cabinets,id',
            'status' => 'required',
            'review_type' => '',
            'ratings' => 'present|array',
            'brands' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $client = $this->findOwnedCabinet((int) $request->client_id);
        if (! $client) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        $settings = $this->settingsFor($client);
        $settings->ai_status = (bool) $request->status;
        $settings->ai_ratings = $request->ratings;
        $settings->review_type = $request->review_type;
        if ($request->exists('brands')) {
            $brands = trim((string) $request->input('brands', ''));
            $settings->brands = $brands !== '' ? $brands : null;
        }
        $settings->save();

        return response()->json([
            'success' => true,
            'messages' => ['Настройки сохранены'],
            'data' => [
                'status' => $settings->ai_status,
                'ratings' => $settings->ai_ratings,
                'review_type' => $settings->review_type,
                'brands' => $settings->brands,
            ],
        ], 200);
    }

    public function getAiData(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:wb_cabinets,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $client = $this->findOwnedCabinet((int) $request->client_id);
        if (! $client) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        $settings = $this->settingsFor($client);

        return response()->json([
            'success' => true,
            'messages' => ['Данные автоответов получены'],
            'data' => [
                'status' => $settings->ai_status,
                'ratings' => $settings->ai_ratings,
                'review_type' => $settings->review_type,
                'brands' => $settings->brands,
            ],
        ], 200);
    }

    private function findOwnedCabinet(int $id): ?WbCabinet
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        return $this->wbCabinets->findOwned($user, $id);
    }

    private function settingsFor(WbCabinet $client): WbFeedbacksSettings
    {
        return WbFeedbacksSettings::query()->firstOrCreate(
            ['cabinet_id' => $client->id],
            [
                'brands' => null,
                'bot_status' => false,
                'ai_status' => false,
                'ai_ratings' => null,
                'review_type' => null,
            ]
        );
    }
}
