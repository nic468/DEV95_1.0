<?php

namespace App\Http\Controllers;

use App\Models\UploadSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MaterialSnapshotDeleteController extends Controller
{
    public function __invoke(Request $request, UploadSnapshot $snapshot): RedirectResponse
    {
        $request->validate([
            'confirm_delete' => ['required', 'accepted'],
        ], [
            'confirm_delete.required' => 'Confirme a exclusão definitiva do arquivamento.',
            'confirm_delete.accepted' => 'Confirme a exclusão definitiva do arquivamento.',
        ]);
        $sourcePath = $snapshot->source_file_path;

        if ($sourcePath !== null && ! str_starts_with($sourcePath, 'snapshots/source/')) {
            return back()->with('error', 'O caminho do arquivo original não é válido; o snapshot não foi excluído.');
        }

        $disk = Storage::disk('local');
        $stagedPath = null;
        $originalWasMissing = false;

        if ($sourcePath !== null && $disk->exists($sourcePath)) {
            $stagedPath = 'snapshots/deleting/'.Str::uuid().'.xlsx';

            if (! $disk->move($sourcePath, $stagedPath)) {
                return back()->with('error', 'Não foi possível preparar o arquivo original para exclusão.');
            }
        } elseif ($sourcePath !== null) {
            $originalWasMissing = true;
            Log::warning('Arquivo original ausente durante exclusão do snapshot.', [
                'snapshot_id' => $snapshot->id,
                'source_file_path' => $sourcePath,
            ]);
        }

        $deleted = false;

        try {
            DB::transaction(function () use ($snapshot): void {
                DB::table('upload_snapshots')
                    ->where('id', $snapshot->id)
                    ->lockForUpdate()
                    ->first();

                $movementIds = DB::table('snapshot_movements')
                    ->where('snapshot_id', $snapshot->id)
                    ->select('id');

                DB::table('snapshot_movement_revisions')
                    ->whereIn('snapshot_movement_id', $movementIds)
                    ->delete();
                DB::table('scrap_revisions')
                    ->where('snapshot_id', $snapshot->id)
                    ->delete();
                DB::table('snapshot_scraps')
                    ->where('snapshot_id', $snapshot->id)
                    ->delete();
                DB::table('snapshot_metrics')
                    ->where('snapshot_id', $snapshot->id)
                    ->delete();
                DB::table('snapshot_movements')
                    ->where('snapshot_id', $snapshot->id)
                    ->delete();

                if (DB::table('upload_snapshots')->where('id', $snapshot->id)->delete() !== 1) {
                    throw new RuntimeException('O snapshot não foi encontrado para exclusão.');
                }
            });
            $deleted = true;
        } catch (QueryException $exception) {
            Log::error('Falha ao excluir snapshot e seus dados relacionados.', [
                'snapshot_id' => $snapshot->id,
                'exception' => $exception->getMessage(),
            ]);

            return back()->with('error', 'Não foi possível excluir o arquivamento. Nenhum dado foi removido.');
        } finally {
            if (! $deleted && $stagedPath !== null && $disk->exists($stagedPath)) {
                if (! $disk->move($stagedPath, $sourcePath)) {
                    Log::critical('Falha ao restaurar arquivo original após cancelamento da exclusão.', [
                        'snapshot_id' => $snapshot->id,
                        'staged_path' => $stagedPath,
                        'source_file_path' => $sourcePath,
                    ]);
                }
            }
        }

        if ($stagedPath !== null && ! $disk->delete($stagedPath)) {
            Log::error('Dados do snapshot excluídos, mas o arquivo original temporário não pôde ser removido.', [
                'snapshot_id' => $snapshot->id,
                'staged_path' => $stagedPath,
            ]);

            return redirect()
                ->route('materials.index')
                ->with('error', 'O arquivamento foi removido, mas o arquivo original ficou pendente de limpeza.');
        }

        $status = 'Arquivamento, materiais, revisões, sobras e métricas excluídos.';

        if ($stagedPath !== null) {
            $status .= ' O arquivo original também foi excluído.';
        } elseif ($originalWasMissing) {
            $status .= ' O arquivo original já estava ausente.';
        } else {
            $status .= ' Não havia arquivo original associado.';
        }

        return redirect()->route('materials.index')->with('status', $status);
    }
}
