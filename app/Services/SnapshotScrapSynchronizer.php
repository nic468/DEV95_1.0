<?php

namespace App\Services;

use App\Models\ScrapRevision;
use App\Models\SnapshotScrap;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SnapshotScrapSynchronizer
{
    public function __construct(
        private readonly EffectiveSnapshotMovementQuery $movements
    ) {}

    public function synchronize(int $snapshotId): void
    {
        $groups = [];

        foreach ($this->movements->forSnapshot($snapshotId)
            ->orderBy('source_row')
            ->get([
                'cost_center_code',
                'work_order',
                'movement_type',
                'product_code',
                'description',
                'unit_code',
            ]) as $movement) {
            if (
                $movement->unit_code !== 'M2'
                || stripos($movement->description, 'CHAPA') === false
                || preg_match('/GRADE|DEGRAU/i', $movement->description) === 1
            ) {
                continue;
            }

            $identity = [
                'snapshot_id' => $snapshotId,
                'cost_center_code' => $movement->cost_center_code,
                'work_order' => $movement->work_order,
                'product_code' => $movement->product_code,
            ];
            $key = json_encode(array_values($identity), JSON_THROW_ON_ERROR);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    ...$identity,
                    'description' => $movement->description,
                    'movement_type' => $movement->movement_type,
                    'movement_types' => [$movement->movement_type],
                ];

                continue;
            }

            $groups[$key]['movement_types'][] = $movement->movement_type;
        }

        SnapshotScrap::query()
            ->where('snapshot_id', $snapshotId)
            ->update(['is_active' => false]);

        foreach ($groups as $group) {
            if (count(array_unique($group['movement_types'])) > 1) {
                throw ValidationException::withMessages([
                    'movement_type' => 'A correção criaria um grupo de sobra com mais de um Tipo RE/DE.',
                ]);
            }

            unset($group['movement_types']);
            $scrap = SnapshotScrap::query()->firstOrNew([
                'snapshot_id' => $group['snapshot_id'],
                'cost_center_code' => $group['cost_center_code'],
                'work_order' => $group['work_order'],
                'product_code' => $group['product_code'],
            ]);
            $isNew = ! $scrap->exists;
            $scrap->fill($group);
            $scrap->is_active = true;
            $scrap->save();

            if ($isNew) {
                ScrapRevision::query()->create([
                    'snapshot_id' => $group['snapshot_id'],
                    'cost_center_code' => $group['cost_center_code'],
                    'work_order' => $group['work_order'],
                    'product_code' => $group['product_code'],
                    'revision' => 1,
                    'value_m2' => null,
                    'recorded_at' => CarbonImmutable::now('UTC'),
                    'recorded_by' => null,
                ]);
            }
        }
    }
}
