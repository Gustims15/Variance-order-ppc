<?php

namespace App\Services\Imports;

use App\Models\WeeklyImport;
use App\Models\VarianceReport;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VariancePersistenceService
{
    // 12 inserted columns x 150 rows = 1800 parameters (SQL Server limit: 2100).
    private const PERIOD_BATCH_SIZE = 150;
    public function save(
        int $previousImportId,
        int $currentImportId,
        array $result
    ): VarianceReport {

        // Validate imports before starting transaction.
        $previous = WeeklyImport::findOrFail(
            $previousImportId
        );

        $current = WeeklyImport::findOrFail(
            $currentImportId
        );

        if ($previousImportId === $currentImportId) {
            throw new RuntimeException(
                'Snapshot tidak boleh sama.'
            );
        }

        if (
            $previous->status !== 'completed' ||
            $current->status !== 'completed'
        ) {
            throw new RuntimeException(
                'Kedua import harus completed.'
            );
        }

        if (
            $previous->import_type !== 'weekly' ||
            $current->import_type !== 'weekly'
        ) {
            throw new RuntimeException(
                'Perbandingan harus menggunakan dua Weekly Snapshot.'
            );
        }

        if (
            !$previous->snapshot_date ||
            !$current->snapshot_date ||
            $previous->snapshot_date->greaterThanOrEqualTo($current->snapshot_date)
        ) {
            throw new RuntimeException(
                'Tanggal snapshot sebelumnya harus lebih awal dari snapshot terbaru.'
            );
        }

        $details = $result['details'] ?? null;
        $engineSummary = $result['summary'] ?? null;

        if (!is_array($details) || !is_array($engineSummary)) {
            throw new RuntimeException(
                'Struktur hasil Rolling Variance tidak valid.'
            );
        }

        $requiredSummaryKeys = [
            'new_order',
            'increased',
            'decreased',
            'mixed',
            'unchanged',
            'needs_review',
            'total_changes',
        ];

        foreach ($requiredSummaryKeys as $key) {
            if (
                !isset($engineSummary[$key]) ||
                !is_numeric($engineSummary[$key]) ||
                (int) $engineSummary[$key] < 0
            ) {
                throw new RuntimeException(
                    "Summary engine tidak valid: {$key}"
                );
            }
        }

        $engineSummary['retained'] = $engineSummary['retained'] ?? 0;
        if (!is_numeric($engineSummary['retained']) || (int) $engineSummary['retained'] < 0) {
            throw new RuntimeException('Summary engine tidak valid: retained');
        }

        $summaryMap = [
            'NEW_ORDER' => 'new_order',
            'INCREASED' => 'increased',
            'DECREASED' => 'decreased',
            'MIXED' => 'mixed',
            'UNCHANGED' => 'unchanged',
            'RETAIN' => 'retained',
            'NEEDS_REVIEW' => 'needs_review',
        ];

        $databaseSummary = [
            'new_order_count' => (int) $engineSummary['new_order'],
            'increased_count' => (int) $engineSummary['increased'],
            'decreased_count' => (int) $engineSummary['decreased'],
            'mixed_count' => (int) $engineSummary['mixed'],
            'unchanged_count' => (int) $engineSummary['unchanged'],
            'retained_count' => (int) $engineSummary['retained'],
            'needs_review_count' => (int) $engineSummary['needs_review'],
        ];

        $expectedTotalChanges =
            (int) $engineSummary['new_order'] +
            (int) $engineSummary['increased'] +
            (int) $engineSummary['decreased'] +
            (int) $engineSummary['mixed'];

        if (
            (int) $engineSummary['total_changes']
            !== $expectedTotalChanges
        ) {
            throw new RuntimeException(
                'Total changes pada summary engine tidak konsisten.'
            );
        }

        $detailCounts = array_fill_keys(
            array_keys($summaryMap),
            0
        );

        foreach ($details as $detail) {

            $status = strtoupper(
                $detail['status'] ?? ''
            );

            if (!isset($summaryMap[$status])) {
                throw new RuntimeException(
                    "Status detail tidak dikenal: {$status}"
                );
            }

            $detailCounts[$status]++;
        }

        foreach ($summaryMap as $status => $engineKey) {

            if (
                $detailCounts[$status]
                !== (int) $engineSummary[$engineKey]
            ) {
                throw new RuntimeException(
                    "Jumlah {$status} tidak cocok dengan summary engine."
                );
            }
        }

        if (count($details) !== array_sum($detailCounts)) {
            throw new RuntimeException(
                'Jumlah detail produk tidak konsisten.'
            );
        }

        return DB::transaction(function () use (
            $previousImportId,
            $currentImportId,
            $details,
            $databaseSummary,
            $expectedTotalChanges
        ) {

            // Serialize revisions for this pair by locking the current import row.
            WeeklyImport::query()->whereKey($currentImportId)->lockForUpdate()->firstOrFail();

            $latestRevision = DB::table('variance_reports')
                ->where('previous_import_id', $previousImportId)
                ->where('current_import_id', $currentImportId)
                ->orderByDesc('revision_number')
                ->lockForUpdate()
                ->first();

            $revisionNumber = $latestRevision
                ? ((int) $latestRevision->revision_number + 1)
                : 1;
            $parentReportId = $latestRevision->id ?? null;

            if ($latestRevision) {
                DB::table('variance_reports')
                    ->where('previous_import_id', $previousImportId)
                    ->where('current_import_id', $currentImportId)
                    ->update(['is_latest' => false, 'updated_at' => now()]);
            }

            $now = now();

            $reportId = DB::table('variance_reports')
                ->insertGetId([
                    'previous_import_id' => $previousImportId,
                    'current_import_id' => $currentImportId,
                    'revision_number' => $revisionNumber,
                    'parent_report_id' => $parentReportId,
                    'is_latest' => true,
                    'status' => 'processing',
                    'total_products' => count($details),

                    'new_order_count' => 0,
                    'increased_count' => 0,
                    'decreased_count' => 0,
                    'mixed_count' => 0,
                    'unchanged_count' => 0,
                    'retained_count' => 0,
                    'needs_review_count' => 0,
                    'total_changes' => 0,

                    'generated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            $periodBuffer = [];

            foreach ($details as $detail) {

                $status = strtoupper($detail['status']);

                $reason = $detail['reason'] ?? null;

                $lineNumber = $detail['line_number'] ?? null;
                $sourceRow = $detail['source_row'] ?? null;

                $previousLine =
                    $detail['previous_line_number'] ?? null;

                $currentLine =
                    $detail['current_line_number'] ?? null;

                $previousSource =
                    $detail['previous_source_row'] ?? null;

                $currentSource =
                    $detail['current_source_row'] ?? null;

                if ($status === 'NEW_ORDER') {

                    $currentLine ??= $lineNumber;
                    $currentSource ??= $sourceRow;

                } elseif ($reason === 'PRODUCT_MISSING') {

                    $previousLine ??= $lineNumber;
                    $previousSource ??= $sourceRow;

                } elseif ($status !== 'NEEDS_REVIEW') {

                    $previousLine ??= $lineNumber;
                    $currentLine ??= $lineNumber;

                    $previousSource ??= $sourceRow;
                    $currentSource ??= $sourceRow;
                }

                // Collect audit metadata from the comparison engine.
                $auditMetadataKeys = [
                    'duplicate_count',
                    'duplicate_source_rows',
                    'previous_duplicate_count',
                    'current_duplicate_count',
                    'previous_source_rows',
                    'current_source_rows',
                    'previous_line_numbers',
                    'current_line_numbers',
                    'duplicate_line_numbers',
                    'previous_attributes',
                    'current_attributes',
                    'previous_attribute_sets',
                    'current_attribute_sets',
                    'previous_duplicate_attribute_sets',
                    'current_duplicate_attribute_sets',
                ];

                $auditMetadata = array_intersect_key(
                    $detail,
                    array_flip($auditMetadataKeys)
                );

                $auditMetadataJson = !empty($auditMetadata)
                    ? json_encode(
                        $auditMetadata,
                        JSON_THROW_ON_ERROR
                            | JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                    )
                    : null;

                $varianceDetailId = DB::table('variance_details')
                    ->insertGetId([
                        'variance_report_id' => $reportId,

                        'department' => $detail['department'] ?? 'UNKNOWN',
                        'sr_number' => $detail['sr_number'] ?? '',
                        'product_code' => $detail['product_code'] ?? '',

                        'previous_line_number' => $previousLine,
                        'current_line_number' => $currentLine,

                        'product_name' => $detail['product_name'] ?? null,

                        'previous_source_row' => $previousSource,
                        'current_source_row' => $currentSource,

                        'previous_total' => $detail['previous_total'] ?? null,
                        'current_total' => $detail['current_total'] ?? null,
                        'total_variance' => $detail['total_variance'] ?? null,

                        'status' => $status,
                        'reason' => $reason,
                        'audit_metadata' => $auditMetadataJson,

                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                $periods = $detail['periods'] ?? [];

                foreach ($periods as $date => $period) {

                    if (!is_array($period)) {
                        throw new RuntimeException(
                            "Format periode tidak valid: {$date}"
                        );
                    }

                    $date = (string) $date;

                    $parsedDate = \DateTimeImmutable::createFromFormat(
                        '!Y-m-d',
                        $date
                    );

                    if (
                        !$parsedDate ||
                        $parsedDate->format('Y-m-d') !== $date
                    ) {
                        throw new RuntimeException(
                            "Tanggal periode tidak valid: {$date}"
                        );
                    }

                    $forecast = $period['forecast'] ?? null;
                    $latest = $period['latest'] ?? null;
                    $variance = $period['variance'] ?? null;

                    foreach ([
                        'forecast' => $forecast,
                        'latest' => $latest,
                        'variance' => $variance,
                    ] as $field => $value) {

                        if (
                            $value !== null &&
                            (!is_numeric($value) ||
                            !is_finite((float) $value))
                        ) {
                            throw new RuntimeException(
                                "Quantity {$field} tidak valid pada {$date}."
                            );
                        }
                    }

                    $previousCum = $period['previous_cumulative_quantity'] ?? null;
                    $currentCum = $period['current_cumulative_quantity'] ?? null;
                    $cumulativeVariance = $period['cumulative_variance'] ?? null;

                    foreach ([
                        'previous_cumulative_quantity' => $previousCum,
                        'current_cumulative_quantity' => $currentCum,
                        'cumulative_variance' => $cumulativeVariance,
                    ] as $field => $value) {
                        if (
                            $value !== null &&
                            (!is_numeric($value) || !is_finite((float) $value))
                        ) {
                            throw new RuntimeException(
                                "Nilai {$field} tidak valid pada {$date}."
                            );
                        }
                    }

                    $periodBuffer[] = [
                        'variance_detail_id' => $varianceDetailId,
                        'sr_issue_date' => $date,
                        'forecast_quantity' => $forecast,
                        'latest_quantity' => $latest,
                        'variance' => $variance,
                        'previous_cumulative_quantity' => $previousCum,
                        'current_cumulative_quantity' => $currentCum,
                        'cumulative_variance' => $cumulativeVariance,
                        'cum_status' => $period['cum_status'] ?? 'NOT_CHECKED',
                        'status' => $period['status'] ?? 'NEEDS_REVIEW',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (
                        count($periodBuffer)
                        >= self::PERIOD_BATCH_SIZE
                    ) {

                        DB::table('variance_period_details')
                            ->insert($periodBuffer);

                        $periodBuffer = [];
                    }
                }

                if ($status === 'NEEDS_REVIEW') {

                    DB::table('review_items')->insert([
                        'variance_report_id' => $reportId,
                        'variance_detail_id' => $varianceDetailId,
                        'reason' => $reason ?? 'NEEDS_REVIEW',
                        'status' => 'pending',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            if (!empty($periodBuffer)) {

                DB::table('variance_period_details')
                    ->insert($periodBuffer);

                $periodBuffer = [];
            }

            DB::table('variance_reports')
                ->where('id', $reportId)
                ->update([
                    ...$databaseSummary,

                    'total_changes' => $expectedTotalChanges,

                    'status' => 'completed',
                    'generated_at' => now(),
                    'updated_at' => now(),
                ]);

            return VarianceReport::findOrFail($reportId);

        }, 3);
    }
}
