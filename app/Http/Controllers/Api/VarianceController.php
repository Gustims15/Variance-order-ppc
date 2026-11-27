<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VarianceReport;
use App\Services\Imports\WeeklyVarianceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class VarianceController extends Controller
{
    public function store(
        Request $request,
        WeeklyVarianceGenerator $generator
    ): JsonResponse {
        $validated = $request->validate([
            'previous_import_id' => ['required', 'integer', 'exists:weekly_imports,id'],
            'current_import_id' => ['required', 'integer', 'exists:weekly_imports,id'],
        ]);

        try {
            $report = $generator->generatePair(
                (int) $validated['previous_import_id'],
                (int) $validated['current_import_id']
            );

            return response()->json([
                'success' => true,
                'message' => 'Weekly variance report berhasil dibuat.',
                'data' => $this->reportSummary($report),
            ], 201);

        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);

        } catch (QueryException $exception) {
            Log::error('Weekly variance database operation failed', [
                'previous_import_id' => $validated['previous_import_id'],
                'current_import_id' => $validated['current_import_id'],
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan weekly variance report.',
            ], 500);

        } catch (Throwable $exception) {
            Log::error('Weekly variance generation failed', [
                'previous_import_id' => $validated['previous_import_id'],
                'current_import_id' => $validated['current_import_id'],
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat weekly variance report.',
            ], 500);
        }
    }

    public function index(): JsonResponse
    {
        $reports = VarianceReport::query()
            ->where('is_latest', true)
            ->with(['previousImport', 'currentImport'])
            ->latest('generated_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $reports,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $report = VarianceReport::query()
            ->with([
                'previousImport',
                'currentImport',
                'details.periods',
                'details.reviewItems',
                'revisions',
            ])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    private function reportSummary(VarianceReport $report): array
    {
        return [
            'report_id' => $report->id,
            'previous_import_id' => $report->previous_import_id,
            'current_import_id' => $report->current_import_id,
            'revision_number' => $report->revision_number,
            'is_latest' => $report->is_latest,
            'status' => $report->status,
            'summary' => [
                'total_products' => $report->total_products,
                'new_order' => $report->new_order_count,
                'increased' => $report->increased_count,
                'decreased' => $report->decreased_count,
                'mixed' => $report->mixed_count,
                'unchanged' => $report->unchanged_count,
                'retained' => $report->retained_count,
                'needs_review' => $report->needs_review_count,
                'total_changes' => $report->total_changes,
            ],
        ];
    }
}
