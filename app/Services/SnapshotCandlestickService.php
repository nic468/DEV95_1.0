<?php

namespace App\Services;

use App\Data\DashboardFilters;
use App\Models\UploadSnapshot;

class SnapshotCandlestickService
{
    private const METRIC_COLUMNS = [
        'cost' => 'total_cost',
        'records' => 'record_count',
        'distinct_work_orders' => 'distinct_work_orders',
        'scrap_m2' => 'scrap_total_m2',
    ];

    public function __construct(
        private readonly SnapshotMetricQuery $metricQuery
    ) {}

    /**
     * @return list<array{
     *     date: string,
     *     open: int|float,
     *     high: int|float,
     *     low: int|float,
     *     close: int|float,
     *     variation: int|float,
     *     variation_percent: float|null,
     *     update_count: int,
     *     derived: bool,
     *     doji: bool
     * }>
     */
    public function candles(
        string $metric,
        DashboardFilters $filters,
    ): array {
        if (! isset(self::METRIC_COLUMNS[$metric])) {
            throw new \InvalidArgumentException("Metrica de candlestick desconhecida: {$metric}.");
        }

        $snapshots = UploadSnapshot::query()
            ->whereBetween('reference_date', [
                $filters->startDate->toDateString(),
                $filters->endDate->toDateString(),
            ])
            ->orderBy('reference_date')
            ->orderBy('version')
            ->get();

        if ($snapshots->isEmpty()) {
            return [];
        }

        $previousSnapshot = UploadSnapshot::query()
            ->where('reference_date', '<', $filters->startDate->toDateString())
            ->orderByDesc('reference_date')
            ->orderByDesc('version')
            ->first();

        $snapshotIds = $snapshots->pluck('id')->all();

        if ($previousSnapshot !== null) {
            $snapshotIds[] = $previousSnapshot->id;
        }

        $this->metricQuery->assertCalculated($snapshotIds);
        $valuesBySnapshot = $this->metricQuery->forSnapshots($snapshotIds, $filters);

        $valueFor = function (UploadSnapshot $snapshot) use ($valuesBySnapshot, $metric): int|float {
            $row = $valuesBySnapshot->get($snapshot->id);
            $values = $this->metricQuery->values($row);
            $value = $values[self::METRIC_COLUMNS[$metric]];

            return in_array($metric, ['records', 'distinct_work_orders'], true)
                ? (int) $value
                : (float) $value;
        };

        $lastKnownClose = $previousSnapshot === null ? null : $valueFor($previousSnapshot);
        $candles = [];

        foreach ($snapshots->groupBy(fn (UploadSnapshot $snapshot): string => $snapshot->reference_date->format('Y-m-d')) as $date => $daySnapshots) {
            $values = $daySnapshots->map($valueFor)->all();
            $singleUpdate = count($values) === 1;
            $close = $values[array_key_last($values)];
            $derived = $singleUpdate && $lastKnownClose !== null;
            $open = $derived ? $lastKnownClose : $values[0];
            $variation = $close - $open;

            $candles[] = [
                'date' => $date,
                'open' => $open,
                'high' => max($values),
                'low' => min($values),
                'close' => $close,
                'variation' => $variation,
                'variation_percent' => $open == 0
                    ? null
                    : round(($variation / $open) * 100, 2),
                'update_count' => count($values),
                'derived' => $derived,
                'doji' => $singleUpdate && ! $derived,
            ];

            $lastKnownClose = $close;
        }

        return $candles;
    }
}
