<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class CandlestickApiAccessTest extends TestCase
{
    public function test_dashboard_data_api_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/candlestick')
            ->assertUnauthorized()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->getJson('/api/dashboard/units')
            ->assertUnauthorized();

        $this->getJson('/api/snapshots/1/export')
            ->assertUnauthorized();
    }

    public function test_candlestick_api_rejects_conflicting_or_invalid_range_parameters(): void
    {
        $this->actingAs(new User)
            ->getJson('/api/dashboard/candlestick?period=last_30_days&year=2026&month=9')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');

        $this->actingAs(new User)
            ->getJson('/api/dashboard/candlestick?start_date=2026-09-10')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');
    }

    public function test_snapshot_day_api_requires_a_valid_date(): void
    {
        $this->actingAs(new User)
            ->getJson('/api/snapshots?date=15-09-2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }
}
