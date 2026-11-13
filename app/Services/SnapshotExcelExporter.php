<?php

namespace App\Services;

use App\Models\UploadSnapshot;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SnapshotExcelExporter
{
    public function __construct(
        private readonly EffectiveSnapshotMovementQuery $effectiveMovements
    ) {}

    /**
     * @param  array{cost_center?: ?string, movement_type?: ?string, unit?: ?string}  $filters
     */
    public function build(UploadSnapshot $snapshot, array $filters = []): Spreadsheet
    {
        $costCenter = $filters['cost_center'] ?? null;
        $movementType = $filters['movement_type'] ?? null;
        $unit = $filters['unit'] ?? null;

        $movementQuery = $this->effectiveMovements->forSnapshot((int) $snapshot->id)
            ->orderBy('source_row');
        $this->applyMovementFilters($movementQuery, $costCenter, $movementType, $unit);

        $movementCount = (int) (clone $movementQuery)->count();
        $movementTotal = (string) ((clone $movementQuery)->sum('cost') ?? '0.00');
        $movements = $movementQuery
            ->leftJoin('cost_centers as centers', 'centers.code', '=', 'effective_movements.cost_center_code')
            ->select('effective_movements.*')
            ->addSelect('centers.name as cost_center_name')
            ->get();
        $this->assertMovementReconciliation(
            $snapshot,
            $movements,
            $movementCount,
            $movementTotal,
            $costCenter,
            $movementType,
            $unit
        );

        $scraps = $this->scraps($snapshot, $costCenter, $movementType, $unit);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('Controle de Materiais')
            ->setTitle('Snapshot de movimentações de materiais')
            ->setSubject('Exportação de snapshot')
            ->setDescription(
                "Snapshot {$snapshot->reference_date->format('Y-m-d')} versão {$snapshot->version}"
            );

        $dashboard = $spreadsheet->getActiveSheet();
        $dashboard->setTitle('Dashboard');
        $data = $spreadsheet->createSheet()->setTitle('Dados');
        $scrapSheet = $spreadsheet->createSheet()->setTitle('Aproveitamento Chapa');
        $summary = $spreadsheet->createSheet()->setTitle('Resumo');
        $readme = $spreadsheet->createSheet()->setTitle('Leia-me');
        $lists = $spreadsheet->createSheet()->setTitle('Listas');

        $dataLastRow = max(5, 4 + $movements->count());
        $scrapLastRow = max(5, 4 + $scraps->count());
        $centers = $this->centerOptions($movements, $costCenter);
        $movementTypes = $this->uniqueMovementTypes($movements, $movementType);
        $units = $this->uniqueUnits($movements, $unit);
        $workOrders = $this->uniqueWorkOrders($movements);

        $this->writeLists($lists, $centers, $movementTypes, $units);
        $this->writeData($data, $movements, $dataLastRow);
        $this->writeScraps($scrapSheet, $scraps, $scrapLastRow);
        $this->writeSummary($summary, $dashboard, $centers, $movementTypes, $workOrders, $dataLastRow);
        $this->writeDashboard(
            $dashboard,
            $snapshot,
            $centers,
            $movementTypes,
            $dataLastRow,
            $scrapLastRow,
            $workOrders,
            $costCenter,
            $movementType
        );
        $this->writeReadme($readme, $snapshot, $costCenter, $movementType, $unit);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  array{cost_center?: ?string, movement_type?: ?string, unit?: ?string}  $filters
     */
    public function download(UploadSnapshot $snapshot, array $filters = []): BinaryFileResponse
    {
        $spreadsheet = $this->build($snapshot, $filters);
        $path = tempnam(sys_get_temp_dir(), 'material-export-');

        if ($path === false) {
            $spreadsheet->disconnectWorksheets();
            throw new RuntimeException('Nao foi possivel criar o arquivo temporario da exportacao.');
        }

        try {
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            $writer->setPreCalculateFormulas(false);
            $writer->setForceFullCalc(true);
            $writer->save($path);
        } catch (Throwable $exception) {
            if (is_file($path)) {
                unlink($path);
            }

            throw $exception;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $filename = sprintf(
            'controle_materiais_%s_v%d.xlsx',
            $snapshot->reference_date->format('Y-m-d'),
            $snapshot->version
        );

        return response()->download(
            $path,
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    private function applyMovementFilters(
        Builder $query,
        ?string $costCenter,
        ?string $movementType,
        ?string $unit
    ): void {
        if ($costCenter !== null) {
            $query->where('cost_center_code', $costCenter);
        }

        if ($movementType !== null) {
            $query->where('movement_type', $movementType);
        }

        if ($unit !== null) {
            $query->where('unit_code', $unit);
        }
    }

    private function assertMovementReconciliation(
        UploadSnapshot $snapshot,
        Collection $movements,
        int $expectedCount,
        string $expectedTotal,
        ?string $costCenter,
        ?string $movementType,
        ?string $unit
    ): void {
        $exportedTotalCents = 0;

        foreach ($movements as $movement) {
            $exportedTotalCents += $this->moneyInCents((string) $movement->cost);
        }

        if (
            $movements->count() !== $expectedCount
            || $exportedTotalCents !== $this->moneyInCents($expectedTotal)
        ) {
            throw new RuntimeException('O total exportado diverge dos dados filtrados no banco.');
        }

        if (
            $costCenter === null
            && $movementType === null
            && $unit === null
            && $expectedCount !== (int) $snapshot->row_count
        ) {
            throw new RuntimeException('O total exportado diverge do snapshot arquivado.');
        }
    }

    private function moneyInCents(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function scraps(
        UploadSnapshot $snapshot,
        ?string $costCenter,
        ?string $movementType,
        ?string $unit
    ): Collection {
        if ($unit !== null && $unit !== 'M2') {
            return collect();
        }

        $latestRevision = DB::table('scrap_revisions')
            ->where('snapshot_id', $snapshot->id)
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

        return DB::table('snapshot_scraps AS scraps')
            ->leftJoinSub($latestRevision, 'latest', function (JoinClause $join): void {
                $join->on('latest.snapshot_id', '=', 'scraps.snapshot_id')
                    ->on('latest.cost_center_code', '=', 'scraps.cost_center_code')
                    ->on('latest.work_order', '=', 'scraps.work_order')
                    ->on('latest.product_code', '=', 'scraps.product_code');
            })
            ->leftJoin('scrap_revisions AS revisions', function (JoinClause $join): void {
                $join->on('revisions.snapshot_id', '=', 'latest.snapshot_id')
                    ->on('revisions.cost_center_code', '=', 'latest.cost_center_code')
                    ->on('revisions.work_order', '=', 'latest.work_order')
                    ->on('revisions.product_code', '=', 'latest.product_code')
                    ->on('revisions.revision', '=', 'latest.latest_revision');
            })
            ->where('scraps.snapshot_id', $snapshot->id)
            ->where('scraps.is_active', true)
            ->when($costCenter !== null, fn ($query) => $query->where('scraps.cost_center_code', $costCenter))
            ->when($movementType !== null, fn ($query) => $query->where('scraps.movement_type', $movementType))
            ->orderBy('scraps.cost_center_code')
            ->orderBy('scraps.work_order')
            ->orderBy('scraps.product_code')
            ->get([
                'scraps.cost_center_code',
                'scraps.work_order',
                'scraps.product_code',
                'scraps.description',
                'scraps.movement_type',
                'revisions.value_m2',
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function centerOptions(Collection $movements, ?string $selected): array
    {
        $centers = [];

        foreach ($movements as $movement) {
            $centers[$movement->cost_center_code] = $movement->cost_center_name ?? $movement->cost_center_code;
        }

        if ($selected !== null && ! array_key_exists($selected, $centers)) {
            $centers[$selected] = DB::table('cost_centers')->where('code', $selected)->value('name') ?? $selected;
        }

        ksort($centers, SORT_STRING);

        return $centers;
    }

    /**
     * @return list<string>
     */
    private function uniqueMovementTypes(Collection $movements, ?string $selected): array
    {
        $types = $movements->pluck('movement_type')->unique()->values()->all();

        if ($selected !== null && ! in_array($selected, $types, true)) {
            $types[] = $selected;
        }

        sort($types, SORT_STRING);

        return $types;
    }

    /**
     * @return list<string>
     */
    private function uniqueUnits(Collection $movements, ?string $selected): array
    {
        $units = $movements->pluck('unit_code')->unique()->values()->all();

        if ($selected !== null && ! in_array($selected, $units, true)) {
            $units[] = $selected;
        }

        sort($units, SORT_STRING);

        return $units;
    }

    /**
     * @return list<array{cost_center_code: string, work_order: string}>
     */
    private function uniqueWorkOrders(Collection $movements): array
    {
        $workOrders = [];

        foreach ($movements as $movement) {
            $key = json_encode(
                [$movement->cost_center_code, $movement->work_order],
                JSON_THROW_ON_ERROR
            );
            $workOrders[$key] = [
                'cost_center_code' => $movement->cost_center_code,
                'work_order' => $movement->work_order,
            ];
        }

        $values = array_values($workOrders);
        usort($values, fn (array $left, array $right): int => [
            $left['cost_center_code'],
            $left['work_order'],
        ] <=> [
            $right['cost_center_code'],
            $right['work_order'],
        ]);

        return $values;
    }

    /**
     * @param  array<string, string>  $centers
     * @param  list<string>  $movementTypes
     * @param  list<string>  $units
     */
    private function writeLists(Worksheet $sheet, array $centers, array $movementTypes, array $units): void
    {
        $sheet->setCellValue('A1', 'LISTAS E MAPEAMENTOS');
        $sheet->fromArray(['C Custo', 'Centro de Custo'], null, 'A4');
        $sheet->fromArray(['Tipo RE/DE'], null, 'D4');
        $sheet->fromArray(['UM'], null, 'F4');

        $row = 5;
        foreach ($centers as $code => $name) {
            $this->setText($sheet, "A{$row}", $code);
            $this->setText($sheet, "B{$row}", $name);
            $row++;
        }

        $this->writeListValues($sheet, 'D', $movementTypes);
        $this->writeListValues($sheet, 'F', $units);
        $this->styleTitle($sheet, 'A1:F1');
        $this->styleHeader($sheet, 'A4:B4');
        $this->styleHeader($sheet, 'D4:D4');
        $this->styleHeader($sheet, 'F4:F4');
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('D')->setWidth(18);
        $sheet->getColumnDimension('F')->setWidth(14);
    }

    /**
     * @param  list<string>  $values
     */
    private function writeListValues(Worksheet $sheet, string $column, array $values): void
    {
        foreach ($values as $index => $value) {
            $this->setText($sheet, $column.(5 + $index), $value);
        }
    }

    private function writeData(Worksheet $sheet, Collection $movements, int $lastRow): void
    {
        $sheet->mergeCells('A1:H1');
        $sheet->mergeCells('A2:H2');
        $sheet->setCellValue('A1', 'BASE INTEGRADA — MOVIMENTAÇÕES DE OP');
        $sheet->setCellValue('A2', 'Dados do snapshot selecionado; identificadores preservados como texto.');
        $sheet->fromArray([
            'C Custo',
            'Ord Producao',
            'Tipo RE/DE',
            'Produto',
            'Descricao',
            'Quantidade',
            'UM',
            'Custo',
        ], null, 'A4');

        foreach ($movements as $index => $movement) {
            $row = 5 + $index;
            $this->setText($sheet, "A{$row}", (string) $movement->cost_center_code);
            $this->setText($sheet, "B{$row}", (string) $movement->work_order);
            $this->setText($sheet, "C{$row}", (string) $movement->movement_type);
            $this->setText($sheet, "D{$row}", (string) $movement->product_code);
            $this->setText($sheet, "E{$row}", (string) $movement->description);
            $this->setNumber($sheet, "F{$row}", (string) $movement->quantity);
            $this->setText($sheet, "G{$row}", (string) $movement->unit_code);
            $this->setNumber($sheet, "H{$row}", (string) $movement->cost);
        }

        $table = new Table("A4:H{$lastRow}", 'DadosMovimentacoes');
        $table->setStyle((new TableStyle)->setTheme(TableStyle::TABLE_STYLE_MEDIUM2)->setShowRowStripes(true));
        $sheet->addTable($table);
        $this->styleTitle($sheet, 'A1:H1');
        $this->styleSubtitle($sheet, 'A2:H2');
        $this->styleHeader($sheet, 'A4:H4');
        $sheet->getStyle("A5:H{$lastRow}")->getFont()->getColor()->setRGB('008000');
        $sheet->getStyle("F5:F{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.000000');
        $sheet->getStyle("H5:H{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (['A' => 14, 'B' => 20, 'C' => 14, 'D' => 18, 'E' => 48, 'F' => 16, 'G' => 10, 'H' => 16] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('A5');
    }

    private function writeScraps(Worksheet $sheet, Collection $scraps, int $lastRow): void
    {
        $sheet->mergeCells('A1:G1');
        $sheet->mergeCells('A2:G2');
        $sheet->setCellValue('A1', 'APONTAMENTO MANUAL DE SOBRA — CHAPAS');
        $sheet->setCellValue(
            'A2',
            'Preencha somente a coluna “Sobra manual (m²)”. O valor exportado é a revisão manual mais recente.'
        );
        $sheet->fromArray([
            'C Custo',
            'Centro de Custo',
            'Ord Producao',
            'Produto',
            'Descricao',
            'Tipo RE/DE',
            'Sobra manual (m²)',
        ], null, 'A4');

        $costCenterNames = DB::table('cost_centers')->pluck('name', 'code');
        foreach ($scraps as $index => $scrap) {
            $row = 5 + $index;
            $this->setText($sheet, "A{$row}", (string) $scrap->cost_center_code);
            $this->setText(
                $sheet,
                "B{$row}",
                (string) ($costCenterNames[$scrap->cost_center_code] ?? $scrap->cost_center_code)
            );
            $this->setText($sheet, "C{$row}", (string) $scrap->work_order);
            $this->setText($sheet, "D{$row}", (string) $scrap->product_code);
            $this->setText($sheet, "E{$row}", (string) $scrap->description);
            $this->setText($sheet, "F{$row}", (string) $scrap->movement_type);

            if ($scrap->value_m2 !== null) {
                $this->setNumber($sheet, "G{$row}", (string) $scrap->value_m2);
            }
        }

        $table = new Table("A4:G{$lastRow}", 'ApontamentoSobra');
        $table->setStyle((new TableStyle)->setTheme(TableStyle::TABLE_STYLE_MEDIUM2)->setShowRowStripes(true));
        $sheet->addTable($table);
        $this->styleTitle($sheet, 'A1:G1');
        $this->styleSubtitle($sheet, 'A2:G2');
        $this->styleHeader($sheet, 'A4:G4');
        $sheet->getStyle("A5:F{$lastRow}")->getFont()->getColor()->setRGB('008000');
        $sheet->getStyle("G5:G{$lastRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setRGB('DDEBF7');
        $sheet->getStyle("G5:G{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.000');
        foreach (['A' => 14, 'B' => 24, 'C' => 20, 'D' => 18, 'E' => 48, 'F' => 14, 'G' => 22] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('A5');
    }

    /**
     * @param  array<string, string>  $centers
     * @param  list<string>  $movementTypes
     * @param  list<array{cost_center_code: string, work_order: string}>  $workOrders
     */
    private function writeSummary(
        Worksheet $sheet,
        Worksheet $dashboard,
        array $centers,
        array $movementTypes,
        array $workOrders,
        int $dataLastRow
    ): void {
        $sheet->mergeCells('A1:M1');
        $sheet->setCellValue('A1', 'RESUMO E BASES AUXILIARES');
        $sheet->fromArray([
            'C Custo',
            'Centro de Custo',
            'Custo total (R$)',
            'Registros',
        ], null, 'A3');
        $sheet->fromArray(['Tipo RE/DE', 'Registros', 'Custo total (R$)'], null, 'F3');

        $workOrderHeaders = ['C Custo', 'Ord Producao'];
        foreach ($movementTypes as $type) {
            $workOrderHeaders[] = "Tem {$type}";
        }
        $sheet->fromArray($workOrderHeaders, null, 'J3');

        $centerValues = $centers !== [] ? $centers : ['—' => 'Sem dados'];
        $row = 4;
        foreach ($centerValues as $code => $name) {
            $this->setText($sheet, "A{$row}", $code);
            $this->setText($sheet, "B{$row}", $name);
            $this->setFormula($sheet, "C{$row}", $this->sumFormula($dataLastRow, "A{$row}"));
            $this->setFormula($sheet, "D{$row}", $this->countFormula($dataLastRow, "A{$row}"));
            $row++;
        }

        $typeValues = $movementTypes !== [] ? $movementTypes : ['—'];
        $row = 4;
        foreach ($typeValues as $type) {
            $this->setText($sheet, "F{$row}", $type);
            $this->setFormula($sheet, "G{$row}", $this->countFormula($dataLastRow, null, "F{$row}"));
            $this->setFormula($sheet, "H{$row}", $this->sumFormula($dataLastRow, null, "F{$row}"));
            $row++;
        }

        foreach ($workOrders as $index => $workOrder) {
            $row = 4 + $index;
            $this->setText($sheet, "J{$row}", $workOrder['cost_center_code']);
            $this->setText($sheet, "K{$row}", $workOrder['work_order']);

            foreach ($movementTypes as $typeIndex => $type) {
                $flagColumn = Coordinate::stringFromColumnIndex(12 + $typeIndex);
                $formula = sprintf(
                    '=--(COUNTIFS(Dados!$A$5:$A$%d,$J%d,Dados!$B$5:$B$%d,$K%d,Dados!$C$5:$C$%d,"%s")>0)',
                    $dataLastRow,
                    $row,
                    $dataLastRow,
                    $row,
                    $dataLastRow,
                    $this->escapeFormulaText($type)
                );
                $this->setFormula($sheet, "{$flagColumn}{$row}", $formula);
            }
        }

        $centerLastRow = 3 + count($centerValues);
        $typeLastRow = 3 + count($typeValues);
        $this->styleTitle($sheet, 'A1:M1');
        $this->styleHeader($sheet, 'A3:D3');
        $this->styleHeader($sheet, 'F3:H3');
        $lastWorkOrderColumn = Coordinate::stringFromColumnIndex(
            max(11, 11 + count($movementTypes))
        );
        $this->styleHeader($sheet, "J3:{$lastWorkOrderColumn}3");
        $sheet->getStyle("C4:C{$centerLastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("H4:H{$typeLastRow}")->getNumberFormat()->setFormatCode('#,##0.00');

        $this->writeDashboardTables($dashboard, $centerValues, $typeValues, $dataLastRow);
    }

    /**
     * @param  array<string, string>  $centers
     * @param  list<string>  $movementTypes
     * @param  list<array{cost_center_code: string, work_order: string}>  $workOrders
     */
    private function writeDashboard(
        Worksheet $sheet,
        UploadSnapshot $snapshot,
        array $centers,
        array $movementTypes,
        int $dataLastRow,
        int $scrapLastRow,
        array $workOrders,
        ?string $costCenter,
        ?string $movementType
    ): void {
        $sheet->mergeCells('A1:H1');
        $sheet->mergeCells('A2:H2');
        $sheet->setCellValue('A1', 'CONTROLE DE MATERIAIS — DEVOLUÇÕES E SOBRAS');
        $sheet->setCellValue(
            'A2',
            sprintf(
                'Snapshot %s | versão %d | %s',
                $snapshot->reference_date->format('Y-m-d'),
                $snapshot->version,
                $snapshot->filename
            )
        );
        $sheet->setCellValue('A4', 'Centro de Custo');
        $this->setText($sheet, 'B4', $costCenter ?? 'Todos');
        $sheet->setCellValue('D4', 'Tipo RE/DE');
        $this->setText($sheet, 'E4', $movementType ?? 'Todos');
        $sheet->fromArray([
            'CUSTO TOTAL (R$)',
            null,
            'REGISTROS',
            null,
            'OPs DISTINTAS',
            null,
            'SOBRA APONTADA (m²)',
        ], null, 'A6');

        $this->setFormula(
            $sheet,
            'A7',
            $this->sumFormula($dataLastRow, null, null, '$B$4', '$E$4')
        );
        $this->setFormula(
            $sheet,
            'C7',
            $this->countFormula($dataLastRow, null, null, '$B$4', '$E$4')
        );

        $distinctFormula = $this->distinctWorkOrderFormula($workOrders, $movementTypes);
        $this->setFormula($sheet, 'E7', $distinctFormula);
        $scrapCenterRange = $this->range('Aproveitamento Chapa', 'A', 5, $scrapLastRow);
        $scrapTypeRange = $this->range('Aproveitamento Chapa', 'F', 5, $scrapLastRow);
        $scrapValueRange = $this->range('Aproveitamento Chapa', 'G', 5, $scrapLastRow);
        $this->setFormula(
            $sheet,
            'G7',
            '=SUMIFS('.$scrapValueRange.','.$scrapCenterRange.',IF($B$4="Todos","*",$B$4),'
                .$scrapTypeRange.',IF($E$4="Todos","*",$E$4))'
        );

        $sheet->fromArray([
            'C Custo',
            'Centro de Custo',
            'Custo (R$)',
            'Registros',
            null,
            'Tipo RE/DE',
            'Registros',
            'Custo (R$)',
        ], null, 'A11');
        $this->styleTitle($sheet, 'A1:H1');
        $this->styleSubtitle($sheet, 'A2:H2');
        $this->styleHeader($sheet, 'A6:H6');
        $this->styleHeader($sheet, 'A11:D11');
        $this->styleHeader($sheet, 'F11:H11');
        $sheet->getStyle('B4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
        $sheet->getStyle('E4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
        $sheet->getStyle('A7:H7')->getFont()->getColor()->setRGB('000000');
        $sheet->getStyle('A7')->getNumberFormat()->setFormatCode('"R$" #,##0.00');
        $sheet->getStyle('G7')->getNumberFormat()->setFormatCode('#,##0.000');
        $sheet->getStyle('C12:C'.(11 + max(1, count($centers))))->getNumberFormat()->setFormatCode('"R$" #,##0.00');
        $sheet->getStyle('H12:H'.(11 + max(1, count($movementTypes))))->getNumberFormat()->setFormatCode('"R$" #,##0.00');
        foreach (['A' => 14, 'B' => 25, 'C' => 18, 'D' => 14, 'E' => 4, 'F' => 16, 'G' => 14, 'H' => 18] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getColumnDimension('I')->setWidth(3);
        $sheet->getColumnDimension('J')->setWidth(16);
        $sheet->getColumnDimension('K')->setWidth(16);
        $sheet->getColumnDimension('L')->setWidth(16);
        $sheet->getRowDimension(7)->setRowHeight(28);

        $this->addCostChart(
            $sheet,
            'chartCostByCenter',
            'Custo por centro de custo',
            'B',
            'C',
            12,
            11 + max(1, count($centers)),
            'J4',
            'R21'
        );
        $this->addCostChart(
            $sheet,
            'chartCostByType',
            'Custo por tipo RE/DE',
            'F',
            'H',
            12,
            11 + max(1, count($movementTypes)),
            'J22',
            'R39'
        );
    }

    private function writeDashboardTables(
        Worksheet $dashboard,
        array $centers,
        array $movementTypes,
        int $dataLastRow
    ): void {
        $centerValues = $centers !== [] ? $centers : ['—' => 'Sem dados'];

        $row = 12;
        foreach ($centerValues as $code => $name) {
            $this->setText($dashboard, "A{$row}", $code);
            $dashboard->setCellValue("B{$row}", $name);
            $this->setFormula(
                $dashboard,
                "C{$row}",
                $this->sumFormula(
                    $dataLastRow,
                    "A{$row}",
                    null,
                    '$B$4',
                    '$E$4'
                )
            );
            $this->setFormula(
                $dashboard,
                "D{$row}",
                $this->countFormula(
                    $dataLastRow,
                    "A{$row}",
                    null,
                    '$B$4',
                    '$E$4'
                )
            );
            $row++;
        }

        $typeValues = $movementTypes !== [] ? $movementTypes : ['—'];
        $row = 12;
        foreach ($typeValues as $type) {
            $this->setText($dashboard, "F{$row}", $type);
            $this->setFormula(
                $dashboard,
                "G{$row}",
                $this->countFormula($dataLastRow, null, "F{$row}", '$B$4', '$E$4')
            );
            $this->setFormula(
                $dashboard,
                "H{$row}",
                $this->sumFormula($dataLastRow, null, "F{$row}", '$B$4', '$E$4')
            );
            $row++;
        }
    }

    /**
     * @param  array<string, string>  $centers
     * @param  list<string>  $movementTypes
     * @param  list<array{cost_center_code: string, work_order: string}>  $workOrders
     */
    private function distinctWorkOrderFormula(array $workOrders, array $movementTypes): string
    {
        $lastRow = max(4, 3 + count($workOrders));
        $centerRange = $this->range('Resumo', 'J', 4, $lastRow);
        $allTypesFormula = 'COUNTIF('.$centerRange.',IF($B$4="Todos","*",$B$4))';
        $selectedTypeFormula = '0';

        foreach (array_reverse($movementTypes, true) as $index => $type) {
            $flagColumn = Coordinate::stringFromColumnIndex(
                12 + $index
            );
            $flagRange = $this->range('Resumo', $flagColumn, 4, $lastRow);
            $selectedTypeFormula = sprintf(
                'IF($E$4="%s",COUNTIFS(%s,IF($B$4="Todos","*",$B$4),%s,1),%s)',
                $this->escapeFormulaText($type),
                $centerRange,
                $flagRange,
                $selectedTypeFormula
            );
        }

        return '=IF($E$4="Todos",'.$allTypesFormula.','.$selectedTypeFormula.')';
    }

    private function sumFormula(
        int $dataLastRow,
        ?string $centerCell = null,
        ?string $typeCell = null,
        ?string $centerFilterCell = null,
        ?string $typeFilterCell = null
    ): string {
        $centerCriteria = $centerFilterCell !== null
            ? "IF({$centerFilterCell}=\"Todos\",\"*\",{$centerFilterCell})"
            : ($centerCell ?? '"*"');
        $typeCriteria = $typeFilterCell !== null
            ? "IF({$typeFilterCell}=\"Todos\",\"*\",{$typeFilterCell})"
            : ($typeCell ?? '"*"');
        $centerRange = $this->range('Dados', 'A', 5, $dataLastRow);
        $typeRange = $this->range('Dados', 'C', 5, $dataLastRow);
        $costRange = $this->range('Dados', 'H', 5, $dataLastRow);

        if ($centerCell !== null && $centerFilterCell !== null) {
            $centerCriteria = "IF({$centerFilterCell}=\"Todos\",{$centerCell},IF({$centerFilterCell}={$centerCell},{$centerCell},\"__NO_MATCH__\"))";
        }

        if ($typeCell !== null && $typeFilterCell !== null) {
            $typeCriteria = "IF({$typeFilterCell}=\"Todos\",{$typeCell},IF({$typeFilterCell}={$typeCell},{$typeCell},\"__NO_MATCH__\"))";
        }

        return "=SUMIFS({$costRange},{$centerRange},{$centerCriteria},{$typeRange},{$typeCriteria})";
    }

    private function countFormula(
        int $dataLastRow,
        ?string $centerCell = null,
        ?string $typeCell = null,
        ?string $centerFilterCell = null,
        ?string $typeFilterCell = null
    ): string {
        $centerCriteria = $centerFilterCell !== null
            ? "IF({$centerFilterCell}=\"Todos\",\"*\",{$centerFilterCell})"
            : ($centerCell ?? '"*"');
        $typeCriteria = $typeFilterCell !== null
            ? "IF({$typeFilterCell}=\"Todos\",\"*\",{$typeFilterCell})"
            : ($typeCell ?? '"*"');
        $centerRange = $this->range('Dados', 'A', 5, $dataLastRow);
        $typeRange = $this->range('Dados', 'C', 5, $dataLastRow);

        if ($centerCell !== null && $centerFilterCell !== null) {
            $centerCriteria = "IF({$centerFilterCell}=\"Todos\",{$centerCell},IF({$centerFilterCell}={$centerCell},{$centerCell},\"__NO_MATCH__\"))";
        }

        if ($typeCell !== null && $typeFilterCell !== null) {
            $typeCriteria = "IF({$typeFilterCell}=\"Todos\",{$typeCell},IF({$typeFilterCell}={$typeCell},{$typeCell},\"__NO_MATCH__\"))";
        }

        return "=COUNTIFS({$centerRange},{$centerCriteria},{$typeRange},{$typeCriteria})";
    }

    private function range(string $sheet, string $column, int $firstRow, int $lastRow): string
    {
        $sheetName = str_contains($sheet, ' ') ? "'{$sheet}'" : $sheet;

        return $sheetName.'!$'.$column.'$'.$firstRow.':$'.$column.'$'.$lastRow;
    }

    private function addCostChart(
        Worksheet $sheet,
        string $name,
        string $title,
        string $categoryColumn,
        string $valueColumn,
        int $firstRow,
        int $lastRow,
        string $topLeft,
        string $bottomRight
    ): void {
        $pointCount = $lastRow - $firstRow + 1;
        $labels = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                "'Dashboard'!$".$valueColumn.'$11',
                null,
                1
            ),
        ];
        $categories = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                "'Dashboard'!$".$categoryColumn.'$'.$firstRow.':$'.$categoryColumn.'$'.$lastRow,
                null,
                $pointCount
            ),
        ];
        $values = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                "'Dashboard'!$".$valueColumn.'$'.$firstRow.':$'.$valueColumn.'$'.$lastRow,
                null,
                $pointCount
            ),
        ];
        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0],
            $labels,
            $categories,
            $values,
            DataSeries::DIRECTION_COL
        );
        $chart = new Chart(
            $name,
            new Title($title),
            new Legend(Legend::POSITION_RIGHT, null, false),
            new PlotArea(null, [$series])
        );
        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);
        $sheet->addChart($chart);
    }

    private function writeReadme(
        Worksheet $sheet,
        UploadSnapshot $snapshot,
        ?string $costCenter,
        ?string $movementType,
        ?string $unit
    ): void {
        $sheet->mergeCells('A1:B1');
        $sheet->setCellValue('A1', 'LEIA-ME — REGRAS DA BASE INTEGRADA');
        $sheet->fromArray(['Tópico', 'Regra / informação'], null, 'A3');
        $correctionCount = DB::table('snapshot_movement_revisions')
            ->join('snapshot_movements', 'snapshot_movements.id', '=', 'snapshot_movement_revisions.snapshot_movement_id')
            ->where('snapshot_movements.snapshot_id', $snapshot->id)
            ->distinct()
            ->count('snapshot_movement_revisions.snapshot_movement_id');
        $rows = [
            ['Snapshot', sprintf('%s, versão %d.', $snapshot->reference_date->format('Y-m-d'), $snapshot->version)],
            ['Arquivo de origem', $snapshot->filename],
            ['Reconciliação', sprintf('%d linhas; custo importado R$ %s.', $snapshot->row_count, $snapshot->imported_total)],
            ['Correções', sprintf(
                '%d materiais revisados; a aba Dados usa os valores corrigidos e o registro original permanece preservado.',
                $correctionCount
            )],
            ['Filtros aplicados', sprintf(
                'Centro: %s; Tipo RE/DE: %s; UM: %s.',
                $costCenter ?? 'Todos',
                $movementType ?? 'Todos',
                $unit ?? 'Todas'
            )],
            ['Identificadores', 'C Custo, Ord Producao e Produto são mantidos como texto.'],
            ['Unidades de medida', 'Quantidades de UMs diferentes nunca são somadas; M e MT permanecem separadas.'],
            ['Sobra manual', 'A coluna Sobra manual (m²) mostra a revisão mais recente do snapshot.'],
            ['Fórmulas', 'Os KPIs e resumos do Dashboard são fórmulas e podem ser recalculados no Excel.'],
        ];
        foreach ($rows as $index => [$topic, $value]) {
            $row = 4 + $index;
            $this->setText($sheet, "A{$row}", $topic);
            $this->setText($sheet, "B{$row}", $value);
        }
        $this->styleTitle($sheet, 'A1:B1');
        $this->styleHeader($sheet, 'A3:B3');
        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(100);
        $sheet->getStyle('B4:B12')->getAlignment()->setWrapText(true);
    }

    private function setText(Worksheet $sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function setNumber(Worksheet $sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, (float) $value, DataType::TYPE_NUMERIC);
    }

    private function setFormula(Worksheet $sheet, string $cell, string $formula): void
    {
        $sheet->setCellValueExplicit($cell, $formula, DataType::TYPE_FORMULA);
    }

    private function escapeFormulaText(string $value): string
    {
        return str_replace('"', '""', $value);
    }

    private function styleTitle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '17365D']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(26);
    }

    private function styleSubtitle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['italic' => true, 'color' => ['rgb' => '44546A']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(30);
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
    }
}
