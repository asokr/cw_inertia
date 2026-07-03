<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SentEmailController extends Controller
{
    /**
     * Список отправленных писем с поиском по теме (subject) + дополнение:
     * - recipient_id и recipient_name берём из meta либо из БД
     * - subscriber_id получаем через связь User->subscriber (user_id = recipient_id)
     *
     * Query params:
     * - search | q: строка поиска по subject
     * - page: номер страницы (по умолчанию 1)
     * - per_page: кол-во на страницу (по умолчанию 20, макс. 100)
     * - sort: поле сортировки (id|created_at|subject), по умолчанию created_at
     * - order: направление сортировки (asc|desc), по умолчанию desc
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search'   => ['nullable', 'string', 'max:255'],
            'q'        => ['nullable', 'string', 'max:255'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Разрешаем сортировку по id, чтобы не было 422 при клике на колонку ID
            'sort'     => ['nullable', 'in:id,created_at,subject'],
            'order'    => ['nullable', 'in:asc,desc'],
        ]);

        $search = trim((string)($validated['search'] ?? $validated['q'] ?? ''));
        $perPage = (int)($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'created_at';
        $order = $validated['order'] ?? 'desc';

        $query = SentEmail::query();

        if ($search !== '') {
            $safe = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $query->where('subject', 'like', '%' . $safe . '%');
        }

        // На будущее, если будут join'ы — лучше указывать явное имя таблицы для id
        $sortColumn = $sort === 'id' ? 'id' : $sort;
        $query->orderBy($sortColumn, $order);

        $paginator = $query->paginate($perPage)->appends($request->query());

        $items = collect($paginator->items());

        // recipient_id берём из meta и грузим пачкой пользователей + subscriber
        $recipientIds = $items
            ->map(fn(SentEmail $email) => ((array)$email->meta)['recipient_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        $users = User::with('subscriber')
            ->whereIn('id', $recipientIds)
            ->get()
            ->keyBy('id');

        $transformed = $items->map(function (SentEmail $email) use ($users) {
            $row = $email->toArray();

            $meta = (array)$email->meta;
            $recipientId = $meta['recipient_id'] ?? null;
            $user = $recipientId ? $users->get($recipientId) : null;

            $row['recipient_id'] = $recipientId;
            $row['recipient_name'] = $user?->name ?? ($meta['recipient_name'] ?? null);
            $row['subscriber_id'] = $user?->subscriber?->id;

            return $row;
        });

        $data = [
            'items' => $transformed->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ];

        return response()->json([
            'success'  => true,
            'messages' => ['Данные получены'],
            'data'     => $data,
        ], 200);
    }

    public function show(SentEmail $sentEmail): JsonResponse
    {
        $meta = (array)$sentEmail->meta;

        $recipientId = $meta['recipient_id'] ?? null;
        $user = $recipientId ? User::with('subscriber')->find($recipientId) : null;

        $row = $sentEmail->toArray();
        $row['recipient_id'] = $recipientId;
        $row['recipient_name'] = $user?->name ?? ($meta['recipient_name'] ?? null);
        $row['subscriber_id'] = $user?->subscriber?->id;

        return response()->json([
            'success'  => true,
            'messages' => ['Данные получены'],
            'data'     => $row,
        ], 200);
    }
}
