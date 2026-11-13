<?php

namespace Tests\Feature;

use App\Models\SnapshotMovement;
use App\Http\Middleware\LocalDashboardAccess;
use App\Services\EffectiveSnapshotMovementQuery;
use App\Services\MaterialSnapshotImporter;
use App\Services\ScrapPointingService;
use App\Services\SnapshotExcelExporter;
use App\Services\SnapshotMovementCorrectionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\MaterialWorkbookFactory;
use Tests\TestCase;

class MaterialSnapshotRevisionsIntegrationTest extends TestCase
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

    public function test_corrections_are_append_only_reclassify_scraps_and_enforce_the_net_area_limit(): void
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
        $newProduct = 'T'.strtoupper(bin2hex(random_bytes(5)));
        $path = MaterialWorkbookFactory::create(
            $center,
            $workOrder,
            $product,
            'M2',
            'CHAPA A',
            '12.50',
            '15/09/2026',
            'DOC001'
        );
        $this->temporaryFiles[] = $path;
        $connection->beginTransaction();

        try {
            $snapshot = app(MaterialSnapshotImporter::class)->store(
                app(MaterialSnapshotImporter::class)->preview($path),
                CarbonImmutable::parse('2026-09-15', 'America/Sao_Paulo')
            );
            $movement = SnapshotMovement::query()
                ->where('snapshot_id', $snapshot->id)
                ->firstOrFail();

            app(SnapshotMovementCorrectionService::class)->correct($movement, [
                'cost_center_code' => $center,
                'work_order' => $workOrder,
                'movement_type' => 'RE1',
                'product_code' => $newProduct,
                'description' => 'CHAPA A CORRIGIDA',
                'quantity' => '2.000000',
                'unit_code' => 'M2',
                'unit_cost' => '7.250000',
                'cost' => '14.50',
                'document' => 'DOC001',
                'issue_date' => '2026-09-15',
                'standard_cost' => null,
                'standard_total' => '0.000000',
            ], 'Operador de teste');

            $this->assertSame('12.50', $movement->fresh()->cost);
            $this->assertSame('2.000000', $movement->fresh()->revisions()->firstOrFail()->quantity);
            $effective = app(EffectiveSnapshotMovementQuery::class)
                ->forSnapshot((int) $snapshot->id)
                ->first();
            $this->assertNotNull($effective);
            $this->assertSame($newProduct, $effective->product_code);
            $this->assertSame('14.50', $effective->cost);
            $export = app(SnapshotExcelExporter::class)->build($snapshot);
            $this->assertSame(2.0, (float) $export->getSheetByName('Dados')->getCell('F5')->getValue());
            $this->assertSame(14.5, (float) $export->getSheetByName('Dados')->getCell('H5')->getValue());
            $export->disconnectWorksheets();

            $oldGroup = $snapshot->scraps()
                ->where('product_code', $product)
                ->firstOrFail();
            $newGroup = $snapshot->scraps()
                ->where('product_code', $newProduct)
                ->firstOrFail();
            $this->assertFalse($oldGroup->is_active);
            $this->assertTrue($newGroup->is_active);
            $this->assertNull($newGroup->revisions()->firstOrFail()->value_m2);

            $identity = [
                'snapshot_id' => (int) $snapshot->id,
                'cost_center_code' => $center,
                'work_order' => $workOrder,
                'product_code' => $newProduct,
            ];
            $pointings = app(ScrapPointingService::class);
            $this->assertSame('2.000000', $pointings->availableArea($identity));
            $this->assertSame(2, $pointings->record($identity, '1.500000', 'Operador de teste'));

            try {
                $pointings->record($identity, '2.000001', 'Operador de teste');
                $this->fail('A sobra acima da área líquida disponível deve ser rejeitada.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('value_m2', $exception->errors());
            }

            $scrapMetric = DB::table('snapshot_metrics')
                ->where('snapshot_id', $snapshot->id)
                ->where('grouping_mask', 4)
                ->where('unit_code_key', 'M2')
                ->first();
            $this->assertNotNull($scrapMetric);
            $this->assertSame('1.500000', $scrapMetric->scrap_total_m2);
            $this->assertSame('14.50', $scrapMetric->total_cost);
        } finally {
            $connection->rollBack();
        }
    }

    public function test_snapshot_deletion_requires_confirmation_and_removes_archived_data_and_original_file(): void
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
        $path = MaterialWorkbookFactory::create(
            $center,
            $workOrder,
            $product,
            'M2',
            'CHAPA A',
            '12.50',
            '15/09/2026',
            'DOC001'
        );
        $this->temporaryFiles[] = $path;
        Storage::fake('local');
        $originalPath = 'snapshots/source/'.Str::uuid().'.xlsx';
        Storage::disk('local')->put($originalPath, 'original');
        $connection->beginTransaction();

        try {
            $snapshot = app(MaterialSnapshotImporter::class)->store(
                app(MaterialSnapshotImporter::class)->preview($path),
                CarbonImmutable::parse('2026-09-15', 'America/Sao_Paulo'),
                $originalPath
            );

            $this->withoutMiddleware(LocalDashboardAccess::class)
                ->delete(route('materials.destroy', ['snapshot' => $snapshot->id]))
                ->assertSessionHasErrors('confirm_delete');
            $this->assertDatabaseHas('upload_snapshots', ['id' => $snapshot->id]);

            $this->withoutMiddleware(LocalDashboardAccess::class)
                ->delete(route('materials.destroy', ['snapshot' => $snapshot->id]), [
                    'confirm_delete' => '1',
                ])
                ->assertRedirect(route('materials.index'))
                ->assertSessionHas('status');

            $this->assertDatabaseMissing('upload_snapshots', ['id' => $snapshot->id]);
            $this->assertDatabaseMissing('snapshot_movements', ['snapshot_id' => $snapshot->id]);
            $this->assertDatabaseMissing('snapshot_scraps', ['snapshot_id' => $snapshot->id]);
            $this->assertDatabaseMissing('snapshot_metrics', ['snapshot_id' => $snapshot->id]);
            Storage::disk('local')->assertMissing($originalPath);
        } finally {
            $connection->rollBack();
            Storage::disk('local')->delete($originalPath);
        }
    }
}
