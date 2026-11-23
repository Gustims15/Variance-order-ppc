<?php

namespace App\Services\Imports;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;
use Throwable;

class ExcelImportService
{
    public function read(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException(
                'File tidak ditemukan.'
            );
        }

        $spreadsheet = null;

        try {
            $spreadsheet = IOFactory::load($filePath);

            $sheet = $spreadsheet->getActiveSheet();

            $rows = $sheet->toArray(
                null,
                true,
                true,
                false
            );

            if (empty($rows)) {
                throw new RuntimeException(
                    'File Excel kosong.'
                );
            }

            $headers = array_map(
                fn ($header) => trim((string) $header),
                array_shift($rows)
            );

            $data = [];

            foreach ($rows as $index => $row) {

                if (count(array_filter(
                    $row,
                    fn ($value) => $value !== null && $value !== ''
                )) === 0) {
                    continue;
                }

                $record = [];

                foreach ($headers as $column => $header) {

                    if ($header === '') {
                        continue;
                    }

                    $record[$header] = $row[$column] ?? null;
                }

                $record['_row_number'] = $index + 2;

                $data[] = $record;
            }

            return [
                'sheet_name' => $sheet->getTitle(),
                'total_rows' => count($data),
                'headers' => array_values(
                    array_filter($headers)
                ),
                'data' => $data,
            ];

        } catch (Throwable $e) {

            throw new RuntimeException(
                'Gagal membaca file: ' . $e->getMessage(),
                0,
                $e
            );

        } finally {

            if ($spreadsheet !== null) {
                $spreadsheet->disconnectWorksheets();
            }
        }
    }
}
