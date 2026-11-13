<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UploadSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SnapshotDayController extends Controller
{
    private const TIMEZONE = 'America/Sao_Paulo';

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $snapshots = UploadSnapshot::query()
            ->leftJoin('snapshot_metrics as metrics', function (JoinClause $join): void {
                $join->on('metrics.snapshot_id', '=', 'upload_snapshots.id')
                    ->where('metrics.grouping_mask', '=', 0)
                    ->where('metrics.cost_center_key', '=', '__ALL__')
                    ->where('metrics.movement_type_key', '=', '__ALL__')
                    ->where('metrics.unit_code_key', '=', '__ALL__');
            })
            ->where('upload_snapshots.reference_date', $validated['date'])
            ->orderBy('upload_snapshots.version')
            ->get(['upload_snapshots.*', 'metrics.total_cost AS effective_total_cost']);

        if ($snapshots->contains(fn (UploadSnapshot $snapshot): bool => $snapshot->effective_total_cost === null)) {
            throw new RuntimeException('Existem snapshots sem métricas calculadas para a consulta diária.');
        }

        $snapshots = $snapshots->map(fn (UploadSnapshot $snapshot): array => [
            'id' => (int) $snapshot->id,
            'version' => (int) $snapshot->version,
            'imported_at' => CarbonImmutable::parse($snapshot->imported_at, 'UTC')
                ->setTimezone(self::TIMEZONE)
                ->toIso8601String(),
            'filename' => $snapshot->filename,
            'row_count' => (int) $snapshot->row_count,
            'total_cost' => (string) $snapshot->effective_total_cost,
        ]);

        return response()->json([
            'date' => $validated['date'],
            'snapshots' => $snapshots,
        ]);
    }
}
