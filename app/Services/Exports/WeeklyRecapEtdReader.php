<?php

namespace App\Services\Exports;

use DateTimeImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class WeeklyRecapEtdReader
{
    private const HEADER_LIMIT = 100;

    /**
     * @param array<string, list<string>> $datesByDepartment
     * @return array<string, array<string, string>>
     */
    public function read(string $path, array $datesByDepartment): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class implements IReadFilter {
            public function readCell(
                string $columnAddress,
                int $row,
                string $worksheetName = ''
            ): bool {
                return $row <= WeeklyRecapEtdReader::headerLimit();
            }
        });

        $spreadsheet = $reader->load($path);

        try {
            $result = [];

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $department = trim($sheet->getTitle());

                if (!isset($datesByDepartment[$department])) {
                    continue;
                }

                $result[$department] = $this->readWorksheet(
                    $sheet,
                    $datesByDepartment[$department]
                );
            }

            return $result;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public static function headerLimit(): int
    {
        return self::HEADER_LIMIT;
    }

    private function readWorksheet(Worksheet $sheet, array $requestedDates): array
    {
        $header = $this->findHeader($sheet);

        if ($header === null) {
            return [];
        }

        [$srRow, $srColumn, $etdRow] = $header;
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $requested = array_fill_keys($requestedDates, true);
        $datesByMonthDay = [];

        foreach ($requestedDates as $date) {
            $datesByMonthDay[substr($date, 5)][] = $date;
        }

        $result = [];

        for ($column = $srColumn + 1; $column <= $lastColumn; $column++) {
            $letter = Coordinate::stringFromColumnIndex($column);
            $srParts = $this->dateParts($this->cellValue($sheet, $letter . $srRow));

            if ($srParts === null) {
                continue;
            }

            [$month, $day, $year] = $srParts;
            $monthDay = sprintf('%02d-%02d', $month, $day);

            if ($year !== null) {
                $srDate = sprintf('%04d-%s', $year, $monthDay);

                if (!isset($requested[$srDate])) {
                    continue;
                }
            } else {
                $candidates = $datesByMonthDay[$monthDay] ?? [];

                if ($candidates === []) {
                    continue;
                }

                if (count($candidates) > 1) {
                    throw new RuntimeException(
                        "Tahun SR ISSUE DATE pada {$sheet->getTitle()} "
                        . "sel {$letter}{$srRow} tidak dapat dipastikan."
                    );
                }

                $srDate = $candidates[0];
            }

            $etdParts = $this->dateParts($this->cellValue($sheet, $letter . $etdRow));

            if ($etdParts === null) {
                continue;
            }

            $etdDate = $this->resolveEtdDate($etdParts, $srDate);

            if (isset($result[$srDate]) && $result[$srDate] !== $etdDate) {
                throw new RuntimeException(
                    "SR ISSUE DATE {$srDate} memiliki dua ETD berbeda "
                    . "pada worksheet {$sheet->getTitle()}."
                );
            }

            $result[$srDate] = $etdDate;
        }

        return $result;
    }

    private function findHeader(Worksheet $sheet): ?array
    {
        $lastRow = min(self::HEADER_LIMIT, $sheet->getHighestDataRow());
        $etdRow = null;

        foreach ($sheet->getRowIterator(1, $lastRow) as $row) {
            $cells = $row->getCellIterator();
            $cells->setIterateOnlyExistingCells(true);

            foreach ($cells as $cell) {
                $value = $cell->getValue();

                if (!is_string($value)) {
                    continue;
                }

                $label = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($value)));

                if (in_array($label, ['ETD', 'ETDPORT'], true)) {
                    $etdRow = $row->getRowIndex();
                }

                if ($label === 'SRISSUEDATE' && $etdRow !== null) {
                    return [
                        $row->getRowIndex(),
                        Coordinate::columnIndexFromString($cell->getColumn()),
                        $etdRow,
                    ];
                }
            }
        }

        return null;
    }

    private function cellValue(Worksheet $sheet, string $coordinate): mixed
    {
        if (!$sheet->cellExists($coordinate)) {
            return null;
        }

        $cell = $sheet->getCell($coordinate);

        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            return $cell->getOldCalculatedValue() ?? $cell->getCalculatedValue();
        }

        return $cell->getValue();
    }

    private function dateParts(mixed $value): ?array
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
        } else {
            $parsed = date_parse($value);

            if (
                $parsed['error_count'] > 0
                || $parsed['warning_count'] > 0
                || $parsed['month'] === false
                || $parsed['day'] === false
            ) {
                return null;
            }

            $month = $parsed['month'];
            $day = $parsed['day'];
            $year = $parsed['year'] === false ? null : $parsed['year'];
        }

        return checkdate($month, $day, $year ?? 2000)
            ? [$month, $day, $year]
            : null;
    }

    private function resolveEtdDate(array $parts, string $srDate): string
    {
        [$month, $day, $year] = $parts;

        if ($year !== null) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        $anchor = new DateTimeImmutable($srDate);
        $anchorYear = (int) $anchor->format('Y');
        $closest = null;
        $shortestDistance = null;

        foreach ([$anchorYear, $anchorYear - 1, $anchorYear + 1] as $candidateYear) {
            if (!checkdate($month, $day, $candidateYear)) {
                continue;
            }

            $candidate = $anchor->setDate($candidateYear, $month, $day);
            $distance = $anchor->diff($candidate)->days;

            if ($shortestDistance === null || $distance < $shortestDistance) {
                $closest = $candidate;
                $shortestDistance = $distance;
            }
        }

        if ($closest === null) {
            throw new RuntimeException("Tahun ETD {$month}/{$day} untuk {$srDate} tidak valid.");
        }

        return $closest->format('Y-m-d');
    }
}