<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WeeklyImport;
use App\Services\Imports\PemiIsuzuReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use RuntimeException;

class ImportActualController extends Controller
{
    public function show(int $id, PemiIsuzuReader $reader): JsonResponse
    {
        $import = $this->findWeeklyImport($id);

        return response()->json([
            'success' => true,
            'data' => [
                'periods' => $this->readPeriods($import, $reader),
                'snapshot_date' => $import->snapshot_date->toDateString(),
                'actual_until_date' => data_get($import->metadata, 'actual_until_date'),
            ],
        ]);
    }

    public function update(
        Request $request,
        int $id,
        PemiIsuzuReader $reader
    ): JsonResponse {
        $validated = $request->validate([
            'actual_until_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $import = $this->findWeeklyImport($id);
        $actualUntilDate = $validated['actual_until_date'];
        $periods = $this->readPeriods($import, $reader);

        if (!in_array($actualUntilDate, $periods, true)) {
            throw ValidationException::withMessages([
                'actual_until_date' => 'Pilih salah satu SR ISSUE DATE dari file snapshot ini.',
            ]);
        }

        DB::transaction(function () use ($id, $actualUntilDate): void {
            $lockedImport = WeeklyImport::query()
                ->where('import_type', 'weekly')
                ->where('status', 'completed')
                ->whereNotNull('snapshot_date')
                ->lockForUpdate()
                ->findOrFail($id);

            $metadata = $lockedImport->metadata ?? [];
            $metadata['actual_until_date'] = $actualUntilDate;

            $lockedImport->metadata = $metadata;
            $lockedImport->save();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'actual_until_date' => $actualUntilDate,
            ],
        ]);
    }

    private function findWeeklyImport(int $id): WeeklyImport
    {
        return WeeklyImport::query()
            ->where('import_type', 'weekly')
            ->where('status', 'completed')
            ->whereNotNull('snapshot_date')
            ->findOrFail($id);
    }

    private function readPeriods(
        WeeklyImport $import,
        PemiIsuzuReader $reader
    ): array {
        $storagePath = data_get($import->metadata, 'storage_path');
        $disk = Storage::disk('local');

        if (!is_string($storagePath) || $storagePath === '' || !$disk->exists($storagePath)) {
            abort(422, 'File Excel untuk snapshot ini tidak ditemukan.');
        }

        try {
            return $reader->previewPeriods(
                $disk->path($storagePath),
                $import->snapshot_date->toDateString()
            );
        } catch (RuntimeException | SpreadsheetException $exception) {
            report($exception);

            abort(422, 'Daftar SR ISSUE DATE dari file snapshot gagal dibaca.');
        }
    }
}