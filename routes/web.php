<?php

use App\Http\Controllers\Api\CandlestickController;
use App\Http\Controllers\Api\DashboardOptionsController;
use App\Http\Controllers\Api\DashboardSnapshotsController;
use App\Http\Controllers\Api\DashboardSummaryController;
use App\Http\Controllers\Api\DashboardUnitChartsController;
use App\Http\Controllers\Api\SnapshotDayController;
use App\Http\Controllers\Api\SnapshotExcelExportController;
use App\Http\Controllers\MaterialArchiveController;
use App\Http\Controllers\MaterialMovementCorrectionController;
use App\Http\Controllers\MaterialScrapPointingController;
use App\Http\Controllers\MaterialSnapshotDeleteController;
use App\Http\Controllers\MaterialSnapshotUploadController;
use App\Http\Controllers\SnapshotOriginalFileController;
use App\Http\Middleware\LocalDashboardAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(LocalDashboardAccess::class)->prefix('api')->name('api.')->group(function (): void {
    Route::get('/dashboard/candlestick', CandlestickController::class)
        ->name('dashboard.candlestick');
    Route::get('/dashboard/summary', DashboardSummaryController::class)
        ->name('dashboard.summary');
    Route::get('/dashboard/snapshots', DashboardSnapshotsController::class)
        ->name('dashboard.snapshots');
    Route::get('/dashboard/options', DashboardOptionsController::class)
        ->name('dashboard.options');
    Route::get('/dashboard/units', DashboardUnitChartsController::class)
        ->name('dashboard.units');
    Route::get('/snapshots', SnapshotDayController::class)
        ->name('snapshots.index');
    Route::get('/snapshots/{snapshot}/export', SnapshotExcelExportController::class)
        ->whereNumber('snapshot')
        ->name('snapshots.export');
});

Route::middleware(LocalDashboardAccess::class)->group(function (): void {
    Route::get('/', fn () => redirect()->route('materials.index'))
        ->name('home');

    Route::get('/materials', MaterialArchiveController::class)
        ->name('materials.index');
    Route::post('/materials/import', MaterialSnapshotUploadController::class)
        ->name('materials.import');
    Route::put('/materials/movements/{movement}', [MaterialMovementCorrectionController::class, 'update'])
        ->whereNumber('movement')
        ->name('materials.movements.update');
    Route::get('/materials/scrap-pointing', [MaterialScrapPointingController::class, 'index'])
        ->name('materials.scraps.index');
    Route::post('/materials/scrap-pointing', [MaterialScrapPointingController::class, 'store'])
        ->name('materials.scraps.store');
    Route::delete('/materials/{snapshot}', MaterialSnapshotDeleteController::class)
        ->whereNumber('snapshot')
        ->name('materials.destroy');
    Route::get('/materials/{snapshot}/source', SnapshotOriginalFileController::class)
        ->whereNumber('snapshot')
        ->name('materials.source');

    Route::get('/dashboard', fn () => view('dashboard'))
        ->name('dashboard');
});
