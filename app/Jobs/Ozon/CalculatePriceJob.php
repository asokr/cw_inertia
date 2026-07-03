<?php

namespace App\Jobs\Ozon;

use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcFbo;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcFbs;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CalculatePriceJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 час

    public function __construct(
        private readonly int $cabinetId,
        private readonly string $type
    ) {}

    public function handle(): void
    {
        try {
            if ($this->type === 'fbs') {
                $this->calculateFbs();
            } else {
                $this->calculateFbo();
            }
        } catch (\Throwable $e) {
            Log::error("CalculatePriceJob failed for {$this->type} cabinet {$this->cabinetId}: " . $e->getMessage());
            throw $e;
        } finally {
            $cacheKey = sprintf('ozon_price_calc_calculate_%s_%s', $this->type, $this->cabinetId);
            Cache::forget($cacheKey);
        }
    }

    private function calculateFbs(): void
    {
        $updated = 0;
        $skipped = 0;

        OzPriceCalcFbs::where('cabinet_id', $this->cabinetId)
            ->orderBy('id')
            ->chunkById(500, function ($items) use (&$updated, &$skipped) {
                foreach ($items as $item) {
                    $checkFields = [
                        'cost_price',
                        'margin_percent',
                        'fulfillment_fee',
                        'dop_rashod_percent',
                        'weight_kg',
                        'length_cm',
                        'width_cm',
                        'height_cm',
                        'buyout_percent',
                        'tax_percent',
                        'commission_percent',
                        'advertising_percent',
                        'promotion_percent',
                    ];

                    $hasError = false;
                    foreach ($checkFields as $field) {
                        if (abs(((float) $item->{$field}) - (-1.0)) < 1e-6) {
                            $hasError = true;
                            break;
                        }
                    }

                    if ($hasError) {
                        $skipped++;
                        continue;
                    }

                    $costPrice = (float) $item->cost_price;
                    $marginPercent = (float) $item->margin_percent;
                    $fulfillmentFee = (float) $item->fulfillment_fee;
                    $dopRashodPercent = (float) $item->dop_rashod_percent;
                    $lengthCm = $item->length_cm !== null ? (float) $item->length_cm : null;
                    $widthCm = $item->width_cm !== null ? (float) $item->width_cm : null;
                    $heightCm = $item->height_cm !== null ? (float) $item->height_cm : null;
                    $buyoutPercent = (float) $item->buyout_percent;
                    $taxPercent = (float) $item->tax_percent;
                    $commissionPercent = (float) $item->commission_percent;
                    $advertisingPercent = (float) $item->advertising_percent;
                    $promotionPercent = (float) $item->promotion_percent;
                    $volumeLiters = $this->calculateVolume($lengthCm, $widthCm, $heightCm);

                    $item->volume_liters = $volumeLiters;

                    $logisticsFbs = $this->calculateBaseLogistics($volumeLiters);
                    $logisticsFbsWithBuyout = $this->calculateLogisticsWithBuyout($logisticsFbs, $buyoutPercent);

                    $item->logistics_fbs_over_190 = $logisticsFbsWithBuyout;
                    $item->logistics_fbs = $logisticsFbs;

                    $denominator = (100 - $dopRashodPercent) / 100;

                    if (abs($denominator) < 1e-6) {
                        $item->stop_price = null;
                        $item->min_price = null;
                        $item->current_price = null;
                        $item->save();
                        $skipped++;
                        continue;
                    }

                    $stopPrice = ceil((ceil($costPrice * ((100 + $marginPercent) / 100)) + $fulfillmentFee) / $denominator);

                    $item->stop_price = $stopPrice;

                    $minPrice = null;

                    if ($logisticsFbsWithBuyout !== null) {
                        $percentSum = $advertisingPercent + $commissionPercent + $taxPercent + 1.5;
                        $minPriceDenominator = 1 - ($percentSum / 100);

                        if (abs($minPriceDenominator) > 1e-6) {
                            $minPrice = (int) ceil(($stopPrice + $logisticsFbsWithBuyout + 65) / $minPriceDenominator);
                        }
                    }

                    $currentPrice = null;

                    if ($minPrice !== null) {
                        $currentPriceDenominator = (100 - $promotionPercent) / 100;

                        if (abs($currentPriceDenominator) > 1e-6) {
                            $currentPrice = (int) round($minPrice / $currentPriceDenominator);
                        }
                    }

                    $item->min_price = $minPrice;
                    $item->current_price = $currentPrice;
                    $item->save();
                    $updated++;
                }
            });
    }

    private function calculateFbo(): void
    {
        $updated = 0;
        $skipped = 0;

        OzPriceCalcFbo::where('cabinet_id', $this->cabinetId)
            ->orderBy('id')
            ->chunkById(500, function ($items) use (&$updated, &$skipped) {
                foreach ($items as $item) {
                    $checkFields = [
                        'cost_price',
                        'margin_percent',
                        'fulfillment_fee',
                        'dop_rashod_percent',
                        'weight_kg',
                        'length_cm',
                        'width_cm',
                        'height_cm',
                        'buyout_percent',
                        'price_markup_for_logistics_percent',
                        'dopakovka_rub',
                        'tax_percent',
                        'commission_percent',
                        'advertising_percent',
                        'promotion_percent',
                    ];

                    $hasError = false;
                    foreach ($checkFields as $field) {
                        if (abs(((float) $item->{$field}) - (-1.0)) < 1e-6) {
                            $hasError = true;
                            break;
                        }
                    }

                    if ($hasError) {
                        $skipped++;
                        continue;
                    }

                    $costPrice = (float) $item->cost_price;
                    $marginPercent = (float) $item->margin_percent;
                    $fulfillmentFee = (float) $item->fulfillment_fee;
                    $dopRashodPercent = (float) $item->dop_rashod_percent;
                    $lengthCm = $item->length_cm !== null ? (float) $item->length_cm : null;
                    $widthCm = $item->width_cm !== null ? (float) $item->width_cm : null;
                    $heightCm = $item->height_cm !== null ? (float) $item->height_cm : null;
                    $buyoutPercent = (float) $item->buyout_percent;
                    $priceMarkupForLogisticsPercent = (float) $item->price_markup_for_logistics_percent;
                    $dopakovkaRub = (float) $item->dopakovka_rub;
                    $taxPercent = (float) $item->tax_percent;
                    $commissionPercent = (float) $item->commission_percent;
                    $advertisingPercent = (float) $item->advertising_percent;
                    $promotionPercent = (float) $item->promotion_percent;
                    $volumeLiters = $this->calculateVolume($lengthCm, $widthCm, $heightCm);

                    $item->volume_liters = $volumeLiters;

                    $logisticsFbo = $this->calculateBaseLogistics($volumeLiters);
                    $logisticsFboWithBuyout = $this->calculateLogisticsWithBuyout($logisticsFbo, $buyoutPercent);
                    $acceptanceFbo = $volumeLiters !== null && $volumeLiters > 0 ? (5 + ($volumeLiters - 1)) : null;

                    $item->logistics_fbo_over_190 = $logisticsFboWithBuyout;
                    $item->logistics_fbo = $logisticsFbo;
                    $item->acceptance_fbo = $acceptanceFbo;

                    $denominator = (100 - $dopRashodPercent) / 100;

                    if (abs($denominator) < 1e-6) {
                        $item->stop_price = null;
                        $item->save();
                        $skipped++;
                        continue;
                    }

                    $stopPrice = ceil((ceil($costPrice * ((100 + $marginPercent) / 100)) + $fulfillmentFee) / $denominator);

                    $item->stop_price = $stopPrice;

                    $minPrice = null;

                    if ($logisticsFboWithBuyout !== null && $acceptanceFbo !== null) {
                        $percentSum = $advertisingPercent + $commissionPercent + $taxPercent + $priceMarkupForLogisticsPercent + 1.5;
                        $minPriceDenominator = 1 - ($percentSum / 100);

                        if (abs($minPriceDenominator) > 1e-6) {
                            $minPrice = (int) ceil(($stopPrice + $logisticsFboWithBuyout + $acceptanceFbo + $dopakovkaRub + 45) / $minPriceDenominator);
                        }
                    }

                    $item->min_price = $minPrice;

                    $currentPrice = null;

                    if ($minPrice !== null) {
                        $currentPriceDenominator = (100 - $promotionPercent) / 100;

                        if (abs($currentPriceDenominator) > 1e-6) {
                            $currentPrice = (int) round($minPrice / $currentPriceDenominator);
                        }
                    }

                    $item->current_price = $currentPrice;
                    $item->save();
                    $updated++;
                }
            });
    }

    private function calculateVolume(?float $lengthCm, ?float $widthCm, ?float $heightCm): ?int
    {
        if ($lengthCm === null || $widthCm === null || $heightCm === null) {
            return null;
        }

        if ($lengthCm <= 0 || $widthCm <= 0 || $heightCm <= 0) {
            return null;
        }

        return (int) ceil(($lengthCm * $widthCm * $heightCm) / 1000);
    }

    private function calculateBaseLogistics(?int $volumeLiters): ?int
    {
        if ($volumeLiters === null || $volumeLiters <= 0) {
            return null;
        }

        $tariffs = [
            1 => 71,
            2 => 74,
            4 => 78,
            6 => 89,
            8 => 99,
            10 => 100,
            13 => 102,
            14 => 106,
            15 => 111,
            17 => 119,
            20 => 131,
            25 => 143,
            30 => 162,
            35 => 177,
            40 => 195,
            45 => 209,
            50 => 228,
            60 => 244,
            70 => 279,
            80 => 299,
            90 => 344,
            100 => 371,
            125 => 436,
            150 => 503,
            175 => 578,
            200 => 692,
            400 => 1026,
            600 => 1457,
            800 => 1891,
        ];

        foreach ($tariffs as $maxVolume => $value) {
            if ($volumeLiters <= $maxVolume) {
                return $value;
            }
        }

        return 2232;
    }

    private function calculateLogisticsWithBuyout(?int $logistics, float $buyoutPercent): ?int
    {
        if ($logistics === null || abs($buyoutPercent) < 1e-6) {
            return null;
        }

        $result = (($logistics * 100) + ((100 - $buyoutPercent) * $logistics)) / $buyoutPercent;

        return (int) round($result);
    }
}
