<?php

namespace Tests\Feature\Web\Subscriber\Ai;

use Tests\Feature\Web\Auth\WebAuthTestCase;

class AiApiRemovalTest extends WebAuthTestCase
{
    public function test_legacy_ai_api_routes_are_removed(): void
    {
        $this->postJson('/api/subscriber/ai/marketplace', ['task_type' => 'rewrite_text'])
            ->assertNotFound();

        $this->postJson('/api/subscriber/ai/image/start', ['task_type' => 'generate_image'])
            ->assertNotFound();

        $this->getJson('/api/subscriber/ai/image/generations')
            ->assertNotFound();

        $this->postJson('/api/subscriber/ai/video/start', ['task_type' => 'generate_video', 'prompt' => 'test'])
            ->assertNotFound();

        $this->getJson('/api/subscriber/ai/video/generations')
            ->assertNotFound();
    }
}