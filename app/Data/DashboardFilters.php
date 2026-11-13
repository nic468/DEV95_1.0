<?php

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class DashboardFilters
{
    public function __construct(
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public ?string $costCenter,
        public ?string $movementType,
        public ?string $unit,
    ) {}
}
