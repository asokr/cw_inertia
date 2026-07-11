<?php

namespace App\Http\Controllers\Api\Subscriber\Ai;

use App\Enums\AiTaskType;
use App\Http\Controllers\Controller;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Services\Ai\AiImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AiImageController extends Controller
{
    public function __construct(
        private readonly AiImageService $aiImageService,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $allowedTaskTypes = [
            AiTaskType::GENERATE_IMAGE->value,
            AiTaskType::EDIT_IMAGE->value,
        ];

        $validator = Validator::make($request->all(), [
            'task_type' => 'required|string|in:' . implode(',', $allowedTaskTypes),
            'image' => 'nullable|string',
            'images' => 'nullable|array|max:7',
            'images.*' => 'nullable|string',
            'image_prompt' => 'nullable|string|max:4000',
            'aspectRatio' => 'nullable|string|regex:/^\d{1,2}:\d{1,2}$/',
            'resolution' => 'nullable|string',
            'generation_id' => 'nullable|integer|min:1',
        ], [
            'task_type.required' => 'Не указан тип задачи',
            'task_type.in' => 'Указан недопустимый тип задачи',
            'aspectRatio.regex' => 'aspectRatio должен быть в формате W:H, например 3:4',
        ]);

        $validator->after(function ($validator) use ($request): void {
            $taskType = (string) $request->input('task_type');

            if ($taskType === AiTaskType::GENERATE_IMAGE->value) {
                $prompt = trim((string) $request->input('image_prompt', ''));
                if ($prompt === '') {
                    $validator->errors()->add('image_prompt', 'Для генерации изображения нужен image_prompt');
                }
            }

            if ($taskType === AiTaskType::EDIT_IMAGE->value) {
                if (trim((string) $request->input('image', '')) === '') {
                    $validator->errors()->add('image', 'Для редактирования изображения нужно передать image');
                }

                $singleImageError = $this->aiImageService->validateInputImageSize((string) $request->input('image', ''));
                if ($singleImageError !== null) {
                    $validator->errors()->add('image', $singleImageError);
                }

                if (trim((string) $request->input('image_prompt', '')) === '') {
                    $validator->errors()->add('image_prompt', 'Для редактирования изображения нужно передать image_prompt');
                }
            }

            $resolution = mb_strtolower(trim((string) $request->input('resolution', 'default')));
            $normalized = match ($resolution) {
                '', 'default', 'standart', 'standard' => 'default',
                '1k' => '1k',
                '2k' => '2k',
                '4k' => '4k',
                default => $resolution,
            };

            if (! in_array($normalized, ['default', '1k', '2k', '4k'], true)) {
                $validator->errors()->add('resolution', 'Допустимые resolution: default, 1K, 2K, 4K');
            }

            foreach ((array) $request->input('images', []) as $index => $image) {
                if (! is_string($image)) {
                    continue;
                }

                $imageError = $this->aiImageService->validateInputImageSize($image);
                if ($imageError !== null) {
                    $validator->errors()->add('images.' . $index, $imageError);
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $user = $request->user();
        $userId = (int) ($user?->id ?? 0);
        $subscriberId = (int) data_get($user, 'subscriber.id');

        if ($userId <= 0 || $subscriberId <= 0) {
            return response()->json([
                'success' => false,
                'messages' => ['Пользователь не авторизован'],
            ], 401);
        }

        /** @var SubscribersSubscriptions|null $subscription */
        $subscription = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriberId,
            'status' => 1,
        ])->first();

        if (! $subscription) {
            return response()->json([
                'success' => false,
                'messages' => ['Активная подписка не найдена'],
            ], 200);
        }

        if (! $this->aiImageService->hasEnoughImageLimit($subscription, $request)) {
            return response()->json([
                'success' => false,
                'messages' => ['Недостаточно лимита AI_IMAGE_QUERY'],
            ], 402);
        }

        return $this->aiImageService->start($request, $subscription, $userId, $subscriberId);
    }
}