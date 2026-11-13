<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snapshot_scraps', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });

        Schema::create('snapshot_movement_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_movement_id')
                ->constrained('snapshot_movements', 'id', 'fk_movement_revision_source');
            $table->unsignedInteger('revision');
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
            $table->dateTime('recorded_at', 6);
            $table->string('recorded_by', 256);

            $table->foreign('cost_center_code', 'fk_movement_revision_cost_center')
                ->references('code')
                ->on('cost_centers');
            $table->foreign('unit_code', 'fk_movement_revision_unit')
                ->references('code')
                ->on('units_of_measure');
            $table->unique(
                ['snapshot_movement_id', 'revision'],
                'uq_snapshot_movement_revision'
            );
        });

        DB::statement(
            'ALTER TABLE snapshot_movement_revisions ADD CONSTRAINT ck_movement_revision_positive CHECK (revision > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshot_movement_revisions');

        Schema::table('snapshot_scraps', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
