<?php

namespace App\Services;

use App\Models\SnapshotMovement;
use App\Models\SnapshotMovementRevision;
use App\Models\UploadSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SnapshotMovementCorrectionService
{
    public function __construct(
        private readonly SnapshotScrapSynchronizer $scraps,
        private readonly SnapshotMetricCalculator $metrics
    ) {}

    /**
     * @param  array<string, string|null>  $values
     */
    public function correct(
        SnapshotMovement $movement,
        array $values,
        string $recordedBy
    ): SnapshotMovementRevision {
        return DB::transaction(function () use ($movement, $values, $recordedBy): SnapshotMovementRevision {
            UploadSnapshot::query()
                ->whereKey($movement->snapshot_id)
                ->lockForUpdate()
                ->firstOrFail();

            $source = SnapshotMovement::query()
                ->whereKey($movement->getKey())
                ->where('snapshot_id', $movement->snapshot_id)
                ->lockForUpdate()
                ->firstOrFail();

            $revision = (int) $source->revisions()->max('revision') + 1;
            $correction = $source->revisions()->create([
                ...$values,
                'revision' => $revision,
                'recorded_at' => CarbonImmutable::now('UTC'),
                'recorded_by' => $recordedBy,
            ]);

            $this->scraps->synchronize((int) $source->snapshot_id);
            $this->metrics->rebuild((int) $source->snapshot_id);

            return $correction;
        });
    }
}
