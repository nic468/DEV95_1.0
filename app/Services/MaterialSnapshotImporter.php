<?php

namespace App\Services;

use App\Data\MaterialImportPreview;
use App\Exceptions\MaterialImportException;
use App\Models\CostCenter;
use App\Models\ScrapRevision;
use App\Models\SnapshotMovement;
use App\Models\SnapshotScrap;
use App\Models\UnitOfMeasure;
use App\Models\UploadSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as SpreadsheetReaderException;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class MaterialSnapshotImporter
{
    private const REQUIRED_HEADERS = [
        'ccusto' => 'C Custo',
        'ordproducao' => 'Ord Producao',
        'tiporede' => 'Tipo RE/DE',
        'produto' => 'Produto',
        'descricao' => 'Descricao',
        'quantidade' => 'Quantidade',
        'um' => 'UM',
        'custounitario' => 'Custo Unitario',
        'custo' => 'Custo',
        'documento' => 'Documento',
        'dtemissao' => 'DT Emissao',
        'custostand' => 'Custo Stand.',
        'totalstd' => 'TotalSTD',
    ];

    public function __construct(
        private readonly SnapshotMetricCalculator $metricCalculator
    ) {}

    public function preview(string $path, ?string $originalFilename = null): MaterialImportPreview
    {
        $resolvedPath = realpath($path);

        if ($resolvedPath === false || ! is_file($resolvedPath) || ! is_readable($resolvedPath)) {
            throw new MaterialImportException('Arquivo inexistente ou sem permissao de leitura.');
        }

        $filename = $originalFilename === null
            ? basename($resolvedPath)
            : basename(str_replace('\\', '/', $originalFilename));

        if (
            $filename === ''
            || preg_match('/[\x00-\x1F\x7F]/', $filename) === 1
            || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'xlsx'
        ) {
            throw new MaterialImportException('Formato invalido. Envie um arquivo .xlsx.');
        }

        $fileSize = filesize($resolvedPath);
        $maxSize = (int) config('materials.import_max_kilobytes', 10240) * 1024;

        if ($fileSize === false || $fileSize < 1 || $fileSize > $maxSize) {
            throw new MaterialImportException(
                'Tamanho invalido: o arquivo deve ter entre 1 byte e '
                .number_format($maxSize / 1024, 0, ',', '.').' KB.'
            );
        }

        $hash = hash_file('sha256', $resolvedPath);

        if ($hash === false) {
            throw new MaterialImportException('Nao foi possivel calcular o SHA-256 do arquivo.');
        }

        if (mb_strlen($filename) > 260) {
            throw new MaterialImportException('O nome do arquivo excede 260 caracteres.');
        }

        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);

        try {
            $workbook = $reader->load($resolvedPath);
        } catch (SpreadsheetReaderException $exception) {
            throw new MaterialImportException(
                'O arquivo nao e um XLSX valido ou esta danificado.',
                previous: $exception
            );
        }

        try {
            [$worksheet, $headerRow, $columns] = $this->findHeader($workbook->getWorksheetIterator());
            $lastRow = $worksheet->getHighestDataRow();
            $movements = [];
            $sourceTotal = null;
            $totalRows = [];

            for ($row = $headerRow + 1; $row <= $lastRow; $row++) {
                $values = [];

                foreach ($columns as $key => $column) {
                    $values[$key] = $worksheet->getCell(
                        Coordinate::stringFromColumnIndex($column).$row
                    )->getValue();
                }

                if ($this->isEmptyRow($values)) {
                    continue;
                }

                $center = $this->optionalString($values['ccusto']);
                $unit = $this->optionalString($values['um']);

                if ($center === null && $unit === null) {
                    $sourceTotal = $this->decimal(
                        $worksheet->getCell(Coordinate::stringFromColumnIndex($columns['custo']).$row)
                            ->getCalculatedValue(),
                        2,
                        false,
                        $row,
                        'Custo total'
                    );
                    $totalRows[] = $row;

                    continue;
                }

                if ($center === null || $unit === null) {
                    throw new MaterialImportException("Linha {$row}: C Custo e UM sao obrigatorios.");
                }

                $movement = [
                    'source_row' => $row,
                    'cost_center_code' => $this->requiredString($values['ccusto'], $row, 'C Custo', 20),
                    'work_order' => $this->requiredString($values['ordproducao'], $row, 'Ord Producao', 40),
                    'movement_type' => $this->requiredString($values['tiporede'], $row, 'Tipo RE/DE', 12),
                    'product_code' => $this->requiredString($values['produto'], $row, 'Produto', 60),
                    'description' => $this->requiredString($values['descricao'], $row, 'Descricao', 500),
                    'quantity' => $this->decimal($values['quantidade'], 6, false, $row, 'Quantidade'),
                    'unit_code' => $this->requiredString($values['um'], $row, 'UM', 10),
                    'unit_cost' => $this->decimal($values['custounitario'], 6, false, $row, 'Custo Unitario'),
                    'cost' => $this->decimal($values['custo'], 2, false, $row, 'Custo'),
                    'document' => $this->requiredString($values['documento'], $row, 'Documento', 40),
                    'issue_date' => $this->date($values['dtemissao'], $row),
                    'standard_cost' => $this->standardCost($values['custostand'], $row),
                    'standard_total' => $this->decimal($values['totalstd'], 6, false, $row, 'TotalSTD'),
                ];
                $movements[] = $movement;
            }

            if (count($totalRows) !== 1 || $sourceTotal === null) {
                throw new MaterialImportException(
                    'A planilha deve conter exatamente uma linha de TOTAL GERAL identificavel.'
                );
            }

            if ($movements === []) {
                throw new MaterialImportException('Nenhum lancamento valido foi encontrado.');
            }

            if ($totalRows[0] < max(array_column($movements, 'source_row'))) {
                throw new MaterialImportException(
                    'A linha de TOTAL GERAL deve vir depois de todos os lancamentos.'
                );
            }

            $importedTotal = '0.00';

            foreach ($movements as $movement) {
                $importedTotal = $this->addMoney($importedTotal, $movement['cost']);
            }

            if ($importedTotal !== $sourceTotal) {
                throw new MaterialImportException(
                    "Reconciliacao divergente: origem {$sourceTotal}; linhas {$importedTotal}. "
                    .'Nenhum snapshot foi gravado.'
                );
            }

            $warnings = [];
            [$scraps, $warnings] = $this->scrapGroups($movements, $warnings);
            [$newCostCenters, $newUnits] = $this->newDimensions($movements);

            return new MaterialImportPreview(
                filename: $filename,
                sha256: $hash,
                sourceRowCount: count($movements),
                sourceTotal: $sourceTotal,
                importedTotal: $importedTotal,
                movements: $movements,
                scraps: $scraps,
                newCostCenters: $newCostCenters,
                newUnits: $newUnits,
                warnings: $warnings,
            );
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    public function store(
        MaterialImportPreview $preview,
        CarbonImmutable $referenceDate,
        ?string $sourceFilePath = null
    ): UploadSnapshot
    {
        return DB::transaction(function () use ($preview, $referenceDate, $sourceFilePath): UploadSnapshot {
            $date = $referenceDate->toDateString();

            if (UploadSnapshot::query()
                ->where('reference_date', $date)
                ->where('sha256', $preview->sha256)
                ->exists()) {
                throw new MaterialImportException(
                    "Arquivo duplicado: este SHA-256 ja foi importado em {$date}."
                );
            }

            $version = (int) UploadSnapshot::query()
                ->where('reference_date', $date)
                ->max('version') + 1;
            $importedAt = CarbonImmutable::now('UTC');
            $snapshot = UploadSnapshot::query()->create([
                'reference_date' => $date,
                'version' => $version,
                'filename' => $preview->filename,
                'source_file_path' => $sourceFilePath,
                'sha256' => $preview->sha256,
                'imported_at' => $importedAt,
                'row_count' => $preview->sourceRowCount,
                'source_total' => $preview->sourceTotal,
                'imported_total' => $preview->importedTotal,
                'reconciliation_difference' => '0.00',
                'reconciliation_status' => 'matched',
            ]);

            $centerNames = config('materials.cost_center_names', []);

            foreach ($this->uniqueValues($preview->movements, 'cost_center_code') as $code) {
                CostCenter::query()->firstOrCreate(
                    ['code' => $code],
                    ['name' => $centerNames[$code] ?? null]
                );
            }

            foreach ($this->uniqueValues($preview->movements, 'unit_code') as $code) {
                UnitOfMeasure::query()->firstOrCreate(['code' => $code]);
            }

            foreach (array_chunk($preview->movements, 500) as $chunk) {
                $rows = array_map(
                    fn (array $movement): array => ['snapshot_id' => $snapshot->id] + $movement,
                    $chunk
                );
                SnapshotMovement::query()->insert($rows);
            }

            if ($preview->scraps !== []) {
                $scrapRows = array_map(
                    fn (array $scrap): array => ['snapshot_id' => $snapshot->id] + $scrap,
                    $preview->scraps
                );
                SnapshotScrap::query()->insert($scrapRows);

                $revisionRows = array_map(
                    fn (array $scrap): array => [
                        'snapshot_id' => $snapshot->id,
                        'cost_center_code' => $scrap['cost_center_code'],
                        'work_order' => $scrap['work_order'],
                        'product_code' => $scrap['product_code'],
                        'revision' => 1,
                        'value_m2' => null,
                        'recorded_at' => $importedAt,
                    ],
                    $preview->scraps
                );
                ScrapRevision::query()->insert($revisionRows);
            }

            $this->metricCalculator->rebuild((int) $snapshot->id);

            return $snapshot->refresh();
        });
    }

    /**
     * @param  iterable<Worksheet>  $worksheets
     * @return array{Worksheet, int, array<string, int>}
     */
    private function findHeader(iterable $worksheets): array
    {
        $bestMissing = array_values(self::REQUIRED_HEADERS);
        $matches = [];

        foreach ($worksheets as $worksheet) {
            $highestRow = $worksheet->getHighestDataRow();
            $highestColumn = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());

            if ($highestRow > 100000 || $highestColumn > 256) {
                throw new MaterialImportException(
                    'A estrutura excede os limites aceitos de 100.000 linhas ou 256 colunas.'
                );
            }

            foreach ($worksheet->getRowIterator() as $rowIterator) {
                $row = $rowIterator->getRowIndex();
                $found = [];
                $cellIterator = $rowIterator->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(true);

                foreach ($cellIterator as $cell) {
                    $value = $cell->getValue();

                    if (! is_string($value)) {
                        continue;
                    }

                    $normalized = $this->normalizeHeader($value);

                    if (! isset(self::REQUIRED_HEADERS[$normalized])) {
                        continue;
                    }

                    if (isset($found[$normalized])) {
                        throw new MaterialImportException(
                            "Cabecalho duplicado na linha {$row}: ".self::REQUIRED_HEADERS[$normalized].'.'
                        );
                    }

                    $found[$normalized] = Coordinate::columnIndexFromString($cell->getColumn());
                }

                if (count($found) === count(self::REQUIRED_HEADERS)) {
                    $matches[] = [$worksheet, $row, $found];

                    continue;
                }

                if (count($found) > count(self::REQUIRED_HEADERS) - count($bestMissing)) {
                    $bestMissing = array_values(array_diff(
                        array_keys(self::REQUIRED_HEADERS),
                        array_keys($found)
                    ));
                    $bestMissing = array_map(
                        fn (string $key): string => self::REQUIRED_HEADERS[$key],
                        $bestMissing
                    );
                }
            }
        }

        if (count($matches) !== 1) {
            if ($matches !== []) {
                throw new MaterialImportException('Mais de um cabecalho valido foi encontrado.');
            }

            if (count($bestMissing) < count(self::REQUIRED_HEADERS)) {
                throw new MaterialImportException(
                    'Colunas obrigatorias ausentes: '.implode(', ', $bestMissing).'.'
                );
            }

            throw new MaterialImportException(
                'Nao foi encontrado um cabecalho com as colunas obrigatorias da planilha.'
            );
        }

        return $matches[0];
    }

    private function normalizeHeader(string $header): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($header));

        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $ascii));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function requiredString(mixed $value, int $row, string $label, int $maxLength): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new MaterialImportException("Linha {$row}: {$label} deve ser texto nao vazio.");
        }

        $text = trim($value);

        if (mb_strlen($text) > $maxLength) {
            throw new MaterialImportException(
                "Linha {$row}: {$label} excede {$maxLength} caracteres."
            );
        }

        return $text;
    }

    private function standardCost(mixed $value, int $row): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_string($value) && preg_match('/^[\s,.]+$/u', $value) === 1) {
            return null;
        }

        return $this->decimal($value, 6, true, $row, 'Custo Stand.');
    }

    private function decimal(
        mixed $value,
        int $scale,
        bool $nullable,
        int $row,
        string $label
    ): ?string {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($nullable) {
                return null;
            }

            throw new MaterialImportException("Linha {$row}: {$label} e obrigatorio.");
        }

        if (is_int($value) || is_float($value)) {
            if (! is_finite((float) $value)) {
                throw new MaterialImportException("Linha {$row}: {$label} nao e um decimal valido.");
            }

            $rounded = round((float) $value, $scale, PHP_ROUND_HALF_UP);
            $tolerance = max(1.0E-12, PHP_FLOAT_EPSILON * max(1.0, abs((float) $value)) * 4);

            if (abs((float) $value - $rounded) > $tolerance) {
                throw new MaterialImportException(
                    "Linha {$row}: {$label} possui mais de {$scale} casas decimais."
                );
            }

            $number = number_format($rounded, $scale, '.', '');
        } elseif (is_string($value)) {
            $number = trim(str_replace(["\u{00A0}", ' '], '', $value));

            if (str_contains($number, ',')) {
                if (str_contains($number, '.') && strrpos($number, ',') < strrpos($number, '.')) {
                    throw new MaterialImportException(
                        "Linha {$row}: formato decimal ambiguo em {$label}."
                    );
                }

                $number = str_replace('.', '', $number);
                $number = str_replace(',', '.', $number);
            }

            if (preg_match('/^[+-]?\d+(?:\.\d+)?$/', $number) !== 1) {
                throw new MaterialImportException("Linha {$row}: {$label} nao e um decimal valido.");
            }

            [$integer, $fraction] = array_pad(explode('.', ltrim($number, '+-'), 2), 2, '');

            if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
                throw new MaterialImportException(
                    "Linha {$row}: {$label} possui mais de {$scale} casas decimais."
                );
            }

            $negative = str_starts_with($number, '-');
            $integer = ltrim($integer, '0') ?: '0';
            $number = ($negative && ($integer !== '0' || trim($fraction, '0') !== '') ? '-' : '')
                .$integer.'.'.str_pad(substr($fraction, 0, $scale), $scale, '0');
        } else {
            throw new MaterialImportException("Linha {$row}: {$label} nao e um decimal valido.");
        }

        $integerPart = ltrim(explode('.', ltrim($number, '-'))[0], '0') ?: '0';

        if (strlen($integerPart) > 19 - $scale) {
            throw new MaterialImportException("Linha {$row}: {$label} excede a precisao permitida.");
        }

        return $number;
    }

    private function date(mixed $value, int $row): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_int($value) || is_float($value)) {
            try {
                return Date::excelToDateTimeObject($value)
                    ->format('Y-m-d');
            } catch (Throwable $exception) {
                throw new MaterialImportException("Linha {$row}: DT Emissao invalida.", previous: $exception);
            }
        }

        if (is_string($value)) {
            foreach (['!d/m/Y', '!Y-m-d', '!d-m-Y'] as $format) {
                $date = \DateTimeImmutable::createFromFormat($format, trim($value));
                $errors = \DateTimeImmutable::getLastErrors();

                if (
                    $date !== false
                    && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                    && $date->format(substr($format, 1)) === trim($value)
                ) {
                    return $date->format('Y-m-d');
                }
            }
        }

        throw new MaterialImportException("Linha {$row}: DT Emissao invalida.");
    }

    private function addMoney(string $left, string $right): string
    {
        $leftMinor = str_replace('.', '', $left);
        $rightMinor = str_replace('.', '', $right);

        return $this->formatMinorUnits($this->addSignedIntegers($leftMinor, $rightMinor));
    }

    private function addSignedIntegers(string $left, string $right): string
    {
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');
        $leftAbs = ltrim($left, '-');
        $rightAbs = ltrim($right, '-');

        if ($leftNegative === $rightNegative) {
            $sum = $this->addPositiveIntegers($leftAbs, $rightAbs);

            return $leftNegative && $sum !== '0' ? '-'.$sum : $sum;
        }

        $comparison = $this->comparePositiveIntegers($leftAbs, $rightAbs);

        if ($comparison === 0) {
            return '0';
        }

        $larger = $comparison > 0 ? $leftAbs : $rightAbs;
        $smaller = $comparison > 0 ? $rightAbs : $leftAbs;
        $negative = $comparison > 0 ? $leftNegative : $rightNegative;
        $difference = $this->subtractPositiveIntegers($larger, $smaller);

        return $negative ? '-'.$difference : $difference;
    }

    private function addPositiveIntegers(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;

        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = ($leftIndex >= 0 ? (int) $left[$leftIndex--] : 0)
                + ($rightIndex >= 0 ? (int) $right[$rightIndex--] : 0)
                + $carry;
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private function comparePositiveIntegers(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';

        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private function subtractPositiveIntegers(string $left, string $right): string
    {
        $borrow = 0;
        $result = '';
        $rightIndex = strlen($right) - 1;

        for ($leftIndex = strlen($left) - 1; $leftIndex >= 0; $leftIndex--) {
            $digit = (int) $left[$leftIndex] - $borrow
                - ($rightIndex >= 0 ? (int) $right[$rightIndex--] : 0);

            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }

            $result = $digit.$result;
        }

        return ltrim($result, '0') ?: '0';
    }

    private function formatMinorUnits(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $digits = str_pad(ltrim($value, '-'), 3, '0', STR_PAD_LEFT);
        $formatted = substr($digits, 0, -2).'.'.substr($digits, -2);

        return $negative && $formatted !== '0.00' ? '-'.$formatted : $formatted;
    }

    /**
     * @param  list<array<string, int|string|null>>  $movements
     * @return array{list<array{cost_center_code: string, work_order: string, product_code: string, description: string, movement_type: string}>, list<string>}
     */
    private function scrapGroups(array $movements, array &$warnings): array
    {
        $groups = [];

        foreach ($movements as $movement) {
            if (
                $movement['unit_code'] !== 'M2'
                || stripos($movement['description'], 'CHAPA') === false
                || preg_match('/GRADE|DEGRAU/i', $movement['description']) === 1
            ) {
                continue;
            }

            $key = json_encode([
                $movement['cost_center_code'],
                $movement['work_order'],
                $movement['product_code'],
            ], JSON_THROW_ON_ERROR);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'cost_center_code' => $movement['cost_center_code'],
                    'work_order' => $movement['work_order'],
                    'product_code' => $movement['product_code'],
                    'description' => $movement['description'],
                    'movement_type' => $movement['movement_type'],
                    'descriptions' => [$movement['description']],
                    'types' => [$movement['movement_type']],
                ];

                continue;
            }

            $groups[$key]['descriptions'][] = $movement['description'];
            $groups[$key]['types'][] = $movement['movement_type'];
        }

        $scraps = [];

        foreach ($groups as $group) {
            if (count(array_unique($group['types'])) > 1) {
                throw new MaterialImportException(
                    'Grupo de sobra encontrado com mais de um Tipo RE/DE; corrija a origem antes de importar.'
                );
            }

            if (count(array_unique($group['descriptions'])) > 1) {
                $warnings[] = sprintf(
                    'Descricoes divergentes no grupo de sobra %s / %s / %s; foi mantida a primeira.',
                    $group['cost_center_code'],
                    $group['work_order'],
                    $group['product_code']
                );
            }

            $scraps[] = [
                'cost_center_code' => $group['cost_center_code'],
                'work_order' => $group['work_order'],
                'product_code' => $group['product_code'],
                'description' => $group['description'],
                'movement_type' => $group['movement_type'],
            ];
        }

        return [$scraps, $warnings];
    }

    /**
     * @param  list<array<string, int|string|null>>  $movements
     * @return array{list<string>, list<string>}
     */
    private function newDimensions(array $movements): array
    {
        $centers = $this->uniqueValues($movements, 'cost_center_code');
        $units = $this->uniqueValues($movements, 'unit_code');
        $knownCenters = array_keys(config('materials.cost_center_names', []));
        $knownUnits = config('materials.known_units', []);

        return [
            array_values(array_diff($centers, $knownCenters)),
            array_values(array_diff($units, $knownUnits)),
        ];
    }

    /**
     * @param  list<array<string, int|string|null>>  $movements
     * @return list<string>
     */
    private function uniqueValues(array $movements, string $key): array
    {
        return array_values(array_unique(array_column($movements, $key)));
    }
}
