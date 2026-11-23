<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VarianceReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Main dashboard summary.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report_id' => [
                'nullable',
                'integer',
                'exists:variance_reports,id',
            ],
        ]);

        $report = isset($validated['report_id'])
            ? VarianceReport::with([
                'previousImport',
                'currentImport',
            ])->findOrFail($validated['report_id'])
            : VarianceReport::with([
                'previousImport',
                'currentImport',
            ])->latest('generated_at')->first();

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Belum ada variance report.',
            ], 404);
        }

        $departmentSummary = DB::table('variance_details')
            ->where('variance_report_id', $report->id)
            ->select('department')
            ->selectRaw('COUNT(*) AS total_products')
            ->selectRaw(
                "SUM(CASE WHEN status = 'NEW_ORDER' THEN 1 ELSE 0 END) AS new_order"
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'INCREASED' THEN 1 ELSE 0 END) AS increased"
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'DECREASED' THEN 1 ELSE 0 END) AS decreased"
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'MIXED' THEN 1 ELSE 0 END) AS mixed"
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'UNCHANGED' THEN 1 ELSE 0 END) AS unchanged"
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'NEEDS_REVIEW' THEN 1 ELSE 0 END) AS needs_review"
            )
            ->groupBy('department')
            ->orderBy('department')
            ->get();

        $periodRange = DB::table('variance_period_details as p')
            ->join(
                'variance_details as d',
                'd.id',
                '=',
                'p.variance_detail_id'
            )
            ->where('d.variance_report_id', $report->id)
            ->selectRaw('MIN(p.sr_issue_date) AS first_date')
            ->selectRaw('MAX(p.sr_issue_date) AS last_date')
            ->selectRaw('COUNT(DISTINCT p.sr_issue_date) AS total_periods')
            ->first();

        $reviewCount = DB::table('review_items')
            ->where('variance_report_id', $report->id)
            ->where('status', 'pending')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'report' => [
                    'id' => $report->id,
                    'status' => $report->status,
                    'generated_at' => $report->generated_at,
                    'forecast' => [
                        'id' => $report->previousImport->id,
                        'filename' => $report->previousImport->original_filename,
                    ],
                    'actual' => [
                        'id' => $report->currentImport->id,
                        'filename' => $report->currentImport->original_filename,
                    ],
                ],

                'summary' => [
                    'total_products' => $report->total_products,
                    'new_order' => $report->new_order_count,
                    'increased' => $report->increased_count,
                    'decreased' => $report->decreased_count,
                    'mixed' => $report->mixed_count,
                    'unchanged' => $report->unchanged_count,
                    'needs_review' => $report->needs_review_count,
                    'total_changes' => $report->total_changes,
                    'pending_reviews' => $reviewCount,
                ],

                'departments' => $departmentSummary,

                'periods' => [
                    'first_date' => $periodRange->first_date ?? null,
                    'last_date' => $periodRange->last_date ?? null,
                    'total_periods' => (int) (
                        $periodRange->total_periods ?? 0
                    ),
                ],
            ],
        ]);
    }

    /**
     * Forecast vs Actual period trend.
     */
    public function trends(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report_id' => [
                'required',
                'integer',
                'exists:variance_reports,id',
            ],
            'department' => [
                'nullable',
                'string',
                'max:50',
            ],
        ]);

        $query = DB::table('variance_period_details as p')
            ->join(
                'variance_details as d',
                'd.id',
                '=',
                'p.variance_detail_id'
            )
            ->where(
                'd.variance_report_id',
                $validated['report_id']
            );

        if (!empty($validated['department'])) {
            $query->where(
                'd.department',
                $validated['department']
            );
        }

        $trends = $query
            ->select('p.sr_issue_date')
            ->selectRaw(
                'SUM(p.forecast_quantity) AS forecast_quantity'
            )
            ->selectRaw(
                'SUM(p.latest_quantity) AS actual_quantity'
            )
            ->selectRaw(
                'SUM(p.variance) AS total_variance'
            )
            ->groupBy('p.sr_issue_date')
            ->orderBy('p.sr_issue_date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $trends,
        ]);
    }
}
