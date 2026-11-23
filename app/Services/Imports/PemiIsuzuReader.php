<?php

namespace App\Services\Imports;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;

class PemiIsuzuReader
{
    private const HEADER_ROW = 19;
    private const FIRST_DATA_ROW = 21;
    private const FIRST_PERIOD_COLUMN = 11; // K
    private const SR_NUMBER_CELL = 'B7';
    private const PRODUCT_CODE_COLUMN = 5; // E

    public function read(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('File tidak ditemukan.');
        }

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($filePath);

        try {
            $result = [
                'file_name' => basename($filePath),
                'worksheets' => [],
                'total_products' => 0,
                'total_periods' => 0,
            ];

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $highestColumn = Coordinate::columnIndexFromString(
                    $sheet->getHighestDataColumn()
                );

                $highestRow = $sheet->getHighestDataRow();

                $srNumber = trim(
                    (string) $sheet->getCell(
                        self::SR_NUMBER_CELL
                    )->getValue()
                );

                $periods = [];

                for (
                    $column = self::FIRST_PERIOD_COLUMN;
                    $column <= $highestColumn;
                    $column++
                ) {
                    $coordinate = Coordinate::stringFromColumnIndex(
                        $column
                    ) . self::HEADER_ROW;

                    $date = $this->normalizeDate(
                        $sheet->getCell($coordinate)->getValue()
                    );

                    if ($date !== null) {
                        $periods[$column] = $date;
                    }
                }

                $products = [];

                for (
                    $row = self::FIRST_DATA_ROW;
                    $row <= $highestRow;
                    $row++
                ) {
                    $productCode = trim(
                        (string) $sheet->getCell(
                            'E' . $row
                        )->getValue()
                    );

                    if ($productCode === '') {
                        continue;
                    }

                    $quantities = [];

                    foreach ($periods as $column => $date) {
                        $coordinate =
                            Coordinate::stringFromColumnIndex($column)
                            . $row;

                        $value = $sheet->getCell(
                            $coordinate
                        )->getValue();

                        if ($value === null || $value === '') {
                            $quantity = 0;
                        } elseif (is_numeric($value)) {
                            $quantity = (float) $value;
                        } else {
                            throw new RuntimeException(
                                "Quantity tidak valid pada worksheet "
                                . $sheet->getTitle()
                                . ", baris {$row}, kolom {$coordinate}."
                            );
                        }

                        $quantities[$date] = $quantity;
                    }

                    $products[] = [
                        'product_code' => $productCode,
                        'car_model' => $this->cellString(
                            $sheet, 'A', $row
                        ),
                        'family' => $this->cellString(
                            $sheet, 'B', $row
                        ),
                        'jig_type' => $this->cellString(
                            $sheet, 'C', $row
                        ),
                        'line_number' => $this->cellString(
                            $sheet, 'D', $row
                        ),
                        'source_row' => $row,
                        'quantities' => $quantities,
                    ];
                }

                $result['worksheets'][] = [
                    'department' => $sheet->getTitle(),
                    'sr_number' => $srNumber,
                    'periods' => array_values($periods),
                    'period_count' => count($periods),
                    'products' => $products,
                    'product_count' => count($products),
                ];

                $result['total_products'] += count($products);

                $result['total_periods'] = max(
                    $result['total_periods'],
                    count($periods)
                );
            }

            return $result;

        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::
                    excelToDateTimeObject((float) $value)
                    ->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return (new \DateTimeImmutable(trim($value)))
                    ->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function cellString(
        $sheet,
        string $column,
        int $row
    ): string {
        return trim(
            (string) $sheet->getCell($column . $row)->getValue()
        );
    }
}