<?php

namespace Tests\Unit;

use App\Exceptions\MaterialImportException;
use App\Services\MaterialSnapshotImporter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MaterialSnapshotImporterTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_it_detects_shifted_headers_preserves_text_codes_and_groups_scraps(): void
    {
        $path = $this->createWorkbook();
        $preview = app(MaterialSnapshotImporter::class)->preview($path);

        $this->assertSame(3, $preview->sourceRowCount);
        $this->assertSame('0.60', $preview->sourceTotal);
        $this->assertSame('0.60', $preview->importedTotal);
        $this->assertSame('0010256', $preview->movements[0]['cost_center_code']);
        $this->assertSame('00001234', $preview->movements[0]['work_order']);
        $this->assertSame('00099', $preview->movements[0]['product_code']);
        $this->assertNull($preview->movements[0]['standard_cost']);
        $this->assertCount(1, $preview->scraps);
        $this->assertSame('CHAPA A', $preview->scraps[0]['description']);
        $this->assertContains('99999', $preview->newCostCenters);
        $this->assertSame('ZZ', $preview->newUnits[0]);
    }

    public function test_it_preserves_the_uploaded_filename_instead_of_the_temporary_path(): void
    {
        $workbookPath = $this->createWorkbook();
        $temporaryPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'upload-'.bin2hex(random_bytes(8));
        $this->assertTrue(copy($workbookPath, $temporaryPath));
        $this->temporaryFiles[] = $temporaryPath;

        $preview = app(MaterialSnapshotImporter::class)->preview(
            $temporaryPath,
            'C:\\fakepath\\Controle_Materiais.xlsx'
        );

        $this->assertSame('Controle_Materiais.xlsx', $preview->filename);
    }

    public function test_it_rejects_a_missing_required_column(): void
    {
        $path = $this->createWorkbook(removeHeader: 'Documento');

        $this->expectException(MaterialImportException::class);
        $this->expectExceptionMessage('Colunas obrigatorias ausentes: Documento.');

        app(MaterialSnapshotImporter::class)->preview($path);
    }

    public function test_it_rejects_a_total_that_does_not_reconcile(): void
    {
        $path = $this->createWorkbook(totalFormula: '=0');

        $this->expectException(MaterialImportException::class);
        $this->expectExceptionMessage('Reconciliacao divergente');

        app(MaterialSnapshotImporter::class)->preview($path);
    }

    public function test_it_rejects_an_invalid_issue_date(): void
    {
        $path = $this->createWorkbook(issueDate: '31/02/2026');

        $this->expectException(MaterialImportException::class);
        $this->expectExceptionMessage('DT Emissao invalida');

        app(MaterialSnapshotImporter::class)->preview($path);
    }

    public function test_it_rejects_decimal_values_beyond_the_database_scale(): void
    {
        $path = $this->createWorkbook(quantity: '2,0000001');

        $this->expectException(MaterialImportException::class);
        $this->expectExceptionMessage('Quantidade possui mais de 6 casas decimais');

        app(MaterialSnapshotImporter::class)->preview($path);
    }

    private function createWorkbook(
        ?string $removeHeader = null,
        string $totalFormula = '',
        string $issueDate = '15/09/2026',
        string $quantity = '2,000000'
    ): string {
        $headers = array_reverse([
            'C Custo',
            'Ord Producao',
            'Tipo RE/DE',
            'Produto',
            'Descricao',
            'Quantidade',
            'UM',
            'Custo Unitario',
            'Custo',
            'Documento',
            'DT Emissao',
            'Custo Stand.',
            'TotalSTD',
        ]);
        $columnByHeader = [];
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Relatorio de movimentacoes');
        $headerRow = 4;

        foreach ($headers as $index => $header) {
            if ($header === $removeHeader) {
                continue;
            }

            $column = $index + 1;
            $columnByHeader[$header] = $column;
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($column).$headerRow, $header);
        }

        $movements = [
            [
                'C Custo' => '0010256',
                'Ord Producao' => '00001234',
                'Tipo RE/DE' => 'RE1',
                'Produto' => '00099',
                'Descricao' => 'CHAPA A',
                'Quantidade' => $quantity,
                'UM' => 'M2',
                'Custo Unitario' => '1,250000',
                'Custo' => '0,10',
                'Documento' => '0001',
                'DT Emissao' => $issueDate,
                'Custo Stand.' => ',   ,   ,   .',
                'TotalSTD' => '0,000000',
            ],
            [
                'C Custo' => '0010256',
                'Ord Producao' => '00001234',
                'Tipo RE/DE' => 'RE1',
                'Produto' => '00099',
                'Descricao' => 'CHAPA A',
                'Quantidade' => '3,000000',
                'UM' => 'M2',
                'Custo Unitario' => '1,166667',
                'Custo' => '0,20',
                'Documento' => '0002',
                'DT Emissao' => '15/09/2026',
                'Custo Stand.' => '1,500000',
                'TotalSTD' => '0,000000',
            ],
            [
                'C Custo' => '99999',
                'Ord Producao' => '00009999',
                'Tipo RE/DE' => 'PR0',
                'Produto' => '99999',
                'Descricao' => 'GRADE DE PROTECAO',
                'Quantidade' => '1,000000',
                'UM' => 'ZZ',
                'Custo Unitario' => '4,000000',
                'Custo' => '0,30',
                'Documento' => '0003',
                'DT Emissao' => '2026-09-15',
                'Custo Stand.' => null,
                'TotalSTD' => '0,000000',
            ],
        ];

        foreach ($movements as $offset => $movement) {
            $row = $headerRow + 1 + $offset;

            foreach ($movement as $header => $value) {
                $column = $columnByHeader[$header] ?? null;

                if ($column !== null) {
                    $coordinate = Coordinate::stringFromColumnIndex($column).$row;

                    if (in_array($header, [
                        'C Custo',
                        'Ord Producao',
                        'Tipo RE/DE',
                        'Produto',
                        'Descricao',
                        'UM',
                        'Documento',
                        'DT Emissao',
                    ], true)) {
                        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
                    } else {
                        $sheet->setCellValue($coordinate, $value);
                    }
                }
            }
        }

        $totalRow = $headerRow + 1 + count($movements);
        $sheet->setCellValue(
            Coordinate::stringFromColumnIndex($columnByHeader['Custo']).$totalRow,
            $totalFormula !== '' ? $totalFormula : '=0.6'
        );

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'materials-'.bin2hex(random_bytes(8)).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
