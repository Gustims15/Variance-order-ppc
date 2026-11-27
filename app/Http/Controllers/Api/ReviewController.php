<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReviewItem;
use App\Models\ReviewItemHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report_id' => [
                'nullable',
                'integer',
                'exists:variance_reports,id',
            ],
            'status' => [
                'nullable',
                Rule::in(['pending', 'resolved']),
            ],
            'department' => [
                'nullable',
                'string',
                'max:50',
            ],
            'keyword' => [
                'nullable',
                'string',
                'max:100',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $query = ReviewItem::query()->with([
            'varianceDetail',
            'varianceReport',
        ]);

        if (!empty($validated['report_id'])) {
            $query->where(
                'variance_report_id',
                $validated['report_id']
            );
        }

        if (!empty($validated['status'])) {
            $query->where(
                'status',
                $validated['status']
            );
        }

        if (!empty($validated['department'])) {
            $query->whereHas(
                'varianceDetail',
                function ($q) use ($validated) {
                    $q->where(
                        'department',
                        $validated['department']
                    );
                }
            );
        }

        if (!empty($validated['keyword'])) {
            $keyword = trim($validated['keyword']);

            if ($keyword !== '') {
                $query->where(function ($search) use ($keyword) {

                    $search->where(
                        'reason',
                        'like',
                        "%{$keyword}%"
                    );

                    $search->orWhereHas(
                        'varianceDetail',
                        function ($detail) use ($keyword) {

                            $detail->where(function ($q) use ($keyword) {

                                $q->where(
                                    'sr_number',
                                    'like',
                                    "%{$keyword}%"
                                )
                                ->orWhere(
                                    'product_code',
                                    'like',
                                    "%{$keyword}%"
                                )
                                ->orWhere(
                                    'product_name',
                                    'like',
                                    "%{$keyword}%"
                                )
                                ->orWhere(
                                    'department',
                                    'like',
                                    "%{$keyword}%"
                                );
                            });
                        }
                    );
                });
            }
        }

        $reviews = $query
            ->latest('id')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $reviews,
        ]);
    }

