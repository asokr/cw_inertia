<?php

namespace App\Services\Admin;

use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerCabinet;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerTemplate;
use Illuminate\Database\Eloquent\Collection;

class AdminAiCabinetService
{
    public function listCabinets(): Collection
    {
        return AiCabinetAnalyzerCabinet::with([
            'user' => function ($query) {
                $query->select('id', 'name', 'email')->with([
                    'subscriber' => fn ($q) => $q->select('id', 'user_id'),
                ]);
            },
        ])
            ->select(['id', 'user_id', 'name', 'created_at', 'updated_at'])
            ->orderByDesc('id')
            ->get()
            ->map(function (AiCabinetAnalyzerCabinet $cabinet) {
                $user = $cabinet->user;
                $cabinet->owner = $user
                    ? ($user->email ?: $user->name ?: 'User #' . $user->id)
                    : 'Unknown (user_id: ' . $cabinet->user_id . ')';
                $cabinet->subscriber_id = ($user && $user->subscriber) ? $user->subscriber->id : null;

                return $cabinet;
            });
    }

    public function listTemplates(): Collection
    {
        return AiCabinetAnalyzerTemplate::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function createTemplate(array $data): AiCabinetAnalyzerTemplate
    {
        return AiCabinetAnalyzerTemplate::create([
            'name' => (string) $data['name'],
            'description' => isset($data['description']) ? (string) $data['description'] : null,
            'system_prompt' => (string) $data['system_prompt'],
            'sort_order' => (int) ($data['sort_order'] ?? 100),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'response_format' => (string) ($data['response_format'] ?? 'json'),
        ]);
    }

    public function updateTemplate(AiCabinetAnalyzerTemplate $template, array $data): AiCabinetAnalyzerTemplate
    {
        $template->name = (string) $data['name'];
        $template->description = isset($data['description']) ? (string) $data['description'] : null;
        $template->system_prompt = (string) $data['system_prompt'];
        $template->sort_order = (int) ($data['sort_order'] ?? $template->sort_order ?? 100);

        if (array_key_exists('is_active', $data)) {
            $template->is_active = (bool) $data['is_active'];
        }

        if (array_key_exists('response_format', $data)) {
            $template->response_format = (string) $data['response_format'];
        }

        $template->save();

        return $template;
    }

    public function deleteTemplate(AiCabinetAnalyzerTemplate $template): void
    {
        $template->delete();
    }
}