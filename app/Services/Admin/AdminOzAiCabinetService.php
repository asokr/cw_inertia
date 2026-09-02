<?php

namespace App\Services\Admin;

use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerTemplate;
use App\Models\Subscribers\Oz\OzCabinet;
use Illuminate\Database\Eloquent\Collection;

class AdminOzAiCabinetService
{
    public function listCabinets(): Collection
    {
        return OzCabinet::with([
            'user' => function ($query) {
                $query->select('id', 'name', 'email')->with([
                    'subscriber' => fn ($q) => $q->select('id', 'user_id'),
                ]);
            },
        ])
            ->select(['id', 'user_id', 'name', 'client_id', 'created_at', 'updated_at'])
            ->orderByDesc('id')
            ->get()
            ->map(function (OzCabinet $cabinet) {
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
        return OzAiCabinetAnalyzerTemplate::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function createTemplate(array $data): OzAiCabinetAnalyzerTemplate
    {
        return OzAiCabinetAnalyzerTemplate::create([
            'name' => (string) $data['name'],
            'description' => isset($data['description']) ? (string) $data['description'] : null,
            'system_prompt' => (string) $data['system_prompt'],
            'sort_order' => (int) ($data['sort_order'] ?? 100),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'response_format' => (string) ($data['response_format'] ?? 'json'),
            'data_sources' => $this->normalizeDataSources($data['data_sources'] ?? null),
        ]);
    }

    public function updateTemplate(OzAiCabinetAnalyzerTemplate $template, array $data): OzAiCabinetAnalyzerTemplate
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
        $template->save();

        return $template;
    }

    /**
     * @param  mixed  $sources
     * @return list<string>
     */
    private function normalizeDataSources(mixed $sources): array
    {
        $allowed = array_flip(OzAiCabinetAnalyzerTemplate::DATA_SOURCES);
        $resolved = [];

        foreach ((array) $sources as $source) {
            $key = (string) $source;
            if (isset($allowed[$key]) && ! in_array($key, $resolved, true)) {
                $resolved[] = $key;
            }
        }

        return $resolved !== [] ? $resolved : OzAiCabinetAnalyzerTemplate::DATA_SOURCES;
    }

    public function deleteTemplate(OzAiCabinetAnalyzerTemplate $template): void
    {
        $template->delete();
    }
}
