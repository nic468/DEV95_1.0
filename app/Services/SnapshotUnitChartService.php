<?php

namespace App\Services;

use App\Data\DashboardFilters;
use App\Models\UploadSnapshot;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SnapshotUnitChartService
{
    private const ALL = '__ALL__';

    public function __construct(
        private readonly SnapshotMetricQuery $metrics
    ) {}

    public function charts(DashboardFilters $filters): array
    {
        $snapshotsByDay = UploadSnapshot::query()
            ->whereBetween('reference_date', [
                $filters->startDate->toDateString(),
                $filters->endDate->toDateString(),
            ])
            ->orderBy('reference_date')
            ->orderBy('version')
            ->get()
            ->groupBy(fn (UploadSnapshot $snapshot): string => $snapshot->reference_date->format('Y-m-d'))
            ->map(fn (Collection $versions): UploadSnapshot => $versions->last())
            ->values();

        if ($snapshotsByDay->isEmpty()) {
            return [
                'latest_snapshot' => null,
                'units' => [],
                'filters' => $this->filterValues($filters),
            ];
        }

        $snapshotIds = $snapshotsByDay
            ->map(fn (UploadSnapshot $snapshot): int => (int) $snapshot->id)
            ->all();
        $this->metrics->assertCalculated($snapshotIds);

        $baseQuery = $this->filteredMetricsQuery($snapshotIds, $filters);
        $dailyTotals = (clone $baseQuery)
            ->select(['metrics.snapshot_id', 'metrics.unit_code_key'])
            ->selectRaw('SUM(metrics.total_cost) AS total_cost')
            ->selectRaw('SUM(metrics.quantity_total) AS quantity_total')
            ->groupBy('metrics.snapshot_id', 'metrics.unit_code_key')
            ->get()
            ->groupBy('snapshot_id');
        $dailyCenters = (clone $baseQuery)
            ->leftJoin('cost_centers AS centers', 'centers.code', '=', 'metrics.cost_center_key')
            ->select([
                'metrics.snapshot_id',
                'metrics.unit_code_key',
                'metrics.cost_center_key',
            ])
            ->selectRaw('COALESCE(centers.name, metrics.cost_center_key) AS cost_center_name')
            ->selectRaw('SUM(metrics.total_cost) AS total_cost')
            ->groupBy(
                'metrics.snapshot_id',
                'metrics.unit_code_key',
                'metrics.cost_center_key',
                'centers.name'
            )
            ->orderBy('metrics.unit_code_key')
            ->orderBy('metrics.cost_center_key')
            ->get()
            ->groupBy('snapshot_id');

        $observedUnitCodes = $dailyTotals
            ->flatMap(fn (Collection $rows): Collection => $rows->pluck('unit_code_key'))
            ->unique()
            ->values()
            ->all();
        if ($filters->unit !== null) {
            $unitCodes = [$filters->unit];
        } else {
            $unitCodes = DB::table('units_of_measure')
                ->orderBy('code')
                ->pluck('code')
                ->map(fn ($code): string => (string) $code)
                ->all();
            $unitCodes = array_values(array_unique([...$unitCodes, ...$observedUnitCodes]));
        }
        sort($unitCodes, SORT_STRING);

        $latestSnapshot = $snapshotsByDay->last();
        $latestSnapshotId = (int) $latestSnapshot->id;
        $latestSnapshotTotals = $dailyTotals->get($latestSnapshotId, collect());
        $latestSnapshotCenters = $dailyCenters->get($latestSnapshotId, collect());

        $units = array_map(function (string $unitCode) use (
            $snapshotsByDay,
            $dailyTotals,
            $latestSnapshotTotals,
            $latestSnapshotCenters
        ): array {
            $latestTotal = $latestSnapshotTotals->firstWhere('unit_code_key', $unitCode);
            $centerRows = $latestSnapshotCenters
                ->where('unit_code_key', $unitCode)
                ->map(fn (object $row): array => [
                    'code' => (string) $row->cost_center_key,
                    'name' => (string) $row->cost_center_name,
                    'total_cost' => (string) $row->total_cost,
                ])
                ->values()
                ->all();

            return [
                'code' => $unitCode,
                'quantity_total' => (string) ($latestTotal->quantity_total ?? '0.000000'),
                'total_cost' => (string) ($latestTotal->total_cost ?? '0.00'),
                'daily' => $snapshotsByDay
                    ->map(function (UploadSnapshot $snapshot) use ($dailyTotals, $unitCode): array {
                        $snapshotRows = $dailyTotals->get((int) $snapshot->id, collect());
                        $row = $snapshotRows->firstWhere('unit_code_key', $unitCode);

                        return [
                            'date' => $snapshot->reference_date->format('Y-m-d'),
                            'snapshot_version' => (int) $snapshot->version,
                            'quantity_total' => (string) ($row->quantity_total ?? '0.000000'),
                            'total_cost' => (string) ($row->total_cost ?? '0.00'),
                        ];
                    })
                    ->values()
                    ->all(),
                'cost_centers' => $centerRows,
            ];
        }, $unitCodes);

        return [
            'latest_snapshot' => [
                'reference_date' => $latestSnapshot->reference_date->format('Y-m-d'),
                'version' => (int) $latestSnapshot->version,
                'id' => $latestSnapshotId,
            ],
            'units' => $units,
            'filters' => $this->filterValues($filters),
        ];
    }

    /**
     * @param  list<int>  $snapshotIds
     */
    private function filteredMetricsQuery(array $snapshotIds, DashboardFilters $filters): Builder
    {
        $groupingMask = 1 | 4 | ($filters->movementType !== null ? 2 : 0);

        $query = DB::table('snapshot_metrics AS metrics')
            ->whereIn('metrics.snapshot_id', $snapshotIds)
            ->where('metrics.grouping_mask', $groupingMask)
            ->where('metrics.movement_type_key', $filters->movementType ?? self::ALL);

        if ($filters->costCenter !== null) {
            $query->where('metrics.cost_center_key', $filters->costCenter);
        }
        if ($filters->unit !== null) {
            $query->where('metrics.unit_code_key', $filters->unit);
        }

        return $query;
    }

    private function filterValues(DashboardFilters $filters): array
    {
        return [
            'start_date' => $filters->startDate->toDateString(),
            'end_date' => $filters->endDate->toDateString(),
            'cost_center' => $filters->costCenter,
            'movement_type' => $filters->movementType,
            'unit' => $filters->unit,
        ];
    }
}
