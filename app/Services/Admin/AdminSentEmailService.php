<?php

namespace App\Services\Admin;

use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AdminSentEmailService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = (int) ($filters['per_page'] ?? 20);
        $sort = $filters['sort'] ?? 'created_at';
        $order = ($filters['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $allowedSort = ['id', 'created_at', 'subject'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'created_at';
        }

        $query = SentEmail::query();

        if ($search !== '') {
            $safe = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $query->where('subject', 'like', '%' . $safe . '%');
        }

        $paginator = $query->orderBy($sort, $order)->paginate($perPage);
        $enriched = $this->enrichItems(collect($paginator->items()));

        return new LengthAwarePaginator(
            $enriched->values()->all(),
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    public function show(SentEmail $sentEmail): array
    {
        return $this->enrichItem($sentEmail);
    }

    private function enrichItems(Collection $items): Collection
    {
        $recipientIds = $items
            ->map(fn (SentEmail $email) => ((array) $email->meta)['recipient_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        $users = User::with('subscriber')
            ->whereIn('id', $recipientIds)
            ->get()
            ->keyBy('id');

        return $items->map(fn (SentEmail $email) => $this->enrichItem($email, $users));
    }

    private function enrichItem(SentEmail $email, ?Collection $users = null): array
    {
        $row = $email->toArray();
        $meta = (array) $email->meta;
        $recipientId = $meta['recipient_id'] ?? null;
        $user = $recipientId
            ? ($users?->get($recipientId) ?? User::with('subscriber')->find($recipientId))
            : null;

        $row['recipient_id'] = $recipientId;
        $row['recipient_name'] = $user?->name ?? ($meta['recipient_name'] ?? null);
        $row['subscriber_id'] = $user?->subscriber?->id;

        return $row;
    }
}