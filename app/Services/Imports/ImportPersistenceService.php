<?php

namespace App\Services\Imports;

use App\Models\ImportedOrder;
use App\Models\WeeklyImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ImportPersistenceService
{
    private const PERIOD_BATCH_SIZE = 200;
    private const VALIDATION_BATCH_SIZE = 150;

    public function __construct(
        private readonly PemiIsuzuReader $reader
    ) {}

    public function import(
        string|UploadedFile $file,
        string $importType,
        string $snapshotDate
    ): WeeklyImport {

        $importType = strtolower(trim($importType));

        if ($importType !== 'weekly') {
            throw new RuntimeException(
                'Jenis import harus weekly.'
            );
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $snapshotDate
        );

        if (
            !$date ||
            $date->format('Y-m-d') !== $snapshotDate
        ) {
            throw new RuntimeException(
                'Tanggal snapshot tidak valid.'
            );
        }

        $filePath = $file instanceof UploadedFile
            ? $file->getRealPath()
            : $file;

        if (!$filePath || !is_file($filePath)) {
            throw new RuntimeException(
                'File Excel tidak ditemukan.'
            );
        }

        // UploadedFile::getRealPath() menunjuk ke file sementara PHP
        // (contoh: C:\\Windows\\Temp\\phpXXXX.tmp), bukan nama file asli.
        // Karena itu, ambil ekstensi dari nama file asli saat upload.
        $extension = $file instanceof UploadedFile
            ? strtolower($file->getClientOriginalExtension())
            : strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (!in_array($extension, ['xls', 'xlsx', 'xlsm'], true)) {
            throw new RuntimeException(
                'Format file harus XLS, XLSX, atau XLSM.'
            );
        }

        $fileHash = hash_file('sha256', $filePath);

        if (!$fileHash) {
            throw new RuntimeException(
                'Gagal menghitung SHA-256 file.'
            );
        }

        if (WeeklyImport::where('file_hash', $fileHash)->exists()) {
            throw new RuntimeException(
                'File ini sudah pernah di-upload.'
            );
        }

        if (
            WeeklyImport::whereDate(
                'snapshot_date',
                $snapshotDate
            )
            ->where('status', 'completed')
            ->exists()
        ) {
            throw new RuntimeException(
                'Snapshot untuk tanggal ini sudah tersedia. '
                . 'Gunakan mekanisme revisi untuk memperbaiki data.'
            );
        }

        $snapshot = $this->reader->read($filePath, $snapshotDate);

        $this->validateSnapshot($snapshot);

        $originalFilename = $file instanceof UploadedFile
            ? $file->getClientOriginalName()
            : basename($filePath);

        $storedFilename = $fileHash . '.' . $extension;

        $storedPath = 'private/variance-order/imports/'
            . $storedFilename;

        $stored = Storage::disk('local')->putFileAs(
            'private/variance-order/imports',
            new \Illuminate\Http\File($filePath),
            $storedFilename
        );

        if (!$stored) {
            throw new RuntimeException(
                'Gagal menyimpan file sumber.'
            );
        }

        try {
            return DB::transaction(function () use (
                $snapshot,
                $fileHash,
                $extension,
                $originalFilename,
                $storedPath,
                $snapshotDate
            ) {

                if (
                    WeeklyImport::where('file_hash', $fileHash)
                        ->lockForUpdate()
                        ->exists()
                ) {
                    throw new RuntimeException(
                        'File ini sudah pernah di-upload.'
                    );
                }

                if (
                    WeeklyImport::whereDate(
                        'snapshot_date',
                        $snapshotDate
                    )
                    ->where('status', 'completed')
                    ->lockForUpdate()
                    ->exists()
                ) {
                    throw new RuntimeException(
                        'Snapshot tanggal ini sudah tersedia.'
                    );
                }

                $date = new \DateTimeImmutable($snapshotDate);

                $snapshotWeek = sprintf(
                    '%d-W%02d',
                    (int) $date->format('o'),
                    (int) $date->format('W')
                );

                $import = WeeklyImport::create([
                    'original_filename' => $originalFilename,
                    'file_hash' => $fileHash,
                    'import_type' => 'weekly',
                    'file_type' => $extension,
                    'status' => 'processing',

                    'snapshot_date' => $snapshotDate,
                    'snapshot_week' => $snapshotWeek,

                    'total_worksheets' => count(
                        $snapshot['worksheets']
                    ),

                    'total_products' => $snapshot['total_products'],
                    'total_periods' => $snapshot['total_periods'],

                    'uploaded_at' => now(),

                    'metadata' => [
                        'storage_path' => $storedPath,
                        'reader' => 'PemiIsuzuReader',
                        'hash_algorithm' => 'SHA-256',
                        'period_batch_size' => self::PERIOD_BATCH_SIZE,
                        'validation_batch_size' => self::VALIDATION_BATCH_SIZE,
                        'snapshot_mode' => 'rolling_weekly',
                        'comparison_scope' => 'all',
                        'missing_product_policy' => 'retain',
                    ],
                ]);

                $periodRows = [];
                $validationRows = [];

                $batchTimestamp = now();

                foreach ($snapshot['worksheets'] as $sheet) {

                    $department = trim(
                        $sheet['department']
                    );

                    $srNumber = trim(
                        $sheet['sr_number']
                    );

                    foreach ($sheet['products'] as $product) {

                        $productCode = trim(
                            $product['product_code']
                        );

                        $lineNumber = trim(
                            (string) ($product['line_number'] ?? '')
                        );

                        $identityHash = hash(
                            'sha256',
                            json_encode([
                                $department,
                                $productCode,
                            ], JSON_UNESCAPED_UNICODE)
                        );

                        $order = ImportedOrder::create([
                            'weekly_import_id' => $import->id,
                            'department' => $department,
                            'worksheet_name' => $department,
                            'sr_number' => $srNumber,
                            'product_code' => $productCode,
                            'line_number' => $lineNumber,
                            'car_model' => $product['car_model'] ?? null,
                            'family' => $product['family'] ?? null,
                            'jig_type' => $product['jig_type'] ?? null,
                            'source_row' => $product['source_row'] ?? null,
                            'identity_hash' => $identityHash,
                        ]);

                        foreach (
                            $product['quantities'] as $periodDate => $quantity
                        ) {

                            $expectedCum =
                                $product['expected_cumulative_quantities'][$periodDate]
                                ?? null;

                            $cum =
                                $product['cumulative_quantities'][$periodDate]
                                ?? null;

                            $cumStatus =
                                $product['cum_statuses'][$periodDate]
                                ?? 'NOT_CHECKED';

                            $cumDifference = (
                                $cum === null ||
                                $expectedCum === null
                            )
                                ? null
                                : (float) $cum - (float) $expectedCum;

                            $periodRows[] = [
                                'imported_order_id' => $order->id,
                                'sr_issue_date' => $periodDate,
                                'quantity' => $quantity,
                                'cumulative_quantity' => $cum,
                                'expected_cumulative_quantity' => $expectedCum,
                                'cum_status' => $cumStatus,
                                'cum_difference' => $cumDifference,
                                'created_at' => $batchTimestamp,
                                'updated_at' => $batchTimestamp,
                            ];

                            if (
                                count($periodRows) >= self::PERIOD_BATCH_SIZE
                            ) {
                                DB::table('order_periods')
                                    ->insert($periodRows);

                                $periodRows = [];
                            }
                        }

                        foreach (
                            ($product['validation_issues'] ?? []) as $issue
                        ) {

                            $validationRows[] = [
                                'weekly_import_id' => $import->id,
                                'imported_order_id' => $order->id,
                                'sr_issue_date' => $issue['sr_issue_date'] ?? null,
                                'source_row' => $issue['source_row'] ?? null,
                                'issue_type' => $issue['issue_type'],
                                'expected_value' => $issue['expected_value'] ?? null,
                                'actual_value' => $issue['actual_value'] ?? null,
                                'difference' => $issue['difference'] ?? null,
                                'status' => 'pending',
                                'metadata' => json_encode(
                                    $issue['metadata'] ?? [],
                                    JSON_UNESCAPED_UNICODE
                                ),
                                'created_at' => $batchTimestamp,
                                'updated_at' => $batchTimestamp,
                            ];

                            if (
                                count($validationRows)
                                >= self::VALIDATION_BATCH_SIZE
                            ) {
                                DB::table('import_validation_issues')
                                    ->insert($validationRows);

                                $validationRows = [];
                            }
                        }
                    }
                }

                if (!empty($periodRows)) {
                    DB::table('order_periods')
                        ->insert($periodRows);
                }

                if (!empty($validationRows)) {
                    DB::table('import_validation_issues')
                        ->insert($validationRows);
                }

                $import->update([
                    'status' => 'completed',
                    'processed_at' => now(),
                    'total_products' => ImportedOrder::where(
                        'weekly_import_id',
                        $import->id
                    )->count(),
                ]);

                return $import->fresh();

            }, 3);

        } catch (Throwable $exception) {

            Storage::disk('local')->delete($storedPath);

            throw $exception;
        }
    }

    private function validateSnapshot(array $snapshot): void
    {
        if (
            empty($snapshot['worksheets']) ||
            !isset($snapshot['total_products']) ||
            !isset($snapshot['total_periods'])
        ) {
            throw new RuntimeException(
                'Struktur snapshot Excel tidak valid.'
            );
        }

        foreach ($snapshot['worksheets'] as $sheet) {

            if (
                empty($sheet['department']) ||
                !isset($sheet['sr_number']) ||
                !isset($sheet['products'])
            ) {
                throw new RuntimeException(
                    'Informasi worksheet tidak lengkap.'
                );
            }

            foreach ($sheet['products'] as $product) {

                if (
                    empty($product['product_code']) ||
                    !isset($product['line_number']) ||
                    !isset($product['quantities']) ||
                    !is_array($product['quantities'])
                ) {
                    throw new RuntimeException(
                        'Data produk tidak lengkap pada worksheet '
                        . $sheet['department']
                    );
                }

                foreach ($product['quantities'] as $periodDate => $quantity) {

                    $parsedDate = \DateTimeImmutable::createFromFormat(
                        '!Y-m-d',
                        $periodDate
                    );

                    if (
                        !$parsedDate ||
                        $parsedDate->format('Y-m-d') !== $periodDate ||
                        !is_numeric($quantity) ||
                        !is_finite((float) $quantity)
                    ) {
                        throw new RuntimeException(
                            'Tanggal atau quantity tidak valid: '
                            . $periodDate
                        );
                    }
                }
            }
        }
    }
}
