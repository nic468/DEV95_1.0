<?php

namespace App\Console\Commands;

use App\Exceptions\MaterialImportException;
use App\Services\MaterialSnapshotImporter;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;

class ImportMaterialSnapshot extends Command
{
    protected $signature = 'materials:import
        {file : Caminho do arquivo .xlsx no servidor}
        {--date= : Data de referencia no formato AAAA-MM-DD}
        {--dry-run : Validar e reconciliar sem gravar no banco}';

    protected $description = 'Valida e arquiva um snapshot de movimentacoes de materiais';

    public function handle(MaterialSnapshotImporter $importer): int
    {
        $dateValue = $this->option('date');
        $dateValue = $dateValue === null
            ? CarbonImmutable::now('America/Sao_Paulo')->toDateString()
            : (string) $dateValue;
        try {
            $referenceDate = CarbonImmutable::createFromFormat('!Y-m-d', $dateValue, 'America/Sao_Paulo');
        } catch (InvalidFormatException) {
            $referenceDate = false;
        }
        $dateErrors = CarbonImmutable::getLastErrors();

        if (
            $referenceDate === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $referenceDate->format('Y-m-d') !== $dateValue
        ) {
            $this->components->error('Data invalida; use o formato AAAA-MM-DD.');

            return self::FAILURE;
        }

        try {
            $preview = $importer->preview($this->argument('file'));

            $this->components->info('Validacao e reconciliacao concluidas.');
            $this->line("Arquivo: {$preview->filename}");
            $this->line("Linhas: {$preview->sourceRowCount}");
            $this->line("Custo total: {$preview->importedTotal}");
            $this->line('Grupos de sobra manual: '.count($preview->scraps).' (valores em branco)');

            foreach ($preview->newCostCenters as $code) {
                $this->components->warn("Centro de custo nao padronizado: {$code}");
            }

            foreach ($preview->newUnits as $unit) {
                $this->components->warn("Unidade de medida nao padronizada: {$unit}");
            }

            foreach ($preview->warnings as $warning) {
                $this->components->warn($warning);
            }

            if ($this->option('dry-run')) {
                $this->components->info('Dry run: nenhum dado foi gravado.');

                return self::SUCCESS;
            }

            $snapshot = $importer->store($preview, $referenceDate);
            $this->components->info(
                "Snapshot {$snapshot->reference_date->format('Y-m-d')} v{$snapshot->version} arquivado."
            );

            return self::SUCCESS;
        } catch (MaterialImportException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
