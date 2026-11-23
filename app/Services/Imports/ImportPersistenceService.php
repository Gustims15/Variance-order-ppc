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

    private const PERIOD_BATCH_SIZE = 400;

    public function __construct(
        private readonly PemiIsuzuReader $reader
    ) {}

    public function import(
        string|UploadedFile $file,
        string $importType
    ): WeeklyImport {

        $importType = strtolower(trim($importType));

        if (!in_array($importType, ['forecast', 'actual'], true)) {
            throw new RuntimeException(
                'Jenis import harus forecast atau actual.'
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

        $extension = strtolower(
            pathinfo($filePath, PATHINFO_EXTENSION)
        );

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

        $snapshot = $this->reader->read($filePath);

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
                $importType,
                $extension,
                $originalFilename,
                $storedPath
            ) {

                if (WeeklyImport::where(
                    'file_hash',
                    $fileHash
                )->lockForUpdate()->exists()) {

                    throw new RuntimeException(
                        'File ini sudah pernah di-upload.'
                    );
                }

                $import = WeeklyImport::create([
                    'original_filename' => $originalFilename,
                    'file_hash' => $fileHash,
                    'import_type' => $importType,
                    'file_type' => $extension,
                    'status' => 'processing',

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
                        'batch_size' => self::PERIOD_BATCH_SIZE,
                    ],
                ]);

                $periodRows = [];

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
                                $srNumber,
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
                            $product['quantities'] as $date => $quantity
                        ) {

                            $periodRows[] = [
                                'imported_order_id' => $order->id,
                                'sr_issue_date' => $date,
                                'quantity' => $quantity,
                                'created_at' => $batchTimestamp,
                                'updated_at' => $batchTimestamp,
                            ];

                            if (
                                count($periodRows)
                                >= self::PERIOD_BATCH_SIZE
                            ) {

                                DB::table('order_periods')->insert(
                                    $periodRows
                                );

                                $periodRows = [];
                            }
                        }
                    }
                }

                if (!empty($periodRows)) {

                    DB::table('order_periods')->insert(
                        $periodRows
                    );

                    $periodRows = [];
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
                    !isset($product['quantities'])
                ) {
                    throw new RuntimeException(
                        'Data produk tidak lengkap pada worksheet '
                        . $sheet['department']
                    );
                }

                foreach ($product['quantities'] as $date => $quantity) {

                    $parsedDate = \DateTimeImmutable::createFromFormat(
                        '!Y-m-d',
                        $date
                    );

                    if (
                        !$parsedDate ||
                        $parsedDate->format('Y-m-d') !== $date ||
                        !is_numeric($quantity) ||
                        !is_finite((float) $quantity)
                    ) {
                        throw new RuntimeException(
                            'Tanggal atau quantity tidak valid: '
                            . $date
                        );
                    }
                }
            }
        }
    }
}