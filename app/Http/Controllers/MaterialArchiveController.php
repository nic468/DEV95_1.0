<?php

namespace App\Http\Controllers;

use App\Models\UploadSnapshot;
use App\Services\EffectiveSnapshotMovementQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class MaterialArchiveController extends Controller
{
    public function __invoke(
        Request $request,
        EffectiveSnapshotMovementQuery $movements
    ): View|RedirectResponse {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'filename' => ['nullable', 'string', 'max:260'],
            'open_snapshot_id' => ['nullable', 'integer', 'min:1', 'exists:upload_snapshots,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'materials_page' => ['nullable', 'integer', 'min:1'],
        ], [
            'date.date_format' => 'Informe a data no formato AAAA-MM-DD.',
            'filename.string' => 'O arquivo de origem deve ser texto.',
            'filename.max' => 'O arquivo de origem não pode exceder 260 caracteres.',
            'open_snapshot_id.integer' => 'O identificador do snapshot deve ser um número inteiro.',
            'open_snapshot_id.min' => 'O identificador do snapshot deve ser positivo.',
            'open_snapshot_id.exists' => 'O snapshot selecionado não foi encontrado.',
            'page.integer' => 'A página do arquivo deve ser um número inteiro.',
            'page.min' => 'A página do arquivo deve ser positiva.',
            'materials_page.integer' => 'A página dos materiais deve ser um número inteiro.',
            'materials_page.min' => 'A página dos materiais deve ser positiva.',
        ]);
        $query = UploadSnapshot::query();

        if (isset($validated['date'])) {
            $query->where('reference_date', $validated['date']);
        }

        if (isset($validated['filename']) && $validated['filename'] !== '') {
            $query->where('filename', 'like', '%'.$validated['filename'].'%');
        }

        $snapshots = $query
            ->orderByDesc('reference_date')
            ->orderByDesc('version')
            ->paginate(25)
            ->withQueryString();
        $openSnapshotId = isset($validated['open_snapshot_id'])
            ? (int) $validated['open_snapshot_id']
            : null;
        $openSnapshot = $openSnapshotId === null
            ? null
            : $snapshots->getCollection()->first(
                fn (UploadSnapshot $snapshot): bool => (int) $snapshot->id === $openSnapshotId
            );
        $materials = null;
        $units = collect();
        $costCenters = collect();

        if ($openSnapshotId !== null && $openSnapshot === null) {
            return redirect()->route(
                'materials.index',
                Arr::except($request->query(), ['open_snapshot_id', 'materials_page'])
            );
        }

        if ($openSnapshot !== null) {
            $latestRevision = DB::table('snapshot_movement_revisions')
                ->select('snapshot_movement_id')
                ->selectRaw('MAX(revision) AS latest_revision')
                ->groupBy('snapshot_movement_id');
            $materials = $movements->forSnapshot($openSnapshotId)
                ->leftJoinSub($latestRevision, 'correction_latest', function (JoinClause $join): void {
                    $join->on('correction_latest.snapshot_movement_id', '=', 'effective_movements.id');
                })
                ->leftJoin('snapshot_movement_revisions as correction', function (JoinClause $join): void {
                    $join->on('correction.snapshot_movement_id', '=', 'correction_latest.snapshot_movement_id')
                        ->on('correction.revision', '=', 'correction_latest.latest_revision');
                })
                ->select([
                    'effective_movements.*',
                    'correction.revision as correction_revision',
                    'correction.recorded_at as correction_recorded_at',
                    'correction.recorded_by as correction_recorded_by',
                ])
                ->orderBy('effective_movements.source_row')
                ->paginate(25, ['*'], 'materials_page', (int) ($validated['materials_page'] ?? 1))
                ->withQueryString();
            $units = DB::table('units_of_measure')->orderBy('code')->pluck('code');
            $costCenters = DB::table('cost_centers')->orderBy('code')->get(['code', 'name']);
        } else {
            $openSnapshotId = null;
        }

        return view('materials.index', [
            'snapshots' => $snapshots,
            'selectedDate' => $validated['date'] ?? '',
            'selectedFilename' => $validated['filename'] ?? '',
            'openSnapshotId' => $openSnapshotId,
            'materials' => $materials,
            'editingMovementId' => old('movement_id'),
            'units' => $units,
            'costCenters' => $costCenters,
        ]);
    }
}
