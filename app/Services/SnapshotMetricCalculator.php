<?php

namespace App\Services;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SnapshotMetricCalculator
{
    private const ALL = '__ALL__';

    private const DIMENSIONS = [
        1 => ['cost_center_code', 'cost_center_key'],
        2 => ['movement_type', 'movement_type_key'],
        4 => ['unit_code', 'unit_code_key'],
    ];

    public function __construct(
        private readonly EffectiveSnapshotMovementQuery $movements
    ) {}

    public function rebuild(int $snapshotId): int
    {
        return DB::transaction(function () use ($snapshotId): int {
            $metricRows = [];

            for ($mask = 0; $mask <= 7; $mask++) {
                $query = $this->movements->forSnapshot($snapshotId)
                    ->selectRaw('COUNT(*) AS record_count')
                    ->selectRaw('COUNT(DISTINCT work_order) AS distinct_work_orders')
                    ->selectRaw('SUM(cost) AS total_cost')
                    ->selectRaw(($mask & 4) === 4 ? 'SUM(quantity) AS quantity_total' : 'NULL AS quantity_total');

                foreach (self::DIMENSIONS as $bit => [$sourceColumn, $metricColumn]) {
                    if (($mask & $bit) === $bit) {
                        $query->addSelect(DB::raw("{$sourceColumn} AS {$metricColumn}"))
                            ->groupBy($sourceColumn);
                    } else {
                        $query->selectRaw("'".self::ALL."' AS {$metricColumn}");
                    }
                }

                foreach ($query->get() as $aggregate) {
                    $dimensions = [
                        $aggregate->cost_center_key,
                        $aggregate->movement_type_key,
                        $aggregate->unit_code_key,
                    ];
                    $key = $this->dimensionKey($dimensions);

                    $metricRows[$mask][$key] = [
                        'snapshot_id' => $snapshotId,
                        'grouping_mask' => $mask,
                        'cost_center_key' => $aggregate->cost_center_key,
                        'movement_type_key' => $aggregate->movement_type_key,
                        'unit_code_key' => $aggregate->unit_code_key,
                        'record_count' => (int) $aggregate->record_count,
                        'distinct_work_orders' => (int) $aggregate->distinct_work_orders,
                        'total_cost' => $aggregate->total_cost ?? '0.00',
                        'quantity_total' => ($mask & 4) === 4
                            ? ($aggregate->quantity_total ?? '0.000000')
                            : null,
                        'scrap_total_m2' => '0.000000',
                        'reported_scrap_count' => 0,
                    ];
                }
            }

            $this->addScrapAggregates($snapshotId, $metricRows);

            DB::table('snapshot_metrics')->where('snapshot_id', $snapshotId)->delete();

            foreach ($metricRows as $groupRows) {
                foreach (array_chunk(array_values($groupRows), 500) as $chunk) {
                    DB::table('snapshot_metrics')->insert($chunk);
                }
            }

            return array_sum(array_map('count', $metricRows));
        });
    }

    /**
     * @param  array<int, array<string, array<string, int|string|null>>>  $metricRows
     */
    private function addScrapAggregates(int $snapshotId, array &$metricRows): void
    {
        if (! DB::table('snapshot_scraps')->where('snapshot_id', $snapshotId)->exists()) {
            return;
        }

        $latestRevision = DB::table('scrap_revisions')
            ->where('snapshot_id', $snapshotId)
            ->select([
                'snapshot_id',
                'cost_center_code',
                'work_order',
                'product_code',
            ])
            ->selectRaw('MAX(revision) AS latest_revision')
            ->groupBy([
                'snapshot_id',
                'cost_center_code',
                'work_order',
                'product_code',
            ]);

        for ($mask = 0; $mask <= 7; $mask++) {
            $query = DB::table('snapshot_scraps AS scraps')
                ->where('scraps.snapshot_id', $snapshotId)
                ->where('scraps.is_active', true)
                ->leftJoinSub($latestRevision, 'latest', function (JoinClause $join): void {
                    $join->on('latest.snapshot_id', '=', 'scraps.snapshot_id')
                        ->on('latest.cost_center_code', '=', 'scraps.cost_center_code')
                        ->on('latest.work_order', '=', 'scraps.work_order')
                        ->on('latest.product_code', '=', 'scraps.product_code');
                })
                ->leftJoin('scrap_revisions AS revisions', function (JoinClause $join): void {
                    $join->on('revisions.snapshot_id', '=', 'latest.snapshot_id')
                        ->on('revisions.cost_center_code', '=', 'latest.cost_center_code')
                        ->on('revisions.work_order', '=', 'latest.work_order')
                        ->on('revisions.product_code', '=', 'latest.product_code')
                        ->on('revisions.revision', '=', 'latest.latest_revision');
                })
                ->selectRaw('COALESCE(SUM(revisions.value_m2), 0) AS scrap_total_m2')
                ->selectRaw(
                    'SUM(CASE WHEN revisions.value_m2 IS NOT NULL THEN 1 ELSE 0 END) AS reported_scrap_count'
                );

            foreach (self::DIMENSIONS as $bit => [$sourceColumn, $metricColumn]) {
                if ($bit === 4) {
                    if (($mask & $bit) === $bit) {
                        $query->selectRaw("'M2' AS {$metricColumn}");
                    } else {
                        $query->selectRaw("'".self::ALL."' AS {$metricColumn}");
                    }

                    continue;
                }

                if (($mask & $bit) === $bit) {
                    $query->addSelect("scraps.{$sourceColumn} AS {$metricColumn}")
                        ->groupBy("scraps.{$sourceColumn}");
                } else {
                    $query->selectRaw("'".self::ALL."' AS {$metricColumn}");
                }
            }

            foreach ($query->get() as $aggregate) {
                $dimensions = [
                    $aggregate->cost_center_key,
                    $aggregate->movement_type_key,
                    $aggregate->unit_code_key,
                ];
                $key = $this->dimensionKey($dimensions);

                if (! isset($metricRows[$mask][$key])) {
                    throw new RuntimeException(
                        'O agregado de sobras nao corresponde a um grupo de movimentacoes do snapshot.'
                    );
                }

                $metricRows[$mask][$key]['scrap_total_m2'] = $aggregate->scrap_total_m2 ?? '0.000000';
                $metricRows[$mask][$key]['reported_scrap_count'] = (int) $aggregate->reported_scrap_count;
            }
        }
    }

    /**
     * @param  list<string>  $dimensions
     */
    private function dimensionKey(array $dimensions): string
    {
        return json_encode($dimensions, JSON_THROW_ON_ERROR);
    }
}
