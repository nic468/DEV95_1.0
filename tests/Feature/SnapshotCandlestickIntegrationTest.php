<?php

namespace Tests\Feature;

use App\Data\DashboardFilters;
use App\Models\UploadSnapshot;
use App\Models\User;
use App\Services\MaterialSnapshotImporter;
use App\Services\SnapshotCandlestickService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\MaterialWorkbookFactory;
use Tests\TestCase;

class SnapshotCandlestickIntegrationTest extends TestCase
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

    public function test_it_builds_filtered_ohlc_dashboard_unit_charts_and_snapshot_details_from_real_snapshots(): void
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
        $importer = app(MaterialSnapshotImporter::class);
        $connection->beginTransaction();

        try {
            $snapshots = [
                $this->import($importer, $center, $workOrder, $product, 'M2', 'CHAPA 1', '1.00', '2026-09-01', 'DOC01'),
                $this->import($importer, $center, $workOrder, $product, 'M2', 'CHAPA 2', '10.00', '2026-09-02', 'DOC02'),
                $this->import($importer, $center, $workOrder, $product, 'HH', 'ITEM HH', '20.00', '2026-09-02', 'DOC03'),
                $this->import($importer, $center, $workOrder, $product, 'M2', 'CHAPA 3', '5.00', '2026-09-03', 'DOC04'),
                $this->import($importer, $center, $workOrder, $product, 'M2', 'CHAPA 4', '7.00', '2026-09-05', 'DOC05'),
            ];

            $candlestickService = app(SnapshotCandlestickService::class);
            $candles = $candlestickService->candles(
                'cost',
                new DashboardFilters(
                    CarbonImmutable::parse('2026-09-01'),
                    CarbonImmutable::parse('2026-09-05'),
                    $center,
                    null,
                    null
                )
            );

            $this->assertCount(4, $candles);
            $this->assertSame('2026-09-01', $candles[0]['date']);
            $this->assertSame(1.0, $candles[0]['open']);
            $this->assertSame(1.0, $candles[0]['close']);
            $this->assertTrue($candles[0]['doji']);

            $this->assertSame('2026-09-02', $candles[1]['date']);
            $this->assertSame(10.0, $candles[1]['open']);
            $this->assertSame(20.0, $candles[1]['high']);
            $this->assertSame(10.0, $candles[1]['low']);
            $this->assertSame(20.0, $candles[1]['close']);
            $this->assertSame(2, $candles[1]['update_count']);

            $this->assertSame('2026-09-03', $candles[2]['date']);
            $this->assertSame(20.0, $candles[2]['open']);
            $this->assertSame(5.0, $candles[2]['close']);
            $this->assertTrue($candles[2]['derived']);
            $this->assertSame(-15.0, $candles[2]['variation']);

            $this->assertSame('2026-09-05', $candles[3]['date']);
            $this->assertSame(5.0, $candles[3]['open']);
            $this->assertSame(7.0, $candles[3]['close']);

            $m2Only = $candlestickService->candles(
                'cost',
                new DashboardFilters(
                    CarbonImmutable::parse('2026-09-02'),
                    CarbonImmutable::parse('2026-09-02'),
                    $center,
                    null,
                    'M2'
                )
            );
            $this->assertSame(10.0, $m2Only[0]['open']);
            $this->assertSame(0.0, $m2Only[0]['close']);

            $allUnitsMetric = DB::table('snapshot_metrics')
                ->where('snapshot_id', $snapshots[1]->id)
                ->where('grouping_mask', 1)
                ->where('cost_center_key', $center)
                ->first();
            $this->assertNull($allUnitsMetric->quantity_total);

            $monthResponse = $this->actingAs(new User)
                ->getJson("/api/dashboard/candlestick?year=2026&month=9&cost_center={$center}")
                ->assertOk();
            $customResponse = $this->actingAs(new User)
                ->getJson(
                    "/api/dashboard/candlestick?start_date=2026-09-01&end_date=2026-09-05&cost_center={$center}"
                )
                ->assertOk();
            $this->assertSame($monthResponse->json('candles'), $customResponse->json('candles'));

            $summary = $this->actingAs(new User)
                ->getJson(
                    "/api/dashboard/summary?start_date=2026-09-01&end_date=2026-09-05&cost_center={$center}&unit=M2"
                )
                ->assertOk();
            $this->assertSame('2026-09-05', $summary->json('snapshot.reference_date'));
            $this->assertSame('7.00', $summary->json('kpis.total_cost'));
            $this->assertSame(1, $summary->json('kpis.record_count'));

            $snapshotList = $this->actingAs(new User)
                ->getJson(
                    "/api/dashboard/snapshots?start_date=2026-09-01&end_date=2026-09-05&cost_center={$center}&unit=M2"
                )
                ->assertOk();
            $this->assertSame(5, $snapshotList->json('pagination.total'));
            $this->assertSame('2026-09-05', $snapshotList->json('data.0.reference_date'));
            $this->assertSame('7.00', $snapshotList->json('data.0.total_cost'));
            $this->assertSame(0, $snapshotList->json('data.2.record_count'));

            $unitCharts = $this->actingAs(new User)
                ->getJson(
                    "/api/dashboard/units?start_date=2026-09-01&end_date=2026-09-05&cost_center={$center}"
                )
                ->assertOk();
            $unitsByCode = collect($unitCharts->json('units'))->keyBy('code');
            $this->assertSame(['HH', 'M2'], $unitsByCode->keys()->all());
            $this->assertSame('2026-09-05', $unitCharts->json('latest_snapshot.reference_date'));
            $this->assertSame('2.500000', $unitsByCode->get('M2')['quantity_total']);
            $this->assertSame('7.00', $unitsByCode->get('M2')['total_cost']);
            $this->assertSame(
                ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-05'],
                array_column($unitsByCode->get('M2')['daily'], 'date')
            );
            $this->assertSame('0.00', $unitsByCode->get('M2')['daily'][1]['total_cost']);
            $this->assertSame('20.00', $unitsByCode->get('HH')['daily'][1]['total_cost']);
            $this->assertSame($center, $unitsByCode->get('M2')['cost_centers'][0]['code']);

            $filteredUnitCharts = $this->actingAs(new User)
                ->getJson(
                    "/api/dashboard/units?start_date=2026-09-01&end_date=2026-09-05&cost_center={$center}&unit=M2"
                )
                ->assertOk();
            $this->assertSame(['M2'], array_column($filteredUnitCharts->json('units'), 'code'));

            $this->import($importer, $center, $workOrder, $product, 'M', 'ITEM M', '2.00', '2026-09-06', 'DOC06');
            $this->import($importer, $center, $workOrder, $product, 'MT', 'ITEM MT', '3.00', '2026-09-07', 'DOC07');
            $separateUnits = $this->actingAs(new User)
                ->getJson(
                    "/api/dashboard/units?start_date=2026-09-06&end_date=2026-09-07&cost_center={$center}"
                )
                ->assertOk();
            $separateUnitCodes = array_column($separateUnits->json('units'), 'code');
            $this->assertSame(['M', 'MT'], array_values(array_intersect(['M', 'MT'], $separateUnitCodes)));

            $details = $this->actingAs(new User)
                ->getJson('/api/snapshots?date=2026-09-02')
                ->assertOk();
            $this->assertCount(2, $details->json('snapshots'));
            $this->assertSame(1, $details->json('snapshots.0.version'));
            $this->assertSame(2, $details->json('snapshots.1.version'));
            $this->assertSame('10.00', $details->json('snapshots.0.total_cost'));
        } finally {
            $connection->rollBack();
        }
    }

    private function import(
        MaterialSnapshotImporter $importer,
        string $center,
        string $workOrder,
        string $product,
        string $unit,
        string $description,
        string $cost,
        string $date,
        string $document
    ): UploadSnapshot {
        $path = MaterialWorkbookFactory::create(
            $center,
            $workOrder,
            $product,
            $unit,
            $description,
            $cost,
            CarbonImmutable::parse($date)->format('d/m/Y'),
            $document
        );
        $this->temporaryFiles[] = $path;

        return $importer->store(
            $importer->preview($path),
            CarbonImmutable::parse($date, 'America/Sao_Paulo')
        );
    }
}
