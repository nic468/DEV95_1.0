<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UploadSnapshot;
use App\Services\DashboardFilterResolver;
use App\Services\SnapshotMetricQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class DashboardSnapshotsController extends Controller
{
    private const TIMEZONE = 'America/Sao_Paulo';

    public function __invoke(
        Request $request,
        DashboardFilterResolver $filters,
        SnapshotMetricQuery $metrics
    ): JsonResponse {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $resolvedFilters = $filters->resolve($request->query());
        $snapshots = UploadSnapshot::query()
            ->whereBetween('reference_date', [
                $resolvedFilters->startDate->toDateString(),
                $resolvedFilters->endDate->toDateString(),
            ])
            ->orderByDesc('reference_date')
            ->orderByDesc('version')
            ->paginate(50, ['*'], 'page', (int) ($validated['page'] ?? 1));
        $snapshotIds = $snapshots->getCollection()
            ->map(fn (UploadSnapshot $snapshot): int => (int) $snapshot->id)
            ->all();

        $metrics->assertCalculated($snapshotIds);
        $metricRows = $metrics->forSnapshots($snapshotIds, $resolvedFilters);
        $rows = $snapshots->getCollection()->map(function (UploadSnapshot $snapshot) use ($metricRows, $metrics): array {
            return [
                'id' => (int) $snapshot->id,
                'reference_date' => $snapshot->reference_date->format('Y-m-d'),
                'version' => (int) $snapshot->version,
                'imported_at' => CarbonImmutable::parse($snapshot->imported_at, 'UTC')
                    ->setTimezone(self::TIMEZONE)
                    ->toIso8601String(),
                'filename' => $snapshot->filename,
                'source_row_count' => (int) $snapshot->row_count,
                ...$metrics->values($metricRows->get($snapshot->id)),
            ];
        });

        return response()->json([
            'filters' => [
                'start_date' => $resolvedFilters->startDate->toDateString(),
                'end_date' => $resolvedFilters->endDate->toDateString(),
                'cost_center' => $resolvedFilters->costCenter,
                'movement_type' => $resolvedFilters->movementType,
                'unit' => $resolvedFilters->unit,
            ],
            'data' => $rows,
            'pagination' => $this->pagination($snapshots),
        ]);
    }

    /**
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
