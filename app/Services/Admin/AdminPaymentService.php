<?php

namespace App\Services\Admin;

use App\Models\PaymentsTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AdminPaymentService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $perPage = (int) ($filters['per_page'] ?? 15);

        return $this->baseQuery()
            ->orderBy($sortField, $sortOrder)
            ->paginate($perPage);
    }

    public function paginateForWidget(int $rows, string $sortField = 'id', string $sortOrder = '-1'): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->orderBy($sortField, $sortOrder === '1' ? 'asc' : 'desc')
            ->paginate($rows);
    }

    private function baseQuery()
    {
        return PaymentsTransaction::select([
            'id', 'user_id', 'amount', 'description', 'status', 'system', 'created_at',
        ])
            ->with([
                'user' => function ($query) {
                    $query->select('id', 'name', 'email')->with([
                        'subscriber' => function ($query) {
                            $query->select('id', 'user_id');
                        },
                    ]);
                },
            ]);
    }
}