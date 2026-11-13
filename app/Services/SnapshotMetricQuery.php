<?php

namespace App\Services;

use App\Data\DashboardFilters;
use App\Models\SnapshotMetric;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SnapshotMetricQuery
{
    private const ALL = '__ALL__';

    /**
     * @param  list<int>  $snapshotIds
     * @return Collection<int, SnapshotMetric>
     */
    public function forSnapshots(array $snapshotIds, DashboardFilters $filters): Collection
    {
        if ($snapshotIds === []) {
            return collect();
        }

        $mask = ($filters->costCenter !== null ? 1 : 0)
            | ($filters->movementType !== null ? 2 : 0)
            | ($filters->unit !== null ? 4 : 0);

        return SnapshotMetric::query()
            ->whereIn('snapshot_id', $snapshotIds)
            ->where('grouping_mask', $mask)
            ->where('cost_center_key', $filters->costCenter ?? self::ALL)
            ->where('movement_type_key', $filters->movementType ?? self::ALL)
            ->where('unit_code_key', $filters->unit ?? self::ALL)
            ->get()
            ->keyBy('snapshot_id');
    }

    /**
     * @param  list<int>  $snapshotIds
     */
    public function assertCalculated(array $snapshotIds): void
    {
        if ($snapshotIds === []) {
            return;
        }

        $calculatedIds = DB::table('snapshot_metrics')
            ->whereIn('snapshot_id', $snapshotIds)
            ->distinct()
            ->pluck('snapshot_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (array_diff($snapshotIds, $calculatedIds) !== []) {
            throw new RuntimeException(
                'Existem snapshots sem metricas calculadas; reconstrua as metricas antes da consulta.'
            );
        }
    }

    /**
     * @return array{total_cost: string, record_count: int, distinct_work_orders: int, scrap_total_m2: string}
     */
    public function values(?SnapshotMetric $metric): array
    {
        if ($metric === null) {
            return [
                'total_cost' => '0.00',
                'record_count' => 0,
                'distinct_work_orders' => 0,
                'scrap_total_m2' => '0.000000',
            ];
        }

        return [
            'total_cost' => (string) $metric->total_cost,
            'record_count' => (int) $metric->record_count,
            'distinct_work_orders' => (int) $metric->distinct_work_orders,
            'scrap_total_m2' => (string) $metric->scrap_total_m2,
        ];
    }
}
