<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MaterialSnapshotImporter;
use App\Services\SnapshotExcelExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\MaterialWorkbookFactory;
use Tests\TestCase;

class SnapshotExcelExportIntegrationTest extends TestCase
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

    public function test_it_exports_a_snapshot_with_tables_live_formulas_charts_and_latest_scrap_value(): void
    {
        if (env('RUN_MYSQL_INTEGRATION_TESTS') !== true) {
            $this->markTestSkipped('Set RUN_MYSQL_INTEGRATION_TESTS=true for a dedicated MySQL test database.');
        }

        $expectedDatabase = env('MYSQL_TEST_DATABASE');
        $connection = DB::connection();
        $this->assertNotEmpty($expectedDatabase);
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame($expectedDatabase, $connection->getDatabaseName());

        $center = 'T'.strtoupper(bin2hex(random_bytes(5)));
        $workOrder = '000123456';
        $product = '00123';
        $source = MaterialWorkbookFactory::create(
            $center,
            $workOrder,
            $product,
            'M2',
            'CHAPA A',
            '12.50',
            '15/09/2026',
            'DOC001'
        );
        $this->temporaryFiles[] = $source;
        $importer = app(MaterialSnapshotImporter::class);
        $connection->beginTransaction();

        try {
            $referenceDate = CarbonImmutable::parse('2026-09-15', 'America/Sao_Paulo');
            $snapshot = $importer->store(
                $importer->preview($source),
                $referenceDate
            );
            $secondSource = MaterialWorkbookFactory::create(
                $center,
                $workOrder,
                $product,
                'M2',
                'CHAPA B',
                '9.00',
                '15/09/2026',
                'DOC002'
            );
            $this->temporaryFiles[] = $secondSource;
            $secondSnapshot = $importer->store(
                $importer->preview($secondSource),
                $referenceDate
            );
            $this->assertSame(2, $secondSnapshot->version);

            DB::table('scrap_revisions')->insert([
                'snapshot_id' => $snapshot->id,
                'cost_center_code' => $center,
                'work_order' => $workOrder,
                'product_code' => $product,
                'revision' => 2,
                'value_m2' => '3.250000',
                'recorded_at' => '2026-09-15 12:00:00.000000',
            ]);

            $exporter = app(SnapshotExcelExporter::class);
            $spreadsheet = $exporter->build($snapshot);
            $this->assertSame([
                'Dashboard',
                'Dados',
                'Aproveitamento Chapa',
                'Resumo',
                'Leia-me',
                'Listas',
            ], $spreadsheet->getSheetNames());

            $data = $spreadsheet->getSheetByName('Dados');
            $this->assertNotNull($data);
            $this->assertSame(['DadosMovimentacoes'], $data->getTableNames());
            $this->assertSame('000123456', $data->getCell('B5')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $data->getCell('B5')->getDataType());
            $this->assertSame('00123', $data->getCell('D5')->getValue());

            $dashboard = $spreadsheet->getSheetByName('Dashboard');
            $this->assertNotNull($dashboard);
            $this->assertSame(2, $dashboard->getChartCount());
            $this->assertStringContainsString('SUMIFS', $dashboard->getCell('A7')->getValue());
            $this->assertStringContainsString('COUNTIFS', $dashboard->getCell('E7')->getValue());
            $this->assertEquals(12.5, (float) $dashboard->getCell('A7')->getCalculatedValue());
            $this->assertSame(1, (int) $dashboard->getCell('C7')->getCalculatedValue());
            $this->assertSame(1, (int) $dashboard->getCell('E7')->getCalculatedValue());
            $this->assertEquals(3.25, (float) $dashboard->getCell('G7')->getCalculatedValue());
            $this->assertEquals(12.5, (float) $dashboard->getCell('C12')->getCalculatedValue());
            $this->assertSame(1, (int) $dashboard->getCell('D12')->getCalculatedValue());
            $this->assertSame(1, (int) $dashboard->getCell('G12')->getCalculatedValue());
            $this->assertEquals(12.5, (float) $dashboard->getCell('H12')->getCalculatedValue());

            $scraps = $spreadsheet->getSheetByName('Aproveitamento Chapa');
            $this->assertNotNull($scraps);
            $this->assertSame(['ApontamentoSobra'], $scraps->getTableNames());
            $this->assertSame(3.25, $scraps->getCell('G5')->getValue());

            $filteredExport = $exporter->build($snapshot, [
                'cost_center' => $center,
                'movement_type' => 'RE1',
                'unit' => 'M2',
            ]);
            $this->assertSame('RE1', $filteredExport->getSheetByName('Dashboard')->getCell('E4')->getValue());
            $this->assertSame(
                1,
                (int) $filteredExport->getSheetByName('Dashboard')->getCell('E7')->getCalculatedValue()
            );
            $filteredExport->disconnectWorksheets();

            $versionTwo = $exporter->build($secondSnapshot);
            $this->assertSame('CHAPA B', $versionTwo->getSheetByName('Dados')->getCell('E5')->getValue());
            $this->assertEquals(
                9.0,
                (float) $versionTwo->getSheetByName('Dashboard')->getCell('A7')->getCalculatedValue()
            );
            $versionTwo->disconnectWorksheets();

            $emptyUnitExport = $exporter->build($snapshot, [
                'cost_center' => $center,
                'movement_type' => 'RE1',
                'unit' => 'HH',
            ]);
            $this->assertNull($emptyUnitExport->getSheetByName('Dados')->getCell('A5')->getValue());
            $this->assertNull($emptyUnitExport->getSheetByName('Aproveitamento Chapa')->getCell('A5')->getValue());
            $emptyUnitExport->disconnectWorksheets();

            $path = tempnam(sys_get_temp_dir(), 'snapshot-export-');
            $this->assertNotFalse($path);
            $this->temporaryFiles[] = $path;
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            $writer->setPreCalculateFormulas(false);
            $writer->setForceFullCalc(true);
            $writer->save($path);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            $reader = IOFactory::createReader('Xlsx');
            $reader->setIncludeCharts(true);
            $reopened = $reader->load($path);
            $this->assertSame(2, $reopened->getSheetByName('Dashboard')->getChartCount());
            $this->assertSame(['DadosMovimentacoes'], $reopened->getSheetByName('Dados')->getTableNames());
            $this->assertSame('000123456', $reopened->getSheetByName('Dados')->getCell('B5')->getValue());
            $this->assertSame(
                DataType::TYPE_FORMULA,
                $reopened->getSheetByName('Dashboard')->getCell('A7')->getDataType()
            );
            $reopened->disconnectWorksheets();
            unset($reopened);

            $download = $this->actingAs(new User)
                ->get("/api/snapshots/{$snapshot->id}/export?cost_center={$center}&movement_type=RE1&unit=M2")
                ->assertOk();
            $this->assertSame(
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $download->headers->get('content-type')
            );
            $this->assertStringContainsString(
                'controle_materiais_2026-09-15_v1.xlsx',
                $download->headers->get('content-disposition')
            );
            $this->temporaryFiles[] = $download->baseResponse->getFile()->getPathname();
        } finally {
            $connection->rollBack();
        }
    }
}
