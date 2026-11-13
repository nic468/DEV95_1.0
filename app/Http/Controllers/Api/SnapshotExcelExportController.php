<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UploadSnapshot;
use App\Services\SnapshotExcelExporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SnapshotExcelExportController extends Controller
{
    public function __invoke(
        Request $request,
        UploadSnapshot $snapshot,
        SnapshotExcelExporter $exporter
    ): BinaryFileResponse {
        $filters = $request->validate([
            'cost_center' => ['nullable', 'string', 'max:20'],
            'movement_type' => ['nullable', 'string', 'max:12'],
            'unit' => ['nullable', 'string', 'max:10'],
        ]);

        return $exporter->download($snapshot, $filters);
    }
}
