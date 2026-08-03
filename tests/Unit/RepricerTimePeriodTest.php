<?php

namespace Tests\Unit;

use App\Support\Wb\RepricerTimePeriod;
use PHPUnit\Framework\TestCase;

class RepricerTimePeriodTest extends TestCase
{
    public function test_detects_daily_and_absolute_formats(): void
    {
        $this->assertTrue(RepricerTimePeriod::isDailyTime('09:30'));
        $this->assertFalse(RepricerTimePeriod::isDailyTime('2026-08-01 09:30:00'));

        $this->assertTrue(RepricerTimePeriod::isAbsoluteDateTime('2026-08-01 09:30:00'));
        $this->assertTrue(RepricerTimePeriod::isAbsoluteDateTime('2026-08-01 09:30'));
        $this->assertTrue(RepricerTimePeriod::isAbsoluteDateTime('2026-08-01T09:30'));
        $this->assertFalse(RepricerTimePeriod::isAbsoluteDateTime('09:30'));
    }

    public function test_normalizes_terms_to_canonical_storage(): void
    {
        $normalized = RepricerTimePeriod::normalizeTerms([
            ['start' => '09:00', 'end' => '18:00', 'value' => 100],
            ['start' => '2026-08-01T10:00', 'end' => '2026-08-07T22:00', 'value' => 1500],
        ]);

        $this->assertSame('09:00', $normalized[0]['start']);
        $this->assertSame('18:00', $normalized[0]['end']);
        $this->assertSame('2026-08-01 10:00:00', $normalized[1]['start']);
        $this->assertSame('2026-08-07 22:00:00', $normalized[1]['end']);
    }

    public function test_detects_absolute_period_overlap(): void
    {
        $error = RepricerTimePeriod::validateNoOverlap([
            ['start' => '2026-08-01 10:00:00', 'end' => '2026-08-05 12:00:00'],
            ['start' => '2026-08-04 00:00:00', 'end' => '2026-08-10 00:00:00'],
        ]);

        $this->assertNotNull($error);
        $this->assertStringContainsString('пересекаются', $error);
    }

    public function test_allows_non_overlapping_absolute_periods(): void
    {
        $error = RepricerTimePeriod::validateNoOverlap([
            ['start' => '2026-08-01 10:00:00', 'end' => '2026-08-03 12:00:00'],
            ['start' => '2026-08-04 00:00:00', 'end' => '2026-08-10 00:00:00'],
        ]);

        $this->assertNull($error);
    }
}
