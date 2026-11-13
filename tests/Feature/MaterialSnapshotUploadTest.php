<?php

namespace Tests\Feature;

use App\Data\MaterialImportPreview;
use App\Exceptions\MaterialImportException;
use App\Models\UploadSnapshot;
use App\Services\MaterialSnapshotImporter;
use App\Http\Controllers\SnapshotOriginalFileController;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaterialSnapshotUploadTest extends TestCase
{
    public function test_it_imports_a_valid_upload_and_keeps_the_original_in_private_storage(): void
    {
        $this->app['env'] = 'local';
        Storage::fake('local');
        $preview = new MaterialImportPreview(
            filename: 'materials.xlsx',
            sha256: str_repeat('a', 64),
            sourceRowCount: 1,
            sourceTotal: '12.50',
            importedTotal: '12.50',
            movements: [],
            scraps: [],
            newCostCenters: ['99999'],
            newUnits: ['ZZ'],
            warnings: [],
        );
        $snapshot = new UploadSnapshot([
            'reference_date' => '2026-11-12',
            'version' => 2,
            'row_count' => 1,
            'imported_total' => '12.50',
        ]);
        $storedPath = null;
        $importer = \Mockery::mock(MaterialSnapshotImporter::class);
        $importer->shouldReceive('preview')
            ->once()
            ->withArgs(fn (string $path, string $filename): bool => is_file($path) && $filename === 'materials.xlsx')
            ->andReturn($preview);
        $importer->shouldReceive('store')
            ->once()
            ->withArgs(function (MaterialImportPreview $actualPreview, $referenceDate, string $path) use (
                $preview,
                &$storedPath
            ): bool {
                $storedPath = $path;

                return $actualPreview === $preview
                    && $referenceDate->toDateString() === '2026-11-12'
                    && str_starts_with($path, 'snapshots/source/')
                    && str_ends_with($path, '.xlsx');
            })
            ->andReturn($snapshot);
        $this->app->instance(MaterialSnapshotImporter::class, $importer);

        $this->postWithCsrf(route('materials.import'), [
                'file' => UploadedFile::fake()->createWithContent('materials.xlsx', 'xlsx contents'),
                'reference_date' => '2026-11-12',
            ])
            ->assertRedirect(route('materials.index', ['date' => '2026-11-12']))
            ->assertSessionHas('status')
            ->assertSessionHas('import_warnings', function (array $warnings): bool {
                return count($warnings) === 2;
            });

        $this->assertIsString($storedPath);
        Storage::disk('local')->assertExists($storedPath);
    }

    public function test_a_reconciliation_failure_does_not_store_the_original_or_snapshot(): void
    {
        $this->app['env'] = 'local';
        Storage::fake('local');
        $importer = \Mockery::mock(MaterialSnapshotImporter::class);
        $importer->shouldReceive('preview')
            ->once()
            ->andThrow(new MaterialImportException('Reconciliacao divergente.'));
        $importer->shouldNotReceive('store');
        $this->app->instance(MaterialSnapshotImporter::class, $importer);

        $this->from(route('materials.index'))
            ->postWithCsrf(route('materials.import'), [
                'file' => UploadedFile::fake()->createWithContent('materials.xlsx', 'invalid workbook'),
                'reference_date' => '2026-11-12',
            ])
            ->assertRedirect(route('materials.index'))
            ->assertSessionHasErrors('file');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_non_xlsx_extensions_are_rejected_before_parsing(): void
    {
        $this->app['env'] = 'local';
        Storage::fake('local');
        $importer = \Mockery::mock(MaterialSnapshotImporter::class);
        $importer->shouldNotReceive('preview');
        $this->app->instance(MaterialSnapshotImporter::class, $importer);

        $this->from(route('materials.index'))
            ->postWithCsrf(route('materials.import'), [
                'file' => UploadedFile::fake()->createWithContent('materials.xlsm', 'macro workbook'),
                'reference_date' => '2026-11-12',
            ])
            ->assertRedirect(route('materials.index'))
            ->assertSessionHasErrors('file');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_duplicate_detected_during_store_removes_the_staged_original(): void
    {
        $this->app['env'] = 'local';
        Storage::fake('local');
        $preview = new MaterialImportPreview(
            filename: 'materials.xlsx',
            sha256: str_repeat('b', 64),
            sourceRowCount: 1,
            sourceTotal: '12.50',
            importedTotal: '12.50',
            movements: [],
            scraps: [],
            newCostCenters: [],
            newUnits: [],
            warnings: [],
        );
        $importer = \Mockery::mock(MaterialSnapshotImporter::class);
        $importer->shouldReceive('preview')->once()->andReturn($preview);
        $importer->shouldReceive('store')
            ->once()
            ->andThrow(new MaterialImportException('Arquivo duplicado.'));
        $this->app->instance(MaterialSnapshotImporter::class, $importer);

        $this->from(route('materials.index'))
            ->postWithCsrf(route('materials.import'), [
                'file' => UploadedFile::fake()->createWithContent('materials.xlsx', 'xlsx contents'),
                'reference_date' => '2026-11-12',
            ])
            ->assertRedirect(route('materials.index'))
            ->assertSessionHasErrors('file');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_non_loopback_visitors_cannot_upload_materials(): void
    {
        $this->app['env'] = 'local';
        $importer = \Mockery::mock(MaterialSnapshotImporter::class);
        $importer->shouldNotReceive('preview');
        $this->app->instance(MaterialSnapshotImporter::class, $importer);

        $this->postWithCsrf(route('materials.import'), [], '192.0.2.10')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->get('/materials/1/source')
            ->assertForbidden();
    }

    public function test_original_download_uses_the_private_archived_path_and_client_filename(): void
    {
        Storage::fake('local');
        $path = 'snapshots/source/8f8c5c6e-86a0-4f66-84ef-e9b36b7b1fc0.xlsx';
        Storage::disk('local')->put($path, 'workbook contents');
        $snapshot = new UploadSnapshot([
            'filename' => 'materials-original.xlsx',
            'source_file_path' => $path,
        ]);

        $response = app(SnapshotOriginalFileController::class)($snapshot);

        $this->assertStringContainsString(
            'materials-original.xlsx',
            (string) $response->headers->get('content-disposition')
        );
    }

    private function postWithCsrf(string $url, array $data, string $remoteAddress = '127.0.0.1')
    {
        $token = str_repeat('a', 40);

        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
            ->withSession(['_token' => $token])
            ->post($url, ['_token' => $token] + $data);
    }
}
