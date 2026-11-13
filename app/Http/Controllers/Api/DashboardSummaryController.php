<?php

namespace App\Http\Controllers\Api;

use App\Data\DashboardFilters;
use App\Http\Controllers\Controller;
use App\Models\UploadSnapshot;
use App\Services\DashboardFilterResolver;
use App\Services\SnapshotMetricQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardSummaryController extends Controller
{
    private const TIMEZONE = 'America/Sao_Paulo';

    public function __invoke(
        Request $request,
        DashboardFilterResolver $filters,
        SnapshotMetricQuery $metrics
    ): JsonResponse {
        $resolvedFilters = $filters->resolve($request->query());
        $snapshot = UploadSnapshot::query()
            ->whereBetween('reference_date', [
                $resolvedFilters->startDate->toDateString(),
                $resolvedFilters->endDate->toDateString(),
            ])
            ->orderByDesc('reference_date')
            ->orderByDesc('version')
            ->first();

        if ($snapshot === null) {
            return response()->json([
                'snapshot' => null,
                'kpis' => null,
                'filters' => $this->filterValues($resolvedFilters),
            ]);
        }

        $snapshotId = (int) $snapshot->id;
        $metrics->assertCalculated([$snapshotId]);
        $values = $metrics->values(
            $metrics->forSnapshots([$snapshotId], $resolvedFilters)->get($snapshotId)
        );

        return response()->json([
            'snapshot' => [
                'id' => $snapshotId,
                'reference_date' => $snapshot->reference_date->format('Y-m-d'),
                'version' => (int) $snapshot->version,
                'imported_at' => CarbonImmutable::parse($snapshot->imported_at, 'UTC')
                    ->setTimezone(self::TIMEZONE)
                    ->toIso8601String(),
                'filename' => $snapshot->filename,
            ],
            'kpis' => $values,
            'filters' => $this->filterValues($resolvedFilters),
        ]);
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
