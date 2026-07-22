<?php

namespace Tests\Unit\Support;

use App\Models\JobStatus;
use App\Support\ProfitabilityJobStatusPresenter;
use Tests\TestCase;

class ProfitabilityJobStatusPresenterTest extends TestCase
{
    public function test_presenter_returns_queued_status_for_new_job(): void
    {
        $record = new JobStatus([
            'status' => 'processing',
            'error' => null,
            'data' => ProfitabilityJobStatusPresenter::initialQueuedData(10, 5),
        ]);

        $payload = ProfitabilityJobStatusPresenter::fromRecord($record);

        $this->assertSame('processing', $payload['status']);
        $this->assertSame('queued', $payload['stage']);
        $this->assertSame(5, $payload['progress_percent']);
        $this->assertSame('Скоро начнём', $payload['status_label']);
        $this->assertStringContainsString('Запрос принят', $payload['status_detail']);
    }

    public function test_presenter_increases_fetch_progress_with_batches(): void
    {
        $record = new JobStatus([
            'status' => 'processing',
            'error' => null,
            'data' => [
                'stage' => ProfitabilityJobStatusPresenter::STAGE_FETCHING,
                'batch' => 4,
                'rows_loaded' => 120000,
                'waiting_for_api' => true,
                'started_at' => now()->toIso8601String(),
            ],
        ]);

        $payload = ProfitabilityJobStatusPresenter::fromRecord($record);

        $this->assertSame(36, $payload['progress_percent']);
        $this->assertSame('Ждём данные от Wildberries', $payload['status_label']);
        $this->assertStringContainsString('120 000', $payload['status_detail']);
        $this->assertStringContainsString('операций', $payload['status_detail']);
        $this->assertStringNotContainsString('Пакет', $payload['status_detail']);
    }
}
