<?php

namespace App\Services\Imports;

use RuntimeException;

class RollingVarianceService
{
    public function compare(
        array $previous,
        array $current
    ): array {

        $oldGroups = $this->buildGroups($previous);
        $newGroups = $this->buildGroups($current);

        $baseKeys = array_unique(array_merge(
            array_keys($oldGroups),
            array_keys($newGroups)
        ));

        $details = [];

        $summary = [
            'new_order' => 0,
            'increased' => 0,
            'decreased' => 0,
            'mixed' => 0,
            'unchanged' => 0,
            'needs_review' => 0,
            'total_changes' => 0,
        ];

        foreach ($baseKeys as $baseKey) {

            $oldRecords = $oldGroups[$baseKey] ?? [];
            $newRecords = $newGroups[$baseKey] ?? [];

            $oldCount = count($oldRecords);
            $newCount = count($newRecords);

            if ($oldCount === 0) {
                foreach ($newRecords as $record) {
                    $this->addRecord(
                        $details,
                        $summary,
                        $this->newOrderRecord($record)
                    );
                }

                continue;
            }

            if ($newCount === 0) {
                foreach ($oldRecords as $record) {
                    $this->addRecord(
                        $details,
                        $summary,
                        $this->reviewRecord(
                            $record,
                            'PRODUCT_MISSING'
                        )
                    );
                }

                continue;
            }

            $oldLines = $this->groupByLine($oldRecords);
            $newLines = $this->groupByLine($newRecords);

            $lineKeys = array_unique(array_merge(
                array_keys($oldLines),
                array_keys($newLines)
            ));

            $oldUnmatched = [];
            $newUnmatched = [];

            foreach ($lineKeys as $lineKey) {

                $oldItems = $oldLines[$lineKey] ?? [];
                $newItems = $newLines[$lineKey] ?? [];

                if (count($oldItems) === 1 &&
                    count($newItems) === 1) {

                    $this->addRecord(
                        $details,
                        $summary,
                        $this->compareProduct(
                            $oldItems[0],
                            $newItems[0]
                        )
                    );

                    continue;
                }

                if (!$oldItems) {
                    foreach ($newItems as $item) {
                        $newUnmatched[] = $item;
                    }

                    continue;
                }

                if (!$newItems) {
                    foreach ($oldItems as $item) {
                        $oldUnmatched[] = $item;
                    }

                    continue;
                }

                foreach ($oldItems as $item) {
                    $this->addRecord(
                        $details,
                        $summary,
                        $this->reviewRecord(
                            $item,
                            'DUPLICATE_MATCHING_KEY'
                        )
                    );
                }

                foreach ($newItems as $item) {
                    $this->addRecord(
                        $details,
                        $summary,
                        $this->reviewRecord(
                            $item,
                            'DUPLICATE_MATCHING_KEY'
                        )
                    );
                }
            }

            if (
                count($oldUnmatched) === 1 &&
                count($newUnmatched) === 1 &&
                $oldCount === 1 &&
                $newCount === 1
            ) {

                $this->addRecord(
                    $details,
                    $summary,
                    $this->lineChangedRecord(
                        $oldUnmatched[0],
                        $newUnmatched[0]
                    )
                );

                continue;
            }

            if ($oldUnmatched && $newUnmatched) {

                foreach ($oldUnmatched as $item) {
                    $this->addRecord(
                        $details,
                        $summary,
                        $this->reviewRecord(
                            $item,
                            'AMBIGUOUS_FALLBACK_MATCH'
                        )
                    );
                }

                foreach ($newUnmatched as $item) {
                    $this->addRecord(
                        $details,
                        $summary,
                        $this->reviewRecord(
                            $item,
                            'AMBIGUOUS_FALLBACK_MATCH'
                        )
                    );
                }

                continue;
            }

            foreach ($oldUnmatched as $item) {
                $this->addRecord(
                    $details,
                    $summary,
                    $this->reviewRecord(
                        $item,
                        'PRODUCT_MISSING'
                    )
                );
            }

            foreach ($newUnmatched as $item) {
                $this->addRecord(
                    $details,
                    $summary,
                    $this->newOrderRecord($item)
                );
            }
        }

        return [
            'previous_file' => $previous['file_name'],
            'current_file' => $current['file_name'],
            'summary' => $summary,
            'details' => $details,
        ];
    }
    private function buildGroups(array $snapshot): array
    {
        $groups = [];

        foreach ($snapshot['worksheets'] as $sheet) {

            foreach ($sheet['products'] as $product) {

                $department = trim($sheet['department']);
                $srNumber = trim($sheet['sr_number']);
                $productCode = trim(
                    $product['product_code']
                );

                $lineNumber = trim(
                    (string) ($product['line_number'] ?? '')
                );

                if (
                    $department === '' ||
                    $srNumber === '' ||
                    $productCode === '' ||
                    $lineNumber === ''
                ) {
                    throw new RuntimeException(
                        'Identitas produk tidak lengkap. '
                        . 'Periksa department, SR, product code, '
                        . 'dan line number.'
                    );
                }

                $baseKey = json_encode([
                    $department,
                    $srNumber,
                    $productCode,
                ], JSON_UNESCAPED_UNICODE);

                $groups[$baseKey][] = [
                    'department' => $department,
                    'sr_number' => $srNumber,
                    'product_code' => $productCode,
                    'line_number' => $lineNumber,

                    'product_name' => implode(
                        ' / ',
                        array_filter([
                            $product['car_model'] ?? '',
                            $product['family'] ?? '',
                            $product['jig_type'] ?? '',
                        ])
                    ),

                    'source_row' => $product['source_row'] ?? null,
                    'quantities' => $product['quantities'],
                ];
            }
        }

        return $groups;
    }

