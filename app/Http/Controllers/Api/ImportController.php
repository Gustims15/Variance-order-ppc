<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WeeklyImport;
use App\Services\Imports\ImportPersistenceService;
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
            ->latest('uploaded_at');

        if ($request->filled('import_type')) {
            $query->where(
                'import_type',
                strtolower($request->input('import_type'))
            );
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate(20),
        ]);
    }

    public function store(
        Request $request,
        ImportPersistenceService $service
    ): JsonResponse {

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:51200',
                'mimes:xls,xlsx,xlsm',
            ],
            'import_type' => [
                'required',
                'in:forecast,actual',
            ],
        ]);

        try {
            $import = $service->import(
                $validated['file'],
                $validated['import_type']
            );

            return response()->json([
                'success' => true,
                'message' => 'Import berhasil diproses.',
                'data' => [
                    'id' => $import->id,
                    'filename' => $import->original_filename,
                    'import_type' => $import->import_type,
                    'status' => $import->status,
                    'total_worksheets' => $import->total_worksheets,
                    'total_products' => $import->total_products,
                    'total_periods' => $import->total_periods,
                    'uploaded_at' => $import->uploaded_at,
                    'processed_at' => $import->processed_at,
                ],
            ], 201);

        } catch (RuntimeException $exception) {

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);

        } catch (Throwable $exception) {

            Log::error('Excel import failed', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Import gagal diproses.',
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
