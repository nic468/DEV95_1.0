<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaterialControlSchemaIntegrationTest extends TestCase
{
    public function test_schema_constraints_and_duplicate_source_rows_on_mysql(): void
    {
        if (env('RUN_MYSQL_INTEGRATION_TESTS') !== true) {
            $this->markTestSkipped('Set RUN_MYSQL_INTEGRATION_TESTS=true for a dedicated MySQL test database.');
        }

        $expectedDatabase = env('MYSQL_TEST_DATABASE');
        $connection = DB::connection();

        $this->assertNotEmpty($expectedDatabase);
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame($expectedDatabase, $connection->getDatabaseName());

        $connection->beginTransaction();

        try {
            $centerCode = 'T'.Str::upper(Str::random(9));
            $unitCode = 'T'.Str::upper(Str::random(5));
            $workOrder = 'T'.Str::upper(Str::random(10));
            $productCode = 'T'.Str::upper(Str::random(10));
            $document = 'T'.Str::upper(Str::random(8));
            $hash = hash('sha256', Str::random(40));

            DB::table('cost_centers')->insert([
                'code' => $centerCode,
                'name' => 'Centro de teste',
            ]);
            DB::table('units_of_measure')->insert(['code' => $unitCode]);

            $snapshotId = DB::table('upload_snapshots')->insertGetId([
                'reference_date' => '2026-09-01',
                'version' => 1,
                'filename' => 'fixture.xlsx',
                'sha256' => $hash,
                'imported_at' => '2026-09-01 12:00:00.000000',
                'row_count' => 2,
                'source_total' => '40.22',
                'imported_total' => '40.22',
                'reconciliation_difference' => '0.00',
                'reconciliation_status' => 'matched',
            ]);

            $movement = [
                'snapshot_id' => $snapshotId,
                'cost_center_code' => $centerCode,
                'work_order' => $workOrder,
                'movement_type' => 'RE1',
                'product_code' => $productCode,
                'description' => 'CHAPA AÇO',
                'quantity' => '1.000000',
                'unit_code' => $unitCode,
                'unit_cost' => '20.110000',
                'cost' => '20.11',
                'document' => $document,
                'issue_date' => '2026-09-01',
                'standard_cost' => null,
                'standard_total' => '0.000000',
            ];

            DB::table('snapshot_movements')->insert([
                ...$movement,
                'source_row' => 3,
            ]);
            DB::table('snapshot_movements')->insert([
                ...$movement,
                'source_row' => 4,
            ]);

            $this->assertSame(
                2,
                DB::table('snapshot_movements')
                    ->where('snapshot_id', $snapshotId)
                    ->count()
            );
            $this->assertSame(
                'CHAPA AÇO',
                DB::table('snapshot_movements')
                    ->where('snapshot_id', $snapshotId)
                    ->value('description')
            );

            $this->assertIntegrityViolation(fn () => DB::table('snapshot_movements')->insert([
                ...$movement,
                'source_row' => 3,
            ]));
            $this->assertIntegrityViolation(fn () => DB::table('snapshot_movements')->insert([
                ...$movement,
                'source_row' => 0,
            ]));

            $this->assertIntegrityViolation(fn () => DB::table('upload_snapshots')->insert([
                'reference_date' => '2026-09-01',
                'version' => 2,
                'filename' => 'duplicate.xlsx',
                'sha256' => $hash,
                'imported_at' => '2026-09-01 13:00:00.000000',
                'row_count' => 0,
                'source_total' => '0.00',
                'imported_total' => '0.00',
                'reconciliation_difference' => '0.00',
                'reconciliation_status' => 'matched',
            ]));

            DB::table('snapshot_scraps')->insert([
                'snapshot_id' => $snapshotId,
                'cost_center_code' => $centerCode,
                'work_order' => $workOrder,
                'product_code' => $productCode,
                'description' => 'CHAPA AÇO',
                'movement_type' => 'RE1',
            ]);

            foreach ([1, 2] as $revision) {
                DB::table('scrap_revisions')->insert([
                    'snapshot_id' => $snapshotId,
                    'cost_center_code' => $centerCode,
                    'work_order' => $workOrder,
                    'product_code' => $productCode,
                    'revision' => $revision,
                    'value_m2' => $revision === 1 ? null : '0.000000',
                    'recorded_at' => '2026-09-01 12:00:00.000000',
                ]);
            }

            $this->assertSame(
                2,
                DB::table('scrap_revisions')
                    ->where('snapshot_id', $snapshotId)
                    ->count()
            );
            $this->assertIntegrityViolation(fn () => DB::table('scrap_revisions')->insert([
                'snapshot_id' => $snapshotId,
                'cost_center_code' => $centerCode,
                'work_order' => $workOrder,
                'product_code' => $productCode,
                'revision' => 3,
                'value_m2' => '-1.000000',
                'recorded_at' => '2026-09-01 12:00:00.000000',
            ]));
        } finally {
            $connection->rollBack();
        }
    }

    private function assertIntegrityViolation(callable $operation): void
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0] ?? null);

            return;
        }

        $this->fail('Expected a database integrity constraint violation.');
    }
}
