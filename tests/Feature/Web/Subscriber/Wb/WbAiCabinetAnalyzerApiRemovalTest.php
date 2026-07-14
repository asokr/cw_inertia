<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use Tests\Feature\Web\Auth\WebAuthTestCase;

class WbAiCabinetAnalyzerApiRemovalTest extends WebAuthTestCase
{
    public function test_legacy_wb_ai_cabinet_analyzer_api_routes_are_removed(): void
    {
        $this->getJson('/api/subscriber/wb/ai-cabinet-analyzer/cabinets')
            ->assertNotFound();

        $this->postJson('/api/subscriber/wb/ai-cabinet-analyzer/reports/start', ['cabinet_id' => 1])
            ->assertNotFound();

        $this->getJson('/api/subscriber/wb/ai-cabinet-analyzer/ai-templates')
            ->assertNotFound();

        $this->postJson('/api/subscriber/wb/ai-cabinet-analyzer/ai-analyses/start', ['report_id' => 1, 'template_id' => 1])
            ->assertNotFound();
    }
}