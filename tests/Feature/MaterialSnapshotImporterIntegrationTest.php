<?php

namespace Tests\Feature;

use App\Exceptions\MaterialImportException;
use App\Services\MaterialSnapshotImporter;
use App\Services\SnapshotMetricCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\MaterialWorkbookFactory;
use Tests\TestCase;

class MaterialSnapshotImporterIntegrationTest extends TestCase
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

    public function test_it_archives_versions_and_rejects_a_same_day_duplicate_hash(): void
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
        $workOrder = 'T'.strtoupper(bin2hex(random_bytes(5)));
        $product = 'T'.strtoupper(bin2hex(random_bytes(5)));
        $unit = 'M2';
        $firstFile = $this->createWorkbook($center, $workOrder, $product, $unit, 'CHAPA A');
        $secondFile = $this->createWorkbook($center, $workOrder, $product, $unit, 'CHAPA B');
        $importer = app(MaterialSnapshotImporter::class);
        $referenceDate = CarbonImmutable::parse('2026-09-15', 'America/Sao_Paulo');

        $connection->beginTransaction();

        try {
            $firstPreview = $importer->preview($firstFile);
            $firstSnapshot = $importer->store(
                $firstPreview,
                $referenceDate,
                'snapshots/source/test-original.xlsx'
            );
            $secondSnapshot = $importer->store(
                $importer->preview($secondFile),
                $referenceDate
            );

            $this->assertSame(1, $firstSnapshot->version);
            $this->assertSame(2, $secondSnapshot->version);
            $this->assertSame('snapshots/source/test-original.xlsx', $firstSnapshot->source_file_path);
            $this->assertSame('12.50', $firstSnapshot->imported_total);
            $this->assertSame(1, $firstSnapshot->movements()->count());
            $this->assertSame(1, $firstSnapshot->scraps()->count());
            $this->assertNull($firstSnapshot->scraps()->first()->revisions()->first()->value_m2);
            $allUnitsMetric = DB::table('snapshot_metrics')
                ->where('snapshot_id', $firstSnapshot->id)
                ->where('grouping_mask', 0)
                ->first();
            $allDimensionsMetric = DB::table('snapshot_metrics')
                ->where('snapshot_id', $firstSnapshot->id)
                ->where('grouping_mask', 7)
                ->first();
            $m2Metric = DB::table('snapshot_metrics')
                ->where('snapshot_id', $firstSnapshot->id)
                ->where('grouping_mask', 4)
                ->where('unit_code_key', 'M2')
                ->first();

            $this->assertSame('12.50', $allUnitsMetric->total_cost);
            $this->assertNull($allUnitsMetric->quantity_total);
            $this->assertSame(1, $allUnitsMetric->distinct_work_orders);
            $this->assertSame($center, $allDimensionsMetric->cost_center_key);
            $this->assertSame('RE1', $allDimensionsMetric->movement_type_key);
            $this->assertSame('M2', $allDimensionsMetric->unit_code_key);
            $this->assertSame('2.500000', $m2Metric->quantity_total);
            $this->assertSame('0.000000', $m2Metric->scrap_total_m2);

            DB::table('scrap_revisions')->insert([
                'snapshot_id' => $firstSnapshot->id,
                'cost_center_code' => $center,
                'work_order' => $workOrder,
                'product_code' => $product,
                'revision' => 2,
                'value_m2' => '3.250000',
                'recorded_at' => '2026-09-15 12:00:00.000000',
            ]);
            app(SnapshotMetricCalculator::class)->rebuild((int) $firstSnapshot->id);

            $updatedM2Metric = DB::table('snapshot_metrics')
                ->where('snapshot_id', $firstSnapshot->id)
                ->where('grouping_mask', 4)
                ->where('unit_code_key', 'M2')
                ->first();
            $this->assertSame('3.250000', $updatedM2Metric->scrap_total_m2);
            $this->assertSame(1, $updatedM2Metric->reported_scrap_count);

            $this->assertSame(
                2,
                DB::table('upload_snapshots')
                    ->whereIn('id', [$firstSnapshot->id, $secondSnapshot->id])
                    ->count()
            );

            try {
                $importer->store($firstPreview, $referenceDate);
                $this->fail('A same-day duplicate hash should be rejected.');
            } catch (MaterialImportException $exception) {
                $this->assertStringContainsString('Arquivo duplicado', $exception->getMessage());
            }
        } finally {
            $connection->rollBack();
        }
    }

    private function createWorkbook(
        string $center,
        string $workOrder,
        string $product,
        string $unit,
        string $description,
        string $cost = '12.50',
        string $issueDate = '15/09/2026',
        string $document = 'DOC001'
    ): string {
        $path = MaterialWorkbookFactory::create(
            $center,
            $workOrder,
            $product,
            $unit,
            $description,
            $cost,
            $issueDate,
            $document
        );
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
