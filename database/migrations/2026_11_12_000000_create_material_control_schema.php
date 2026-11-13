<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table): void {
            $table->string('code', 20)->collation('utf8mb4_bin')->primary();
            $table->string('name', 120)->nullable();
        });
        DB::statement(
            "ALTER TABLE cost_centers ADD CONSTRAINT ck_cost_center_reserved_key CHECK (code <> '__ALL__')"
        );

        Schema::create('units_of_measure', function (Blueprint $table): void {
            $table->string('code', 10)->collation('utf8mb4_bin')->primary();
        });
        DB::statement(
            "ALTER TABLE units_of_measure ADD CONSTRAINT ck_unit_reserved_key CHECK (code <> '__ALL__')"
        );

        Schema::create('upload_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('reference_date');
            $table->unsignedInteger('version');
            $table->string('filename', 260);
            $table->char('sha256', 64);
            $table->dateTime('imported_at', 6);
            $table->unsignedInteger('row_count');
            $table->decimal('source_total', 19, 2);
            $table->decimal('imported_total', 19, 2);
            $table->decimal('reconciliation_difference', 19, 2);
            $table->string('reconciliation_status', 24);
            $table->string('confirmed_by', 256)->nullable();
            $table->dateTime('confirmed_at', 6)->nullable();

            $table->unique(
                ['reference_date', 'version'],
                'uq_snapshot_date_version'
            );
            $table->unique(
                ['reference_date', 'sha256'],
                'uq_snapshot_date_sha256'
            );
        });

        Schema::create('snapshot_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')
                ->constrained('upload_snapshots', 'id', 'fk_movement_snapshot');
            $table->unsignedInteger('source_row');
            $table->string('cost_center_code', 20)->collation('utf8mb4_bin');
            $table->string('work_order', 40)->collation('utf8mb4_bin');
            $table->string('movement_type', 12)->collation('utf8mb4_bin');
            $table->string('product_code', 60)->collation('utf8mb4_bin');
            $table->string('description', 500);
            $table->decimal('quantity', 19, 6);
            $table->string('unit_code', 10)->collation('utf8mb4_bin');
            $table->decimal('unit_cost', 19, 6);
            $table->decimal('cost', 19, 2);
            $table->string('document', 40);
            $table->date('issue_date');
            $table->decimal('standard_cost', 19, 6)->nullable();
            $table->decimal('standard_total', 19, 6);

            $table->foreign('cost_center_code', 'fk_movement_cost_center')
                ->references('code')
                ->on('cost_centers');
            $table->foreign('unit_code', 'fk_movement_unit')
                ->references('code')
                ->on('units_of_measure');
            $table->unique(
                ['snapshot_id', 'source_row'],
                'uq_movement_snapshot_source_row'
            );
            $table->index(
                ['snapshot_id', 'cost_center_code', 'movement_type', 'unit_code', 'issue_date'],
                'ix_movement_snapshot_filters'
            );
            $table->index(
                ['snapshot_id', 'work_order'],
                'ix_movement_snapshot_work_order'
            );
        });

        Schema::create('snapshot_scraps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id');
            $table->string('cost_center_code', 20)->collation('utf8mb4_bin');
            $table->string('work_order', 40)->collation('utf8mb4_bin');
            $table->string('product_code', 60)->collation('utf8mb4_bin');
            $table->string('description', 500);
            $table->string('movement_type', 12)->collation('utf8mb4_bin');

            $table->unique(
                ['snapshot_id', 'cost_center_code', 'work_order', 'product_code'],
                'uq_snapshot_scrap_target'
            );
            $table->foreign('snapshot_id', 'fk_scraps_snapshot')
                ->references('id')
                ->on('upload_snapshots');
            $table->foreign('cost_center_code', 'fk_scraps_cost_center')
                ->references('code')
                ->on('cost_centers');
        });

        Schema::create('scrap_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id');
            $table->string('cost_center_code', 20)->collation('utf8mb4_bin');
            $table->string('work_order', 40)->collation('utf8mb4_bin');
            $table->string('product_code', 60)->collation('utf8mb4_bin');
            $table->unsignedInteger('revision');
            $table->decimal('value_m2', 19, 6)->nullable();
            $table->dateTime('recorded_at', 6);
            $table->string('recorded_by', 256)->nullable();

            $table->unique(
                [
                    'snapshot_id',
                    'cost_center_code',
                    'work_order',
                    'product_code',
                    'revision',
                ],
                'uq_scrap_revision_target'
            );
            $table->foreign(
                ['snapshot_id', 'cost_center_code', 'work_order', 'product_code'],
                'fk_scrap_revision_target'
            )->references(
                ['snapshot_id', 'cost_center_code', 'work_order', 'product_code']
            )->on('snapshot_scraps');
        });

        Schema::create('snapshot_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')
                ->constrained('upload_snapshots', 'id', 'fk_metric_snapshot');
            $table->unsignedTinyInteger('grouping_mask');
            $table->string('cost_center_key', 20)->collation('utf8mb4_bin');
            $table->string('movement_type_key', 12)->collation('utf8mb4_bin');
            $table->string('unit_code_key', 10)->collation('utf8mb4_bin');
            $table->unsignedInteger('record_count');
            $table->unsignedInteger('distinct_work_orders');
            $table->decimal('total_cost', 19, 2);
            $table->decimal('quantity_total', 19, 6)->nullable();
            $table->decimal('scrap_total_m2', 19, 6);
            $table->unsignedInteger('reported_scrap_count');

            $table->unique(
                [
                    'snapshot_id',
                    'grouping_mask',
                    'cost_center_key',
                    'movement_type_key',
                    'unit_code_key',
                ],
                'uq_snapshot_metric_dimensions'
            );
        });

        DB::statement(
            'ALTER TABLE upload_snapshots ADD CONSTRAINT ck_snapshot_version_positive CHECK (version > 0)'
        );
        DB::statement(
            'ALTER TABLE upload_snapshots ADD CONSTRAINT ck_snapshot_row_count_nonnegative CHECK (row_count >= 0)'
        );
        DB::statement(
            "ALTER TABLE upload_snapshots ADD CONSTRAINT ck_snapshot_reconciliation_status CHECK (reconciliation_status IN ('matched', 'confirmed_mismatch'))"
        );
        DB::statement(
            'ALTER TABLE upload_snapshots ADD CONSTRAINT ck_snapshot_reconciliation_difference CHECK (reconciliation_difference = imported_total - source_total)'
        );
        DB::statement(
            "ALTER TABLE upload_snapshots ADD CONSTRAINT ck_snapshot_reconciliation_confirmation CHECK ((reconciliation_status = 'matched' AND reconciliation_difference = 0) OR (reconciliation_status = 'confirmed_mismatch' AND reconciliation_difference <> 0 AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL))"
        );
        DB::statement(
            'ALTER TABLE snapshot_movements ADD CONSTRAINT ck_movement_source_row_positive CHECK (source_row > 0)'
        );
        DB::statement(
            'ALTER TABLE scrap_revisions ADD CONSTRAINT ck_scrap_revision_positive CHECK (revision > 0)'
        );
        DB::statement(
            'ALTER TABLE scrap_revisions ADD CONSTRAINT ck_scrap_revision_value_nonnegative CHECK (value_m2 IS NULL OR value_m2 >= 0)'
        );
        DB::statement(
            'ALTER TABLE snapshot_metrics ADD CONSTRAINT ck_metric_grouping_mask CHECK (grouping_mask BETWEEN 0 AND 7)'
        );
        DB::statement(
            'ALTER TABLE snapshot_metrics ADD CONSTRAINT ck_metric_counts_nonnegative CHECK (record_count >= 0 AND distinct_work_orders >= 0 AND reported_scrap_count >= 0)'
        );
        DB::statement(
            "ALTER TABLE snapshot_metrics ADD CONSTRAINT ck_metric_quantity_requires_unit CHECK (unit_code_key <> '__ALL__' OR quantity_total IS NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshot_metrics');
        Schema::dropIfExists('scrap_revisions');
        Schema::dropIfExists('snapshot_scraps');
        Schema::dropIfExists('snapshot_movements');
        Schema::dropIfExists('upload_snapshots');
        Schema::dropIfExists('units_of_measure');
        Schema::dropIfExists('cost_centers');
    }
};
