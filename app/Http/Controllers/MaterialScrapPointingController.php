<?php

namespace App\Http\Controllers;

use App\Services\EffectiveSnapshotMovementQuery;
use App\Services\ScrapPointingService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class MaterialScrapPointingController extends Controller
{
    public function index(Request $request, EffectiveSnapshotMovementQuery $movements): View
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'filename' => ['nullable', 'string', 'max:260'],
            'search' => ['nullable', 'string', 'max:100'],
        ], [
            'date.date_format' => 'Informe a data no formato AAAA-MM-DD.',
            'filename.string' => 'O arquivo de origem deve ser texto.',
            'filename.max' => 'O arquivo de origem não pode exceder 260 caracteres.',
            'search.string' => 'A busca deve ser texto.',
            'search.max' => 'A busca não pode exceder 100 caracteres.',
        ]);

        $latestRevision = DB::table('scrap_revisions')
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
        $netAreas = $movements->query()
            ->where('unit_code', 'M2')
            ->whereRaw('LOWER(description) LIKE ?', ['%chapa%'])
            ->whereRaw('LOWER(description) NOT LIKE ?', ['%grade%'])
            ->whereRaw('LOWER(description) NOT LIKE ?', ['%degrau%'])
            ->select([
                'snapshot_id',
                'cost_center_code',
                'work_order',
                'product_code',
            ])
            ->selectRaw('SUM(quantity) AS net_area')
            ->groupBy([
                'snapshot_id',
                'cost_center_code',
                'work_order',
                'product_code',
            ]);
        $availableAreas = DB::query()
            ->fromSub($netAreas, 'net_areas')
            ->select([
                'snapshot_id',
                'cost_center_code',
                'work_order',
                'product_code',
            ])
            ->selectRaw('CASE WHEN net_area <= 0 THEN 0 ELSE net_area END AS available_area');

        $query = DB::table('snapshot_scraps as scraps')
            ->join('upload_snapshots as snapshots', 'snapshots.id', '=', 'scraps.snapshot_id')
            ->leftJoinSub($latestRevision, 'latest', function (JoinClause $join): void {
                $join->on('latest.snapshot_id', '=', 'scraps.snapshot_id')
                    ->on('latest.cost_center_code', '=', 'scraps.cost_center_code')
                    ->on('latest.work_order', '=', 'scraps.work_order')
                    ->on('latest.product_code', '=', 'scraps.product_code');
            })
            ->leftJoin('scrap_revisions as revisions', function (JoinClause $join): void {
                $join->on('revisions.snapshot_id', '=', 'latest.snapshot_id')
                    ->on('revisions.cost_center_code', '=', 'latest.cost_center_code')
                    ->on('revisions.work_order', '=', 'latest.work_order')
                    ->on('revisions.product_code', '=', 'latest.product_code')
                    ->on('revisions.revision', '=', 'latest.latest_revision');
            })
            ->leftJoinSub($availableAreas, 'areas', function (JoinClause $join): void {
                $join->on('areas.snapshot_id', '=', 'scraps.snapshot_id')
                    ->on('areas.cost_center_code', '=', 'scraps.cost_center_code')
                    ->on('areas.work_order', '=', 'scraps.work_order')
                    ->on('areas.product_code', '=', 'scraps.product_code');
            })
            ->where('scraps.is_active', true)
            ->select([
                'scraps.snapshot_id',
                'scraps.cost_center_code',
                'scraps.work_order',
                'scraps.product_code',
                'scraps.description',
                'scraps.movement_type',
                'snapshots.reference_date',
                'snapshots.version',
                'snapshots.filename',
                'revisions.value_m2',
                'revisions.recorded_at',
                'revisions.recorded_by',
            ])
            ->selectRaw('COALESCE(areas.available_area, 0) AS available_area');

        if (isset($validated['date'])) {
            $query->where('snapshots.reference_date', $validated['date']);
        }

        if (($validated['filename'] ?? '') !== '') {
            $query->where('snapshots.filename', 'like', '%'.$validated['filename'].'%');
        }

        if (($validated['search'] ?? '') !== '') {
            $term = '%'.$validated['search'].'%';
            $query->where(function ($search) use ($term): void {
                $search->where('scraps.cost_center_code', 'like', $term)
                    ->orWhere('scraps.work_order', 'like', $term)
                    ->orWhere('scraps.product_code', 'like', $term)
                    ->orWhere('scraps.description', 'like', $term);
            });
        }

        $scraps = $query
            ->orderByDesc('snapshots.reference_date')
            ->orderByDesc('snapshots.version')
            ->orderBy('scraps.cost_center_code')
            ->orderBy('scraps.work_order')
            ->orderBy('scraps.product_code')
            ->paginate(25)
            ->withQueryString();

        return view('materials.scraps', [
            'scraps' => $scraps,
            'selectedDate' => $validated['date'] ?? '',
            'selectedFilename' => $validated['filename'] ?? '',
            'selectedSearch' => $validated['search'] ?? '',
        ]);
    }

    public function store(Request $request, ScrapPointingService $pointings): RedirectResponse
    {
        $validated = $request->validate([
            'snapshot_id' => ['required', 'integer', 'min:1', 'exists:upload_snapshots,id'],
            'cost_center_code' => ['required', 'string', 'max:20', 'exists:cost_centers,code'],
            'work_order' => ['required', 'string', 'max:40'],
            'product_code' => ['required', 'string', 'max:60'],
            'value_m2' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,6})?$/'],
            'recorded_by' => ['required', 'string', 'max:256'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'filename' => ['nullable', 'string', 'max:260'],
            'search' => ['nullable', 'string', 'max:100'],
        ], [
            'required' => 'O campo :attribute é obrigatório.',
            'string' => 'O campo :attribute deve ser texto.',
            'max.string' => 'O campo :attribute não pode exceder :max caracteres.',
            'integer' => 'O campo :attribute deve ser um número inteiro.',
            'min' => 'O campo :attribute deve ser maior ou igual a :min.',
            'exists' => 'O valor informado para :attribute não existe.',
            'value_m2.regex' => 'Informe uma sobra não negativa com até 6 casas decimais.',
            'recorded_by.required' => 'Informe o nome de quem realizou o apontamento.',
            'date.date_format' => 'Informe a data no formato AAAA-MM-DD.',
            'filename.max' => 'O arquivo de origem não pode exceder 260 caracteres.',
            'search.max' => 'A busca não pode exceder 100 caracteres.',
        ], [
            'snapshot_id' => 'snapshot',
            'cost_center_code' => 'C Custo',
            'work_order' => 'Ord Producao',
            'product_code' => 'Produto',
            'value_m2' => 'sobra manual (m²)',
            'recorded_by' => 'nome de quem aponta',
            'date' => 'data de referência',
            'filename' => 'arquivo de origem',
            'search' => 'busca',
        ]);
        $identity = Arr::only($validated, [
            'snapshot_id',
            'cost_center_code',
            'work_order',
            'product_code',
        ]);
        $revision = $pointings->record(
            $identity,
            $validated['value_m2'],
            $validated['recorded_by']
        );

        return redirect()
            ->route('materials.scraps.index', Arr::only($validated, ['date', 'filename', 'search']))
            ->with('status', "Apontamento salvo como revisão {$revision}.");
    }
}
