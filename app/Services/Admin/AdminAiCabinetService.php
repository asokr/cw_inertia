<?php

namespace App\Services\Admin;

use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerTemplate;
use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Database\Eloquent\Collection;

class AdminAiCabinetService
{
    public function listCabinets(): Collection
    {
        return WbCabinet::with([
            'user' => function ($query) {
                $query->select('id', 'name', 'email')->with([
                    'subscriber' => fn ($q) => $q->select('id', 'user_id'),
                ]);
            },
        ])
            ->select(['id', 'user_id', 'name', 'created_at', 'updated_at'])
            ->orderByDesc('id')
            ->get()
            ->map(function (WbCabinet $cabinet) {
                $user = $cabinet->user;
                $cabinet->owner = $user
                    ? ($user->email ?: $user->name ?: 'User #'.$user->id)
                    : 'Unknown (user_id: '.$cabinet->user_id.')';
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
            'data_sources' => $this->normalizeDataSources($data['data_sources'] ?? null),
            ...$this->creditsCostAttributes($data),
        ]);
    }

    public function updateTemplate(AiCabinetAnalyzerTemplate $template, array $data): AiCabinetAnalyzerTemplate
    {
        $template->name = (string) $data['name'];
        $template->description = isset($data['description']) ? (string) $data['description'] : null;
        $template->system_prompt = (string) $data['system_prompt'];
        $template->sort_order = (int) ($data['sort_order'] ?? $template->sort_order ?? 100);
        $template->is_active = (bool) ($data['is_active'] ?? $template->is_active);
        if (array_key_exists('response_format', $data)) {
            $template->response_format = (string) $data['response_format'];
        }
        if (array_key_exists('data_sources', $data)) {
            $template->data_sources = $this->normalizeDataSources($data['data_sources']);
        }
        $creditsCost = $this->creditsCostAttributes($data);
        if ($creditsCost !== []) {
            $template->credits_cost = $creditsCost['credits_cost'];
        }
        $template->save();

        return $template;
    }

    /**
     * @param  mixed  $sources
     * @return list<string>
     */
    private function normalizeDataSources(mixed $sources): array
    {
        $allowed = array_flip(AiCabinetAnalyzerTemplate::DATA_SOURCES);
        $resolved = [];

        foreach ((array) $sources as $source) {
            $key = (string) $source;
            if (isset($allowed[$key]) && ! in_array($key, $resolved, true)) {
                $resolved[] = $key;
            }
        }

        return $resolved !== [] ? $resolved : AiCabinetAnalyzerTemplate::DATA_SOURCES;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{credits_cost?: int}
     */
    private function creditsCostAttributes(array $data): array
    {
        if (! array_key_exists('credits_cost', $data) || $data['credits_cost'] === null || $data['credits_cost'] === '') {
            return [];
        }

        $cost = (int) $data['credits_cost'];

        return $cost > 0 ? ['credits_cost' => $cost] : [];
    }

    public function deleteTemplate(AiCabinetAnalyzerTemplate $template): void
    {
        $template->delete();
    }
}
