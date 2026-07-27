<?php

namespace App\Services\Admin;

use App\Models\ExtraLimits;
use Illuminate\Database\Eloquent\Collection;

class AdminExtraLimitService
{
    public function all(): Collection
    {
        return ExtraLimits::query()->orderBy('order')->orderBy('id')->get();
    }

    public function create(array $data): ExtraLimits
    {
        return ExtraLimits::create([
            'slug' => $data['slug'],
            'name' => $data['name'],
            'price' => $data['price'],
            'order' => $data['order'] ?? 0,
        ]);
    }

    public function update(ExtraLimits $extraLimit, array $data): ExtraLimits
    {
        $extraLimit->update([
            'slug' => $data['slug'],
            'name' => $data['name'],
            'price' => $data['price'],
            'order' => $data['order'] ?? 0,
        ]);

        return $extraLimit->fresh();
    }

    public function delete(ExtraLimits $extraLimit): void
    {
        $extraLimit->delete();
    }
}
