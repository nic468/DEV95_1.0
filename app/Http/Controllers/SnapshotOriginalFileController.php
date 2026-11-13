<?php

namespace App\Http\Controllers;

use App\Models\UploadSnapshot;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SnapshotOriginalFileController extends Controller
{
    public function __invoke(UploadSnapshot $snapshot): StreamedResponse
    {
        $path = $snapshot->source_file_path;

        if ($path === null || ! str_starts_with($path, 'snapshots/source/')) {
            abort(404, 'O arquivo original não está disponível para este snapshot.');
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            Log::error('Arquivo original de snapshot ausente no armazenamento privado.', [
                'snapshot_id' => $snapshot->id,
                'source_file_path' => $path,
            ]);
            abort(404, 'O arquivo original não foi encontrado no armazenamento privado.');
        }

        return $disk->download($path, $snapshot->filename);
    }
}
