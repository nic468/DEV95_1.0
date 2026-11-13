<?php

namespace App\Http\Controllers;

use App\Data\MaterialImportPreview;
use App\Exceptions\MaterialImportException;
use App\Models\UploadSnapshot;
use App\Services\MaterialSnapshotImporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MaterialSnapshotUploadController extends Controller
{
    private const SOURCE_DIRECTORY = 'snapshots/source';

    public function __invoke(Request $request, MaterialSnapshotImporter $importer): RedirectResponse
    {
        $maxKilobytes = (int) config('materials.import_max_kilobytes', 10240);
        $validated = $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx', 'max:'.$maxKilobytes],
            'reference_date' => ['required', 'date_format:Y-m-d'],
        ], [
            'file.required' => 'Selecione uma planilha .xlsx.',
            'file.file' => 'O arquivo enviado não pôde ser lido.',
            'file.extensions' => 'Envie somente arquivos com extensão .xlsx.',
            'file.max' => 'O arquivo excede o limite de '.$maxKilobytes.' KB.',
            'reference_date.required' => 'Informe a data de referência.',
            'reference_date.date_format' => 'A data de referência deve estar no formato AAAA-MM-DD.',
        ]);

        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $validated['file'];
        $temporaryPath = $uploadedFile->getRealPath();

        if (! is_string($temporaryPath)) {
            throw ValidationException::withMessages([
                'file' => 'Não foi possível ler o arquivo enviado.',
            ]);
        }

        try {
            $preview = $importer->preview($temporaryPath, $uploadedFile->getClientOriginalName());
        } catch (MaterialImportException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $referenceDate = CarbonImmutable::parse($validated['reference_date'], 'America/Sao_Paulo');
        $sourceFilePath = null;
        $snapshotStored = false;

        try {
            $sourceFilePath = $uploadedFile->storeAs(
                self::SOURCE_DIRECTORY,
                Str::uuid().'.xlsx',
                'local'
            );

            if (! is_string($sourceFilePath)) {
                throw new RuntimeException('Não foi possível arquivar o arquivo original.');
            }

            $snapshot = $importer->store($preview, $referenceDate, $sourceFilePath);
            $snapshotStored = true;
        } catch (MaterialImportException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        } finally {
            if (! $snapshotStored && is_string($sourceFilePath)) {
                if (! Storage::disk('local')->delete($sourceFilePath)) {
                    Log::error('Falha ao remover arquivo original após rejeitar a importação.', [
                        'source_file_path' => $sourceFilePath,
                    ]);
                }
            }
        }

        return redirect()
            ->route('materials.index', ['date' => $snapshot->reference_date->format('Y-m-d')])
            ->with('status', sprintf(
                'Snapshot %s v%d arquivado: %s linhas, custo total R$ %s.',
                $snapshot->reference_date->format('d/m/Y'),
                $snapshot->version,
                number_format($snapshot->row_count, 0, ',', '.'),
                number_format((float) $snapshot->imported_total, 2, ',', '.')
            ))
            ->with('import_warnings', $this->warnings($preview));
    }

    /**
     * @return list<string>
     */
    private function warnings(MaterialImportPreview $preview): array
    {
        $warnings = $preview->warnings;

        if ($preview->newCostCenters !== []) {
            $warnings[] = 'Novos centros de custo cadastrados: '.implode(', ', $preview->newCostCenters).'.';
        }

        if ($preview->newUnits !== []) {
            $warnings[] = 'Novas unidades de medida cadastradas: '.implode(', ', $preview->newUnits).'.';
        }

        return $warnings;
    }
}