public function statistics(Request $request): JsonResponse
{
    $validated = $request->validate([
        'report_id' => [
            'nullable',
            'integer',
            'exists:variance_reports,id',
        ],
    ]);

    $baseQuery = DB::table('review_items as ri');

    if (!empty($validated['report_id'])) {
        $baseQuery->where(
            'ri.variance_report_id',
            $validated['report_id']
        );
    }

    $summary = (clone $baseQuery)
        ->selectRaw('COUNT(*) as total_reviews')
        ->selectRaw("
            SUM(CASE WHEN ri.status = 'pending'
                THEN 1 ELSE 0 END) as pending
        ")
        ->selectRaw("
            SUM(CASE WHEN ri.status = 'resolved'
                THEN 1 ELSE 0 END) as resolved
        ")
        ->first();

    $total = (int) ($summary->total_reviews ?? 0);
    $pending = (int) ($summary->pending ?? 0);
    $resolved = (int) ($summary->resolved ?? 0);

    $resolutionRate = $total > 0
        ? round(($resolved / $total) * 100, 2)
        : 0;

    $reasonDistribution = (clone $baseQuery)
        ->select('ri.reason')
        ->selectRaw('COUNT(*) as total')
        ->groupBy('ri.reason')
        ->orderByDesc('total')
        ->get()
        ->map(fn ($item) => [
            'reason' => $item->reason,
            'total' => (int) $item->total,
        ])
        ->values();

    $departmentStatistics = (clone $baseQuery)
        ->leftJoin(
            'variance_details as vd',
            'vd.id',
            '=',
            'ri.variance_detail_id'
        )
        ->selectRaw("
            COALESCE(vd.department, 'UNKNOWN') as department
        ")
        ->selectRaw('COUNT(*) as total_reviews')
        ->selectRaw("
            SUM(CASE WHEN ri.status = 'pending'
                THEN 1 ELSE 0 END) as pending
        ")
        ->selectRaw("
            SUM(CASE WHEN ri.status = 'resolved'
                THEN 1 ELSE 0 END) as resolved
        ")
        ->groupByRaw("COALESCE(vd.department, 'UNKNOWN')")
        ->orderBy('department')
        ->get()
        ->map(fn ($item) => [
            'department' => $item->department,
            'total_reviews' => (int) $item->total_reviews,
            'pending' => (int) $item->pending,
            'resolved' => (int) $item->resolved,
        ])
        ->values();

    return response()->json([
        'success' => true,
        'data' => [
            'report_id' => $validated['report_id'] ?? null,

            'summary' => [
                'total_reviews' => $total,
                'pending' => $pending,
                'resolved' => $resolved,
                'resolution_rate' => $resolutionRate,
            ],

            'reason_distribution' => $reasonDistribution,

            'department_statistics' => $departmentStatistics,
        ],
    ]);
}

    public function show(int $id): JsonResponse
    {
        $review = ReviewItem::with([
            'varianceDetail.periods',
            'varianceReport.previousImport',
            'varianceReport.currentImport',
        ])->findOrFail($id);

        $detail = $review->varianceDetail;
        $report = $review->varianceReport;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $review->id,
                'variance_report_id' => $review->variance_report_id,
                'reason' => $review->reason,
                'status' => $review->status,
                'resolution_note' => $review->resolution_note,
                'reviewed_by' => $review->reviewed_by,
                'reviewed_at' => $review->reviewed_at,

                'product' => $detail ? [
                    'id' => $detail->id,
                    'department' => $detail->department,
                    'sr_number' => $detail->sr_number,
                    'product_code' => $detail->product_code,
                    'product_name' => $detail->product_name,
                    'previous_line_number' => $detail->previous_line_number,
                    'current_line_number' => $detail->current_line_number,
                    'previous_source_row' => $detail->previous_source_row,
                    'current_source_row' => $detail->current_source_row,
                    'previous_total' => $detail->previous_total,
                    'current_total' => $detail->current_total,
                    'total_variance' => $detail->total_variance,
                    'status' => $detail->status,

                    'periods' => $detail->periods
                        ->map(fn ($period) => [
                            'date' => $period->sr_issue_date,
                            'forecast' => $period->forecast_quantity,
                            'actual' => $period->latest_quantity,
                            'variance' => $period->variance,
                            'status' => $period->status,
                        ])
                        ->values(),
                ] : null,

                'report' => $report ? [
                    'id' => $report->id,
                    'status' => $report->status,
                    'generated_at' => $report->generated_at,

                    'forecast' => [
                        'id' => $report->previousImport?->id,
                        'filename' => $report->previousImport?->original_filename,
                    ],

                    'actual' => [
                        'id' => $report->currentImport?->id,
                        'filename' => $report->currentImport?->original_filename,
                    ],
                ] : null,

                'created_at' => $review->created_at,
                'updated_at' => $review->updated_at,
            ],
        ]);
    }

    public function resolve(
        Request $request,
        int $id
    ): JsonResponse {

        $validated = $request->validate([
            'resolution_note' => [
                'required',
                'string',
                'min:3',
                'max:10000',
            ],
        ]);

        $review = DB::transaction(function () use (
            $id,
            $validated
        ) {

            $review = ReviewItem::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($review->status !== 'pending') {
                return null;
            }

            $previousStatus = $review->status;
            $now = now();

            $review->update([
                'status' => 'resolved',
                'resolution_note' => $validated['resolution_note'],
                'reviewed_by' => auth()->id(),
                'reviewed_at' => $now,
            ]);

            ReviewItemHistory::create([
                'review_item_id' => $review->id,
                'action' => 'RESOLVE',
                'from_status' => $previousStatus,
                'to_status' => 'resolved',
                'note' => $validated['resolution_note'],
                'performed_by' => auth()->id(),
                'performed_at' => $now,
            ]);

            return $review->fresh([
                'varianceDetail',
                'varianceReport',
            ]);

        }, 3);

        if (!$review) {
            return response()->json([
                'success' => false,
                'message' => 'Review tidak berstatus pending.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Review berhasil diselesaikan.',
            'data' => $review,
        ]);
    }

    public function reopen(int $id): JsonResponse
    {
        $review = DB::transaction(function () use ($id) {

            $review = ReviewItem::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($review->status !== 'resolved') {
                return null;
            }

            $previousStatus = $review->status;
            $now = now();

            $review->update([
                'status' => 'pending',
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);

            ReviewItemHistory::create([
                'review_item_id' => $review->id,
                'action' => 'REOPEN',
                'from_status' => $previousStatus,
                'to_status' => 'pending',
                'note' => 'Review dibuka kembali. Catatan penyelesaian sebelumnya dipertahankan.',
                'performed_by' => auth()->id(),
                'performed_at' => $now,
            ]);

            return $review->fresh([
                'varianceDetail',
                'varianceReport',
            ]);

        }, 3);

        if (!$review) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya review resolved yang dapat dibuka kembali.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Review berhasil dibuka kembali.',
            'data' => $review,
        ]);
    }

    public function history(int $id): JsonResponse
    {
        $review = ReviewItem::findOrFail($id);

        $histories = $review->histories()
            ->with('performer:id,name')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $histories,
        ]);
    }


    public function bulkResolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:review_items,id',
            ],
            'resolution_note' => [
                'required',
                'string',
                'min:3',
                'max:10000',
            ],
        ]);

        $result = DB::transaction(function () use ($validated) {

            $reviews = ReviewItem::query()
                ->whereIn('id', $validated['ids'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $invalid = $reviews->where('status', '!=', 'pending');

            if ($invalid->isNotEmpty()) {
                return [
                    'success' => false,
                    'invalid_ids' => $invalid->pluck('id')->values(),
                ];
            }

            $now = now();
            $userId = auth()->id();

            foreach ($reviews as $review) {
                $previousStatus = $review->status;

                $review->update([
                    'status' => 'resolved',
                    'resolution_note' => $validated['resolution_note'],
                    'reviewed_by' => $userId,
                    'reviewed_at' => $now,
                ]);

                ReviewItemHistory::create([
                    'review_item_id' => $review->id,
                    'action' => 'RESOLVE',
                    'from_status' => $previousStatus,
                    'to_status' => 'resolved',
                    'note' => $validated['resolution_note'],
                    'performed_by' => $userId,
                    'performed_at' => $now,
                ]);
            }

            return [
                'success' => true,
                'processed' => $reviews->count(),
                'ids' => $reviews->pluck('id')->values(),
            ];

        }, 3);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Bulk Resolve dibatalkan. Beberapa review bukan berstatus pending.',
                'invalid_ids' => $result['invalid_ids'],
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Bulk Resolve berhasil.',
            'data' => $result,
        ]);
    }

    public function bulkReopen(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:review_items,id',
            ],
        ]);

        $result = DB::transaction(function () use ($validated) {

            $reviews = ReviewItem::query()
                ->whereIn('id', $validated['ids'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $invalid = $reviews->where('status', '!=', 'resolved');

            if ($invalid->isNotEmpty()) {
                return [
                    'success' => false,
                    'invalid_ids' => $invalid->pluck('id')->values(),
                ];
            }

            $now = now();
            $userId = auth()->id();

            foreach ($reviews as $review) {
                $previousStatus = $review->status;

                $review->update([
                    'status' => 'pending',
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                ]);

                ReviewItemHistory::create([
                    'review_item_id' => $review->id,
                    'action' => 'REOPEN',
                    'from_status' => $previousStatus,
                    'to_status' => 'pending',
                    'note' => 'Review dibuka kembali melalui Bulk Reopen. Catatan penyelesaian sebelumnya dipertahankan.',
                    'performed_by' => $userId,
                    'performed_at' => $now,
                ]);
            }

            return [
                'success' => true,
                'processed' => $reviews->count(),
                'ids' => $reviews->pluck('id')->values(),
            ];

        }, 3);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Bulk Reopen dibatalkan. Beberapa review bukan berstatus resolved.',
                'invalid_ids' => $result['invalid_ids'],
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Bulk Reopen berhasil.',
            'data' => $result,
        ]);
    }

}
