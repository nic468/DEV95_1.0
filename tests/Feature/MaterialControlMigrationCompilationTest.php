<?php

namespace Tests\Feature;

use Illuminate\Database\MySqlConnection;
use PDO;
use Tests\TestCase;

class MaterialControlMigrationCompilationTest extends TestCase
{
    public function test_initial_migration_compiles_for_mysql_and_reverses(): void
    {
        $database = app('db');
        $database->extend('mysql', function (array $config): MySqlConnection {
            $pdo = new class extends PDO
            {
                public function __construct() {}

                public function getAttribute(int $attribute): mixed
                {
                    if ($attribute === PDO::ATTR_SERVER_VERSION) {
                        return '10.4.32-MariaDB';
                    }

                    if ($attribute === PDO::ATTR_DRIVER_NAME) {
                        return 'mysql';
                    }

                    return false;
                }
            };

            return new MySqlConnection(
                $pdo,
                $config['database'],
                $config['prefix'] ?? '',
                $config,
            );
        });
        $database->purge('mysql');

        $connection = $database->connection('mysql');
        $migrationPath = database_path(
            'migrations/2026_11_12_000000_create_material_control_schema.php'
        );

        $up = $connection->pretend(function () use ($migrationPath): void {
            (require $migrationPath)->up();
        });
        $down = $connection->pretend(function () use ($migrationPath): void {
            (require $migrationPath)->down();
        });

        $statements = array_column($up, 'query');
        $sql = implode("\n", $statements);

        $this->assertCount(7, $down);
        foreach ([
            'cost_centers',
            'units_of_measure',
            'upload_snapshots',
            'snapshot_movements',
            'snapshot_scraps',
            'scrap_revisions',
            'snapshot_metrics',
        ] as $table) {
            $this->assertStringContainsString($table, $sql);
        }
        $this->assertStringContainsString("collate 'utf8mb4_bin'", $sql);
        $this->assertStringContainsString('datetime(6)', $sql);
        $this->assertStringContainsString('CHECK', $sql);
        $this->assertStringNotContainsString('datetimeoffset', strtolower($sql));

        $sourceFileMigrationPath = database_path(
            'migrations/2026_11_12_000001_add_source_file_path_to_upload_snapshots.php'
        );
        $sourceFileUp = $connection->pretend(function () use ($sourceFileMigrationPath): void {
            (require $sourceFileMigrationPath)->up();
        });
        $sourceFileDown = $connection->pretend(function () use ($sourceFileMigrationPath): void {
            (require $sourceFileMigrationPath)->down();
        });

        $this->assertStringContainsString(
            'source_file_path',
            implode("\n", array_column($sourceFileUp, 'query'))
        );
        $this->assertStringContainsString(
            'drop',
            strtolower(implode("\n", array_column($sourceFileDown, 'query')))
        );

        $revisionMigrationPath = database_path(
            'migrations/2026_11_13_000001_create_snapshot_movement_revisions_and_activate_scraps.php'
        );
        $revisionUp = $connection->pretend(function () use ($revisionMigrationPath): void {
            (require $revisionMigrationPath)->up();
        });
        $revisionDown = $connection->pretend(function () use ($revisionMigrationPath): void {
            (require $revisionMigrationPath)->down();
        });
        $revisionSql = implode("\n", array_column($revisionUp, 'query'));

        $this->assertStringContainsString('snapshot_movement_revisions', $revisionSql);
        $this->assertStringContainsString('is_active', $revisionSql);
        $this->assertStringContainsString('fk_movement_revision_source', $revisionSql);
        $this->assertStringContainsString('ck_movement_revision_positive', $revisionSql);
        $this->assertStringContainsString(
            'drop',
            strtolower(implode("\n", array_column($revisionDown, 'query')))
        );
    }
}
