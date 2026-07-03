<?php

namespace App\Services\Admin;

use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AdminRepricerService
{
    public function paginateCabinets(int $perPage = 25): LengthAwarePaginator
    {
        return RepricerCabinets::query()
            ->select(['id', 'user_id', 'name', 'created_at'])
            ->with([
                'user' => function ($query) {
                    $query->select('id', 'name', 'email')->with([
                        'subscriber' => fn ($q) => $q->select('id', 'user_id'),
                    ]);
                },
            ])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function paginateNmIds(?int $cabinetId, int $perPage = 25): LengthAwarePaginator
    {
        $query = RepricerSettings::query()
            ->with([
                'cabinet' => function ($query) {
                    $query->select('id', 'user_id', 'name')->with([
                        'user' => function ($query) {
                            $query->select('id', 'name', 'email')->with([
                                'subscriber' => fn ($q) => $q->select('id', 'user_id'),
                            ]);
                        },
                    ]);
                },
                'logs' => function ($query) {
                    $query->select('nmID', 'type', 'message', 'created_at')
                        ->limit(50)
                        ->orderByDesc('created_at');
                },
            ]);

        if ($cabinetId) {
            $query->where('cabinet_id', $cabinetId);
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }
}