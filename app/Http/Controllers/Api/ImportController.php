<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WeeklyImport;
use App\Services\Imports\ImportPersistenceService;
use App\Services\Imports\WeeklyVarianceGenerator;
use App\Services\Imports\PemiIsuzuReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ImportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = WeeklyImport::query()
            ->latest('snapshot_date')
            ->latest('uploaded_at');

        if ($request->filled('snapshot_date')) {
            $query->whereDate('snapshot_date', $request->input('snapshot_date'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate(20),
        ]);
    }

            public function preview(
            Request $request,
            PemiIsuzuReader $reader
        ): JsonResponse {
            $validated = $request->validate([
                'file' => ['required', 'file', 'max:51200', 'mimes:xls,xlsx,xlsm'],
                'snapshot_date' => ['required', 'date_format:Y-m-d'],
            ]);

            $file = $validated['file'];

            try {
                $periods = $reader->previewPeriods(
                    $file->getPathname(),
                    $validated['snapshot_date']
                );
            } catch (RuntimeException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'filename' => $file->getClientOriginalName(),
                    'snapshot_date' => $validated['snapshot_date'],
                    'periods' => $periods,
                ],
            ]);
        }

    public function store(
        Request $request,
        ImportPersistenceService $service,
        WeeklyVarianceGenerator $varianceGenerator
    ): JsonResponse {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:xls,xlsx,xlsm'],
            'snapshot_date' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $import = $service->import(
                $validated['file'],
                'weekly',
                $validated['snapshot_date']
            );

            $varianceReport = null;
            $varianceWarning = null;

            try {
                $varianceReport = $varianceGenerator->generateForImport($import);
            } catch (Throwable $varianceException) {
                $varianceWarning = $varianceException->getMessage();

                Log::error('Automatic weekly variance generation failed', [
                    'weekly_import_id' => $import->id,
                    'error' => $varianceException->getMessage(),
                    'trace' => $varianceException->getTraceAsString(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Weekly snapshot berhasil diproses.',
                'data' => [
                    'id' => $import->id,
                    'filename' => $import->original_filename,
                    'import_type' => $import->import_type,
                    'snapshot_date' => $import->snapshot_date?->format('Y-m-d'),
                    'snapshot_week' => $import->snapshot_week,
                    'status' => $import->status,
                    'total_worksheets' => $import->total_worksheets,
                    'total_products' => $import->total_products,
                    'total_periods' => $import->total_periods,
                    'uploaded_at' => $import->uploaded_at,
                    'processed_at' => $import->processed_at,
                    'variance_report' => $varianceReport ? [
                        'id' => $varianceReport->id,
                        'status' => $varianceReport->status,
                        'total_products' => $varianceReport->total_products,
                        'new_order' => $varianceReport->new_order_count,
                        'increased' => $varianceReport->increased_count,
                        'decreased' => $varianceReport->decreased_count,
                        'mixed' => $varianceReport->mixed_count,
                        'unchanged' => $varianceReport->unchanged_count,
                        'retained' => $varianceReport->retained_count,
                        'needs_review' => $varianceReport->needs_review_count,
                        'total_changes' => $varianceReport->total_changes,
                    ] : null,
                    'variance_warning' => $varianceWarning,
                ],
            ], 201);

        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);

        } catch (Throwable $exception) {
            Log::error('Weekly snapshot import failed', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Import gagal diproses. Periksa log aplikasi.',
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        $import = WeeklyImport::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $import,
        ]);
    }
}
