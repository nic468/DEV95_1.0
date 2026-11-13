<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class EffectiveSnapshotMovementQuery
{
    private const CORRECTABLE_FIELDS = [
        'cost_center_code',
        'work_order',
        'movement_type',
        'product_code',
        'description',
        'quantity',
        'unit_code',
        'unit_cost',
        'cost',
        'document',
        'issue_date',
        'standard_cost',
        'standard_total',
    ];

    public function query(): Builder
    {
        $latestRevision = DB::table('snapshot_movement_revisions')
            ->select('snapshot_movement_id')
            ->selectRaw('MAX(revision) AS latest_revision')
            ->groupBy('snapshot_movement_id');

        $effectiveRows = DB::table('snapshot_movements AS source')
            ->leftJoinSub($latestRevision, 'latest_revision', function (JoinClause $join): void {
                $join->on('latest_revision.snapshot_movement_id', '=', 'source.id');
            })
            ->leftJoin('snapshot_movement_revisions AS revisions', function (JoinClause $join): void {
                $join->on('revisions.snapshot_movement_id', '=', 'latest_revision.snapshot_movement_id')
                    ->on('revisions.revision', '=', 'latest_revision.latest_revision');
            })
            ->select([
                'source.id AS id',
                'source.snapshot_id',
                'source.source_row',
            ]);

        foreach (self::CORRECTABLE_FIELDS as $field) {
            $effectiveRows->selectRaw(
                "CASE WHEN revisions.id IS NULL THEN source.{$field} ELSE revisions.{$field} END AS {$field}"
            );
        }

        return DB::query()->fromSub($effectiveRows, 'effective_movements');
    }

    public function forSnapshot(int $snapshotId): Builder
    {
        return $this->query()->where('snapshot_id', $snapshotId);
    }
}
