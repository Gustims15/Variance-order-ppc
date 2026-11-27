<?php

namespace App\Services\Imports;

use DateTimeImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use RuntimeException;

class PemiIsuzuReader
{

        public function previewPeriods(string $filePath, string $snapshotDate): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('File tidak ditemukan.');
        }

        $snapshot = DateTimeImmutable::createFromFormat('!Y-m-d', $snapshotDate);

        if ($snapshot === false || $snapshot->format('Y-m-d') !== $snapshotDate) {
            throw new RuntimeException(
                'Tanggal snapshot harus berupa tanggal valid dengan format YYYY-MM-DD.'
            );
        }

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class implements IReadFilter {
            public function readCell(
                string $columnAddress,
                int $row,
                string $worksheetName = ''
            ): bool {
                return $row <= 100;
            }
        });

        $spreadsheet = $reader->load($filePath);

        try {
            $uniqueDates = [];

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                if ($this->detectProductHeader($sheet) === null) {
                    continue;
                }

                $dateInfo = $this->detectPeriods($sheet, $snapshot);

                if ($dateInfo['periods'] === []) {
                    throw new RuntimeException(
                        'SR ISSUE DATE tidak ditemukan pada worksheet '
                        . $sheet->getTitle() . '.'
                    );
                }

                foreach ($dateInfo['periods'] as $date) {
                    $uniqueDates[$date] = true;
                }
            }

            if ($uniqueDates === []) {
                throw new RuntimeException(
                    'Tidak ditemukan worksheet dengan struktur Excel PEMI-ISUZU yang valid.'
                );
            }

            $periods = array_keys($uniqueDates);
            sort($periods, SORT_STRING);

            return $periods;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

            public function previewEtdByDepartment(string $filePath, string $snapshotDate): array
        {
            $snapshot = DateTimeImmutable::createFromFormat('!Y-m-d', $snapshotDate);

            if ($snapshot === false || $snapshot->format('Y-m-d') !== $snapshotDate) {
                throw new RuntimeException('Tanggal snapshot tidak valid.');
            }

            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new class implements IReadFilter {
                public function readCell(
                    string $columnAddress,
                    int $row,
                    string $worksheetName = ''
                ): bool {
                    return $row <= 100;
                }
            });

            $spreadsheet = $reader->load($filePath);

            try {
                $result = [];

                foreach ($spreadsheet->getAllSheets() as $sheet) {
                    if ($this->detectProductHeader($sheet) === null) {
                        continue;
                    }

                    $dateInfo = $this->detectPeriods($sheet, $snapshot);
                    $periods = $dateInfo['periods'];

                    if ($periods === []) {
                        continue;
                    }

                    $firstPeriodColumn = array_key_first($periods);
                    $etdRow = null;

                    for ($row = $dateInfo['date_row'] - 1; $row >= 1; $row--) {
                        for ($column = 1; $column < $firstPeriodColumn; $column++) {
                            $letter = Coordinate::stringFromColumnIndex($column);
                            $label = strtoupper($this->cellString($sheet, $letter, $row));
                            $label = preg_replace('/[^A-Z0-9]/', '', $label);

                            if (in_array($label, ['ETD', 'ETDPORT'], true)) {
                                $etdRow = $row;
                                break 2;
                            }
                        }
                    }

                    if ($etdRow === null) {
                        continue;
                    }

                    $department = trim($sheet->getTitle());

                    foreach ($periods as $column => $srIssueDate) {
                        $letter = Coordinate::stringFromColumnIndex($column);
                        $cell = $sheet->getCell($letter . $etdRow);
                        $value = $cell->getDataType() === 'f'
                            ? $cell->getOldCalculatedValue()
                            : $cell->getValue();

                        $parts = $this->extractDateParts($value);

                        if ($parts === null) {
                            continue;
                        }

                        [$month, $day, $year] = $parts;

                        if ($year === null) {
                            $year = $this->nearestStartYear(
                                $month,
                                $day,
                                new DateTimeImmutable($srIssueDate)
                            );
                        }

                        $result[$department][$srIssueDate] = sprintf(
                            '%04d-%02d-%02d',
                            $year,
                            $month,
                            $day
                        );
                    }
                }

                return $result;
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }
    public function read(string $filePath, ?string $snapshotDate = null): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('File tidak ditemukan.');
        }

        $snapshot = null;

        if ($snapshotDate !== null) {
            $snapshot = DateTimeImmutable::createFromFormat('!Y-m-d', $snapshotDate);

            if ($snapshot === false || $snapshot->format('Y-m-d') !== $snapshotDate) {
                throw new RuntimeException(
                    'Tanggal snapshot harus berupa tanggal valid dengan format YYYY-MM-DD.'
                );
            }
        }

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(false);

        $spreadsheet = $reader->load($filePath);

        try {
            $result = [
                'file_name' => basename($filePath),
                'worksheets' => [],
                'total_products' => 0,
                'total_periods' => 0,
            ];

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $header = $this->detectProductHeader($sheet);

                if ($header === null) {
                    continue;
                }

                $department = trim($sheet->getTitle());
                $dateInfo = $this->detectPeriods($sheet, $snapshot);
                $periods = $dateInfo['periods'];

                if ($periods === []) {
                    throw new RuntimeException(
                        "SR ISSUE DATE tidak ditemukan pada worksheet {$department}."
                    );
                }

                $products = [];
                $highestRow = $sheet->getHighestDataRow();
                $productColumn = Coordinate::stringFromColumnIndex(
                    $header['product_column']
                );

                for ($row = $header['header_row'] + 1; $row <= $highestRow; $row++) {
                    $productCode = $this->cellString($sheet, $productColumn, $row);

                    if ($productCode === '') {
                        continue;
                    }

                    $nextRow = $row + 1;
                    $cumRowExists = $nextRow <= $highestRow
                        && $this->cellString($sheet, $productColumn, $nextRow) === '';

                    $quantities = [];
                    $cumulativeQuantities = [];
                    $expectedCumulativeQuantities = [];
                    $cumStatuses = [];
                    $validationIssues = [];

                    $runningQty = 0.0;
                    $openingBalance = null;

                    foreach ($periods as $column => $date) {
                        $letter = Coordinate::stringFromColumnIndex($column);
                        $rawQty = $this->cellString($sheet, $letter, $row);

                        if ($rawQty === '' || $rawQty === '-') {
                            $quantity = 0.0;
                        } elseif (is_numeric($rawQty)) {
                            $quantity = (float) $rawQty;
                        } else {
                            throw new RuntimeException(
                                "QTY tidak valid pada worksheet {$department}, "
                                . "Product No {$productCode}, baris {$row}, kolom {$letter}."
                            );
                        }

                        $quantities[$date] = $quantity;
                        $runningQty += $quantity;

                        $expectedCum = $openingBalance === null
                            ? null
                            : $openingBalance + $runningQty;

                        $cumValue = null;

                        if (!$cumRowExists) {
                            $status = 'MISSING_CUM_ROW';
                        } else {
                            $rawCum = $this->cellString($sheet, $letter, $nextRow);

                            if ($rawCum === '' || $rawCum === '-') {
                                $status = 'MISSING_CUM_VALUE';
                            } elseif (is_numeric($rawCum)) {
                                $cumValue = (float) $rawCum;

                                if ($openingBalance === null) {
                                    $openingBalance = $cumValue - $runningQty;
                                }

                                $expectedCum = $openingBalance + $runningQty;
                                $status = abs($expectedCum - $cumValue) <= 0.0001
                                    ? 'VALID'
                                    : 'MISMATCH';
                            } else {
                                $status = 'INVALID_CUM_VALUE';
                            }
                        }

                        $expectedCumulativeQuantities[$date] = $expectedCum;
                        $cumulativeQuantities[$date] = $cumValue;
                        $cumStatuses[$date] = $status;

                        if ($status !== 'VALID') {
                            $difference = $cumValue === null || $expectedCum === null
                                ? null
                                : $cumValue - $expectedCum;

                            $validationIssues[] = [
                                'issue_type' => $status,
                                'sr_issue_date' => $date,
                                'source_row' => $cumRowExists ? $nextRow : null,
                                'expected_value' => $expectedCum,
                                'actual_value' => $cumValue,
                                'difference' => $difference,
                                'metadata' => [
                                    'department' => $department,
                                    'product_code' => $productCode,
                                    'qty_source_row' => $row,
                                    'cum_source_row' => $cumRowExists ? $nextRow : null,
                                    'cum_column' => $letter,
                                ],
                            ];
                        }
                    }

                    $products[] = [
                        'expected_cumulative_quantities' => $expectedCumulativeQuantities,
                        'product_code' => $productCode,
                        'car_model' => $this->cellString($sheet, 'A', $row),
                        'family' => $this->cellString($sheet, 'B', $row),
                        'jig_type' => $this->cellString($sheet, 'C', $row),
                        'line_number' => $this->cellString($sheet, 'D', $row),
                        'source_row' => $row,
                        'quantities' => $quantities,
                        'cumulative_quantities' => $cumulativeQuantities,
                        'cum_statuses' => $cumStatuses,
                        'validation_issues' => $validationIssues,
                    ];

                    if ($cumRowExists) {
                        $row++;
                    }
                }

                $result['worksheets'][] = [
                    'department' => $department,
                    'sr_number' => $this->detectSrNumber($sheet),
                    'periods' => array_values($periods),
                    'period_count' => count($periods),
                    'products' => $products,
                    'product_count' => count($products),
                    'header_row' => $header['header_row'],
                    'product_column' => $header['product_column'],
                    'date_row' => $dateInfo['date_row'],
                ];

                $result['total_products'] += count($products);
                $result['total_periods'] = max($result['total_periods'], count($periods));
            }

            if ($result['worksheets'] === []) {
                throw new RuntimeException(
                    'Tidak ditemukan worksheet dengan struktur Excel PEMI-ISUZU yang valid.'
                );
            }

            return $result;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function detectProductHeader(Worksheet $sheet): ?array
    {
        $highestRow = min($sheet->getHighestDataRow(), 100);
        $highestColumn = min(
            Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
            30
        );

        for ($row = 1; $row <= $highestRow; $row++) {
            for ($col = 1; $col <= $highestColumn; $col++) {
                $letter = Coordinate::stringFromColumnIndex($col);
                $value = strtoupper($this->cellString($sheet, $letter, $row));
                $normalized = preg_replace('/[^A-Z0-9]/', '', $value);

                if (str_contains($normalized, 'PRODUCT') && str_contains($normalized, 'N')) {
                    return [
                        'header_row' => $row,
                        'product_column' => $col,
                    ];
                }
            }
        }

        return null;
    }

    private function detectPeriods(
        Worksheet $sheet,
        ?DateTimeImmutable $snapshot
    ): array {
        $highestRow = min($sheet->getHighestDataRow(), 100);
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($row = 1; $row <= $highestRow; $row++) {
            for ($col = 1; $col <= $highestColumn; $col++) {
                $letter = Coordinate::stringFromColumnIndex($col);
                $value = strtoupper($this->cellString($sheet, $letter, $row));
                $normalized = preg_replace('/[^A-Z0-9]/', '', $value);

                if ($normalized !== 'SRISSUEDATE') {
                    continue;
                }

                $dateParts = [];

                for ($periodCol = $col + 1; $periodCol <= $highestColumn; $periodCol++) {
                    $periodLetter = Coordinate::stringFromColumnIndex($periodCol);
                    $raw = $sheet->getCell($periodLetter . $row)->getValue();
                    $parts = $this->extractDateParts($raw);

                    if ($parts !== null) {
                        $dateParts[$periodCol] = $parts;
                    }
                }

                return [
                    'date_row' => $row,
                    'periods' => $this->resolvePeriods(
                        $dateParts,
                        $snapshot,
                        $sheet->getTitle(),
                        $row
                    ),
                ];
            }
        }

        return ['date_row' => null, 'periods' => []];
    }

    /**
     * Mengembalikan [bulan, hari, tahun].
     * Tahun null berarti sel hanya memuat bulan dan hari.
     */
    private function extractDateParts(mixed $value): ?array
    {
        if (is_numeric($value)) {
            $value = ExcelDate::excelToDateTimeObject((float) $value);
        }

        if ($value instanceof DateTimeInterface) {
            return [
                (int) $value->format('m'),
                (int) $value->format('d'),
                (int) $value->format('Y'),
            ];
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        // Format angka sumber PEMI-ISUZU adalah bulan/hari.
        if (preg_match(
            '~^(\d{1,2})[/-](\d{1,2})(?:[/-](\d{4}|\d{2}))?$~',
            $value,
            $matches
        )) {
            $month = (int) $matches[1];
            $day = (int) $matches[2];
            $year = isset($matches[3]) ? (int) $matches[3] : null;

            if (isset($matches[3]) && strlen($matches[3]) === 2) {
                $year += $year < 70 ? 2000 : 1900;
            }

            if (!checkdate($month, $day, $year ?? 2000)) {
                throw new RuntimeException("Tanggal SR ISSUE DATE tidak valid: {$value}.");
            }

            return [$month, $day, $year];
        }

        // Contoh format lain: 02-Nov, 02-Nov-2026, 2026-11-02.
        $parsed = date_parse($value);

        if ($parsed['month'] === false || $parsed['day'] === false) {
            return null;
        }

        if ($parsed['error_count'] > 0 || $parsed['warning_count'] > 0) {
            throw new RuntimeException("Tanggal SR ISSUE DATE tidak valid: {$value}.");
        }

        return [
            $parsed['month'],
            $parsed['day'],
            $parsed['year'] === false ? null : $parsed['year'],
        ];
    }

    private function resolvePeriods(
        array $dateParts,
        ?DateTimeImmutable $snapshot,
        string $sheetName,
        int $row
    ): array {
        if ($dateParts === []) {
            return [];
        }

        // Offset 0 untuk tahun pertama, 1 setelah melewati pergantian tahun.
        $yearOffsets = [];
        $offset = 0;
        $previousMonth = null;
        $baseYear = null;

        foreach ($dateParts as $column => [$month, $day, $explicitYear]) {
            if ($previousMonth !== null && $month < $previousMonth) {
                $offset++;
            }

            $yearOffsets[$column] = $offset;
            $previousMonth = $month;

            if ($explicitYear === null) {
                continue;
            }

            // Tanggal lengkap di kolom mana pun dapat menjadi acuan tahun.
            $candidateBase = $explicitYear - $offset;

            if ($baseYear !== null && $baseYear !== $candidateBase) {
                $letter = Coordinate::stringFromColumnIndex($column);

                throw new RuntimeException(
                    "Tahun SR ISSUE DATE tidak konsisten pada worksheet "
                    . "{$sheetName}, sel {$letter}{$row}."
                );
            }

            $baseYear = $candidateBase;
        }

        if ($baseYear === null) {
            $firstColumn = array_key_first($dateParts);
            [$firstMonth, $firstDay] = $dateParts[$firstColumn];

            if ($snapshot === null) {
                // Kompatibilitas untuk pemanggil lama yang hanya mengirim filePath.
                $baseYear = (int) date('Y');
            } else {
                $baseYear = $this->nearestStartYear($firstMonth, $firstDay, $snapshot);
            }
        }

        $periods = [];
        $previousDate = null;

        foreach ($dateParts as $column => [$month, $day]) {
            $year = $baseYear + $yearOffsets[$column];
            $letter = Coordinate::stringFromColumnIndex($column);

            if (!checkdate($month, $day, $year)) {
                throw new RuntimeException(
                    "SR ISSUE DATE {$month}/{$day}/{$year} tidak valid "
                    . "pada worksheet {$sheetName}, sel {$letter}{$row}."
                );
            }

            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

            if ($previousDate !== null && $date <= $previousDate) {
                throw new RuntimeException(
                    "SR ISSUE DATE ganda atau tidak berurutan pada worksheet "
                    . "{$sheetName}, sel {$letter}{$row}: {$date}."
                );
            }

            $periods[$column] = $date;
            $previousDate = $date;
        }

        return $periods;
    }

    private function nearestStartYear(
        int $month,
        int $day,
        DateTimeImmutable $snapshot
    ): int {
        $snapshotYear = (int) $snapshot->format('Y');
        $bestYear = null;
        $bestDistance = null;

        foreach ([$snapshotYear, $snapshotYear - 1, $snapshotYear + 1] as $year) {
            if (!checkdate($month, $day, $year)) {
                continue;
            }

            $candidate = $snapshot->setDate($year, $month, $day);
            $distance = $snapshot->diff($candidate)->days;

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestYear = $year;
                $bestDistance = $distance;
            }
        }

        if ($bestYear === null) {
            throw new RuntimeException(
                "Tahun untuk SR ISSUE DATE {$month}/{$day} tidak dapat ditentukan "
                . 'dari tanggal snapshot ' . $snapshot->format('Y-m-d') . '.'
            );
        }

        return $bestYear;
    }

    private function detectSrNumber(Worksheet $sheet): string
    {
        $highestRow = min($sheet->getHighestDataRow(), 20);
        $highestColumn = min(
            Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
            10
        );

        for ($row = 1; $row <= $highestRow; $row++) {
            for ($col = 1; $col <= $highestColumn; $col++) {
                $letter = Coordinate::stringFromColumnIndex($col);
                $value = strtoupper($this->cellString($sheet, $letter, $row));
                $normalized = preg_replace('/[^A-Z0-9]/', '', $value);

                if ($normalized === 'SRNO') {
                    $nextLetter = Coordinate::stringFromColumnIndex($col + 1);

                    return $this->cellString($sheet, $nextLetter, $row);
                }
            }
        }

        return '';
    }

    private function cellString(Worksheet $sheet, string $column, int $row): string
    {
        return trim((string) $sheet->getCell($column . $row)->getValue());
    }
}