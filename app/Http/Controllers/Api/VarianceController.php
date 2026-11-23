<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WeeklyImport;
use App\Models\VarianceReport;
use App\Services\Imports\PemiIsuzuReader;
use App\Services\Imports\RollingVarianceService;
use App\Services\Imports\VariancePersistenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class VarianceController extends Controller
{
    /**
     * Generate a variance report from Forecast and Actual imports.
     */
    public function store(
        Request $request,
        PemiIsuzuReader $reader,
        RollingVarianceService $engine,
        VariancePersistenceService $persister
    ): JsonResponse {

        $validated = $request->validate([
            'previous_import_id' => [
                'required',
                'integer',
                'exists:weekly_imports,id',
            ],
            'current_import_id' => [
                'required',
                'integer',
                'exists:weekly_imports,id',
            ],
        ]);

        $previousId = (int) $validated['previous_import_id'];
        $currentId = (int) $validated['current_import_id'];

        if ($previousId === $currentId) {
            return response()->json([
                'success' => false,
                'message' => 'Forecast dan Actual tidak boleh sama.',
            ], 422);
        }

        $previous = WeeklyImport::findOrFail($previousId);
        $current = WeeklyImport::findOrFail($currentId);

        if (
            $previous->import_type !== 'forecast' ||
            $current->import_type !== 'actual'
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Perbandingan harus Forecast vs Actual.',
            ], 422);
        }

        if (
            $previous->status !== 'completed' ||
            $current->status !== 'completed'
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Kedua import harus berstatus completed.',
            ], 422);
        }

        $existingReport = VarianceReport::query()
            ->where('previous_import_id', $previousId)
            ->where('current_import_id', $currentId)
            ->exists();

        if ($existingReport) {
            return response()->json([
                'success' => false,
                'message' => 'Laporan untuk pasangan import ini sudah tersedia.',
            ], 409);
        }

        try {
            // Resolve private source files.
            $disk = Storage::disk('local');

            $previousPath = data_get(
                $previous->metadata,
                'storage_path'
            );

            $currentPath = data_get(
                $current->metadata,
                'storage_path'
            );

            if (
                !$previousPath ||
                !$currentPath ||
                !$disk->exists($previousPath) ||
                !$disk->exists($currentPath)
            ) {
                throw new RuntimeException(
                    'File sumber Forecast atau Actual tidak ditemukan.'
                );
            }

            // Read both Excel snapshots.
            $previousSnapshot = $reader->read(
                $disk->path($previousPath)
            );

            $currentSnapshot = $reader->read(
                $disk->path($currentPath)
            );

            // Execute rolling variance engine.
            $result = $engine->compare(
                $previousSnapshot,
                $currentSnapshot
            );

            // Persist comparison result.
            $report = $persister->save(
                $previousId,
                $currentId,
                $result
            );

            return response()->json([
                'success' => true,
                'message' => 'Variance report berhasil dibuat.',
                'data' => [
                    'report_id' => $report->id,
                    'previous_import_id' => $previousId,
                    'current_import_id' => $currentId,
                    'status' => $report->status,
                    'summary' => [
                        'total_products' => $report->total_products,
                        'new_order' => $report->new_order_count,
                        'increased' => $report->increased_count,
                        'decreased' => $report->decreased_count,
                        'mixed' => $report->mixed_count,
                        'unchanged' => $report->unchanged_count,
                        'needs_review' => $report->needs_review_count,
                        'total_changes' => $report->total_changes,
                    ],
                ],
            ], 201);

        } catch (RuntimeException $exception) {

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);

        } catch (Throwable $exception) {

            Log::error('Variance generation failed', [
                'previous_import_id' => $previousId,
                'current_import_id' => $currentId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat variance report.',
            ], 500);
        }
    }

    /**
     * List generated variance reports.
     */
    public function index(): JsonResponse
    {
        $reports = VarianceReport::query()
            ->with(['previousImport', 'currentImport'])
            ->latest('generated_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $reports,
        ]);
    }

    /**
     * Show variance report details.
     */
    public function show(int $id): JsonResponse
    {
        $report = VarianceReport::with([
            'previousImport',
            'currentImport',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }
}