    private function groupByLine(array $records): array
    {
        $groups = [];

        foreach ($records as $record) {
            $groups[$record['line_number']][] = $record;
        }

        return $groups;
    }

    private function compareProduct(
        array $old,
        array $new
    ): array {

        $dates = array_unique(array_merge(
            array_keys($old['quantities']),
            array_keys($new['quantities'])
        ));

        $periods = [];

        $previousTotal = 0;
        $currentTotal = 0;

        $hasIncrease = false;
        $hasDecrease = false;
        $missingPeriod = false;

        foreach ($dates as $date) {

            $oldExists = array_key_exists(
                $date,
                $old['quantities']
            );

            $newExists = array_key_exists(
                $date,
                $new['quantities']
            );

            if (!$oldExists || !$newExists) {

                $missingPeriod = true;

                $periods[$date] = [
                    'forecast' => $oldExists
                        ? $old['quantities'][$date]
                        : null,

                    'latest' => $newExists
                        ? $new['quantities'][$date]
                        : null,

                    'variance' => null,
                    'status' => 'NEEDS_REVIEW',
                ];

                continue;
            }

            $oldQty = $old['quantities'][$date];
            $newQty = $new['quantities'][$date];

            $variance = $newQty - $oldQty;

            $previousTotal += $oldQty;
            $currentTotal += $newQty;

            if ($variance > 0) {
                $hasIncrease = true;
                $periodStatus = 'INCREASED';

            } elseif ($variance < 0) {
                $hasDecrease = true;
                $periodStatus = 'DECREASED';

            } else {
                $periodStatus = 'UNCHANGED';
            }

            $periods[$date] = [
                'forecast' => $oldQty,
                'latest' => $newQty,
                'variance' => $variance,
                'status' => $periodStatus,
            ];
        }

        if ($missingPeriod) {
            $status = 'NEEDS_REVIEW';
            $reason = 'PERIOD_MISSING';

        } elseif ($hasIncrease && $hasDecrease) {
            $status = 'MIXED';
            $reason = null;

        } elseif ($hasIncrease) {
            $status = 'INCREASED';
            $reason = null;

        } elseif ($hasDecrease) {
            $status = 'DECREASED';
            $reason = null;

        } else {
            $status = 'UNCHANGED';
            $reason = null;
        }

        return [
            'department' => $new['department'],
            'sr_number' => $new['sr_number'],
            'product_code' => $new['product_code'],
            'line_number' => $new['line_number'],
            'product_name' => $new['product_name'],
            'source_row' => $new['source_row'],

            'previous_total' => $previousTotal,
            'current_total' => $currentTotal,
            'total_variance' => $currentTotal - $previousTotal,

            'status' => $status,
            'reason' => $reason,
            'periods' => $periods,
        ];
    }

    private function lineChangedRecord(
        array $old,
        array $new
    ): array {

        return [
            'department' => $new['department'],
            'sr_number' => $new['sr_number'],
            'product_code' => $new['product_code'],

            'previous_line_number' => $old['line_number'],
            'current_line_number' => $new['line_number'],

            'line_number' => $new['line_number'],
            'product_name' => $new['product_name'],

            'previous_source_row' => $old['source_row'],
            'current_source_row' => $new['source_row'],

            'previous_total' => null,
            'current_total' => null,
            'total_variance' => null,

            'status' => 'NEEDS_REVIEW',
            'reason' => 'LINE_NUMBER_CHANGED',

            'periods' => [],
        ];
    }

    private function newOrderRecord(array $record): array
    {
        $periods = [];
        $total = 0;

        foreach ($record['quantities'] as $date => $quantity) {

            $total += $quantity;

            $periods[$date] = [
                'forecast' => 0,
                'latest' => $quantity,
                'variance' => $quantity,
                'status' => $quantity > 0
                    ? 'INCREASED'
                    : 'UNCHANGED',
            ];
        }

        return [
            'department' => $record['department'],
            'sr_number' => $record['sr_number'],
            'product_code' => $record['product_code'],
            'line_number' => $record['line_number'],
            'product_name' => $record['product_name'],
            'source_row' => $record['source_row'],

            'previous_total' => 0,
            'current_total' => $total,
            'total_variance' => $total,

            'status' => 'NEW_ORDER',
            'reason' => 'NEW_PRODUCT',
            'periods' => $periods,
        ];
    }

    private function reviewRecord(
        array $record,
        string $reason
    ): array {

        return [
            'department' => $record['department'],
            'sr_number' => $record['sr_number'],
            'product_code' => $record['product_code'],
            'line_number' => $record['line_number'],
            'product_name' => $record['product_name'],
            'source_row' => $record['source_row'],

            'previous_total' => null,
            'current_total' => null,
            'total_variance' => null,

            'status' => 'NEEDS_REVIEW',
            'reason' => $reason,
            'periods' => [],
        ];
    }

    private function addRecord(
        array &$details,
        array &$summary,
        array $record
    ): void {

        $details[] = $record;

        $statusKey = strtolower($record['status']);

        $summary[$statusKey]++;

        if (in_array($record['status'], [
            'NEW_ORDER',
            'INCREASED',
            'DECREASED',
            'MIXED',
        ], true)) {
            $summary['total_changes']++;
        }
    }
}