<?php

namespace App\Jobs\Wb\AbTesting;

use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Subscriber\Wb\WbAbTestingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnrichAbProductRatingsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 3600;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(
        public readonly int $cabinetId,
    ) {
    }

    public function uniqueId(): string
    {
        return 'wb-ab-testing-ratings-'.$this->cabinetId;
    }

    public function handle(WbAbTestingService $abTestingService): void
    {
        $cabinet = WbCabinet::query()->find($this->cabinetId);

        if (! $cabinet || empty($cabinet->apikey)) {
            return;
        }

        $result = $abTestingService->enrichRatingsFromItemRatingApi($cabinet);

        if (! ($result['success'] ?? false)) {
            Log::warning('WB A/B testing: ratings enrichment finished with errors', [
                'cabinet_id' => $this->cabinetId,
                'messages' => $result['messages'] ?? [],
                'updated' => $result['updated'] ?? 0,
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('WB A/B testing: ratings job failed', [
            'cabinet_id' => $this->cabinetId,
            'message' => $exception->getMessage(),
        ]);
    }
}
