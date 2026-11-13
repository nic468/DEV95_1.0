<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Services\EffectiveSnapshotMovementQuery;
use Illuminate\Http\JsonResponse;

class DashboardOptionsController extends Controller
{
    public function __invoke(EffectiveSnapshotMovementQuery $movements): JsonResponse
    {
        $centers = CostCenter::query()
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn (CostCenter $center): array => [
                'code' => $center->code,
                'name' => $center->name ?? $center->code,
            ]);
        $movementTypes = $movements->query()
            ->select('movement_type')
            ->distinct()
            ->orderBy('movement_type')
            ->pluck('movement_type')
            ->values();
        $units = $movements->query()
            ->select('unit_code')
            ->distinct()
            ->orderBy('unit_code')
            ->pluck('unit_code')
            ->values();

        return response()->json([
            'cost_centers' => $centers,
            'movement_types' => $movementTypes,
            'units' => $units,
        ]);
    }
}
