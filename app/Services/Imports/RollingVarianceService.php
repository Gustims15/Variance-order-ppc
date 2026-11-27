<?php

namespace App\Services\Imports;

use RuntimeException;

class RollingVarianceService
{
    private const ATTRIBUTE_FIELDS = [
        'car_model',
        'family',
        'jig_type',
    ];

    /**
     * Main comparison engine.
     */
    public function compare(array $previous, array $current): array
    {
        $oldGroups = $this->deduplicateGroups($this->buildGroups($previous));
        $newGroups = $this->deduplicateGroups($this->buildGroups($current));

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
            'retained' => 0,
            'needs_review' => 0,
            'total_changes' => 0,
        ];

        foreach ($baseKeys as $baseKey) {
            $oldRecords = $oldGroups[$baseKey] ?? [];
            $newRecords = $newGroups[$baseKey] ?? [];

            $oldCount = count($oldRecords);
            $newCount = count($newRecords);

            if ($oldCount === 0 && $newCount === 1) {
                $this->addRecord($details, $summary, $this->newOrderRecord($newRecords[0]));
                continue;
            }

            if ($oldCount === 0 && $newCount > 1) {
                $this->addRecord(
                    $details,
                    $summary,
                    $this->duplicateReviewRecord([], $newRecords)
                );
                continue;
            }

            if ($newCount === 0 && $oldCount === 1) {
                $this->addRecord($details, $summary, $this->retainedRecord($oldRecords[0]));
                continue;
            }

            if ($newCount === 0 && $oldCount > 1) {
                $this->addRecord(
                    $details,
                    $summary,
                    $this->duplicateReviewRecord($oldRecords, [])
                );
                continue;
            }

            if ($oldCount !== 1 || $newCount !== 1) {
                $this->addRecord(
                    $details,
                    $summary,
                    $this->duplicateReviewRecord($oldRecords, $newRecords)
                );
                continue;
            }

            $this->addRecord(
                $details,
                $summary,
                $this->compareProduct($oldRecords[0], $newRecords[0])
            );
        }

        return [
            'previous_file' => $previous['file_name'] ?? null,
            'current_file' => $current['file_name'] ?? null,
            'summary' => $summary,
            'details' => $details,
        ];
    }

    /**
     * Build product groups using Department + Product Code.
     * Product attributes are retained for validation, not used as the matching key.
     */
    private function buildGroups(array $snapshot): array
    {
        $groups = [];

        foreach ($snapshot['worksheets'] ?? [] as $sheet) {
            $department = trim((string) ($sheet['department'] ?? ''));

            if ($department === '') {
                throw new RuntimeException('Department tidak boleh kosong.');
            }

            foreach ($sheet['products'] ?? [] as $product) {
                $productCode = trim((string) ($product['product_code'] ?? ''));

                if ($productCode === '') {
                    throw new RuntimeException('Product No tidak boleh kosong.');
                }

                $quantities = $product['quantities'] ?? [];

                if (!is_array($quantities)) {
                    throw new RuntimeException(
                        "Format quantity tidak valid untuk Product No {$productCode}."
                    );
                }

                $attributes = $this->extractAttributes($product);
                $baseKey = json_encode(
                    [$department, $productCode],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );

                $groups[$baseKey][] = [
                    'department' => $department,
                    'sr_number' => trim((string) ($sheet['sr_number'] ?? '')),
                    'product_code' => $productCode,
                    'line_number' => trim((string) ($product['line_number'] ?? '')),
                    'product_name' => $this->formatProductName($attributes),
                    'attributes' => $attributes,
                    'source_row' => $product['source_row'] ?? null,
                    'quantities' => $quantities,
                    'cumulative_quantities' => $product['cumulative_quantities'] ?? [],
                    'cum_statuses' => $product['cum_statuses'] ?? [],
                ];
            }
        }

        return $groups;
    }

    private function extractAttributes(array $product): array
    {
        $attributes = [];

        foreach (self::ATTRIBUTE_FIELDS as $field) {
            $value = $product[$field] ?? null;
            $attributes[$field] = $value === null ? null : trim((string) $value);
        }

        return $attributes;
    }

    private function formatProductName(array $attributes): string
    {
        return implode(' / ', array_values(array_filter(
            $attributes,
            fn ($value) => $value !== null && $value !== ''
        )));
    }

    /**
     * Normalize attribute values for comparison. Null and blank are equivalent;
     * comparison is case-insensitive and ignores repeated whitespace.
     */
    private function normalizedAttributes(array $attributes): array
    {
        $normalized = [];

        foreach (self::ATTRIBUTE_FIELDS as $field) {
            $value = (string) ($attributes[$field] ?? '');
            $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
            $normalized[$field] = function_exists('mb_strtoupper')
                ? mb_strtoupper($value, 'UTF-8')
                : strtoupper($value);
        }

        return $normalized;
    }

    private function attributesAreIdentical(array $first, array $second): bool
    {
        return $this->normalizedAttributes($first) === $this->normalizedAttributes($second);
    }

    /**
     * Collapse records only when BOTH their product attributes and all period
     * quantities are identical. Conflicting records remain visible for review.
     */
    private function deduplicateGroups(array $groups): array
    {
        foreach ($groups as $key => $records) {
            if (count($records) <= 1) {
                continue;
            }

            $reference = $records[0];
            $isIdentical = true;

            foreach (array_slice($records, 1) as $record) {
                if (
                    !$this->attributesAreIdentical(
                        $reference['attributes'],
                        $record['attributes']
                    ) ||
                    !$this->quantitiesAreIdentical(
                        $reference['quantities'],
                        $record['quantities']
                    ) ||
                    !$this->quantitiesAreIdentical(
                        $reference['cumulative_quantities'],
                        $record['cumulative_quantities']
                    )
                ) {
                    $isIdentical = false;
                    break;
                }
            }

            if (!$isIdentical) {
                continue;
            }

            $reference['duplicate_count'] = count($records);
            $reference['duplicate_source_rows'] = $this->nonNullValues(
                array_column($records, 'source_row')
            );
            $reference['duplicate_line_numbers'] = $this->nonBlankValues(
                array_column($records, 'line_number')
            );

            // Keep every original row's attribute values in the audit trail.
            $reference['duplicate_attribute_sets'] = array_map(
                fn ($record) => $record['attributes'],
                $records
            );

            $groups[$key] = [$reference];
        }

        return $groups;
    }

    private function quantitiesAreIdentical(array $first, array $second): bool
    {
        if (count($first) !== count($second)) {
            return false;
        }

        $firstDates = array_keys($first);
        $secondDates = array_keys($second);
        sort($firstDates);
        sort($secondDates);

        if ($firstDates !== $secondDates) {
            return false;
        }

        foreach ($firstDates as $date) {
            $firstValue = $first[$date] ?? null;
            $secondValue = $second[$date] ?? null;

            // Missing cumulative readings are equivalent only when both rows
            // are missing that same reading.
            if ($firstValue === null || $secondValue === null) {
                if ($firstValue !== null || $secondValue !== null) {
                    return false;
                }

                continue;
            }

            if (!is_numeric($firstValue) || !is_numeric($secondValue)) {
                return false;
            }

            if (abs((float) $firstValue - (float) $secondValue) > 0.0001) {
                return false;
            }
        }

        return true;
    }

    private function nonNullValues(array $values): array
    {
        return array_values(array_filter($values, fn ($value) => $value !== null));
    }

    private function nonBlankValues(array $values): array
    {
        return array_values(array_filter(
            $values,
            fn ($value) => $value !== null && trim((string) $value) !== ''
        ));
    }

    private function sourceRowsForRecord(array $record): array
    {
        if (isset($record['duplicate_source_rows'])) {
            return $record['duplicate_source_rows'];
        }

        return $record['source_row'] === null ? [] : [$record['source_row']];
    }

    private function lineNumbersForRecord(array $record): array
    {
        if (isset($record['duplicate_line_numbers'])) {
            return $record['duplicate_line_numbers'];
        }

        return $record['line_number'] === '' ? [] : [$record['line_number']];
    }

    private function duplicateCountForRecord(array $record): int
    {
        return max(1, (int) ($record['duplicate_count'] ?? 1));
    }

    /**
     * One review item for a product group that cannot be matched safely.
     */
    private function duplicateReviewRecord(array $oldRecords, array $newRecords): array
    {
        $reference = $oldRecords[0] ?? $newRecords[0] ?? null;

        if ($reference === null) {
            throw new RuntimeException('Tidak ada record untuk duplicate review.');
        }

        $review = $this->reviewRecord($reference, 'DUPLICATE_MATCHING_KEY');

        $previousRows = [];
        $currentRows = [];
        $previousLines = [];
        $currentLines = [];

        foreach ($oldRecords as $record) {
            $previousRows = array_merge($previousRows, $this->sourceRowsForRecord($record));
            $previousLines = array_merge($previousLines, $this->lineNumbersForRecord($record));
        }

        foreach ($newRecords as $record) {
            $currentRows = array_merge($currentRows, $this->sourceRowsForRecord($record));
            $currentLines = array_merge($currentLines, $this->lineNumbersForRecord($record));
        }

        $previousCount = array_sum(array_map(
            fn ($record) => $this->duplicateCountForRecord($record),
            $oldRecords
        ));
        $currentCount = array_sum(array_map(
            fn ($record) => $this->duplicateCountForRecord($record),
            $newRecords
        ));

        $review['duplicate_count'] = max($previousCount, $currentCount);
        $review['previous_duplicate_count'] = $previousCount;
        $review['current_duplicate_count'] = $currentCount;
        $review['previous_source_rows'] = $previousRows;
        $review['current_source_rows'] = $currentRows;
        $review['previous_line_numbers'] = $previousLines;
        $review['current_line_numbers'] = $currentLines;
        $review['previous_attribute_sets'] = array_map(
            fn ($record) => $record['attributes'],
            $oldRecords
        );
        $review['current_attribute_sets'] = array_map(
            fn ($record) => $record['attributes'],
            $newRecords
        );
        // For a duplicate review, attributes are sets because there may be
        // more than one conflicting source record on either side.
        $review['previous_attributes'] = $review['previous_attribute_sets'];
        $review['current_attributes'] = $review['current_attribute_sets'];

        // Scalar source columns are intentionally left empty: the full lists
        // above are the authoritative audit trail for an ambiguous group.
        $review['previous_source_row'] = null;
        $review['current_source_row'] = null;
        $review['previous_line_number'] = null;
        $review['current_line_number'] = null;

        return $review;
    }

    /**
     * Resolve the source cumulative-data status for a period.
     * Cumulative variance is informational and does not alter the QTY status.
     */
    private function resolveCumStatus(array $old, array $new, string $date): string
    {
        $oldStatus = $old['cum_statuses'][$date] ?? 'NOT_CHECKED';
        $newStatus = $new['cum_statuses'][$date] ?? 'NOT_CHECKED';

        if ($oldStatus !== 'VALID' || $newStatus !== 'VALID') {
            return $newStatus === 'VALID' ? $oldStatus : $newStatus;
        }

        return 'VALID';
    }

    /**
     * Compare weekly snapshot QTY and cumulative values period by period.
     */
    private function compareProduct(array $old, array $new): array
    {
        $audit = [
            'previous_duplicate_count' => $this->duplicateCountForRecord($old),
            'current_duplicate_count' => $this->duplicateCountForRecord($new),
            'previous_source_rows' => $this->sourceRowsForRecord($old),
            'current_source_rows' => $this->sourceRowsForRecord($new),
            'previous_line_numbers' => $this->lineNumbersForRecord($old),
            'current_line_numbers' => $this->lineNumbersForRecord($new),
            'previous_attributes' => $old['attributes'],
            'current_attributes' => $new['attributes'],
            'previous_duplicate_attribute_sets' => $old['duplicate_attribute_sets'] ?? [],
            'current_duplicate_attribute_sets' => $new['duplicate_attribute_sets'] ?? [],
            'duplicate_count' => max(
                $this->duplicateCountForRecord($old),
                $this->duplicateCountForRecord($new)
            ),
            'duplicate_source_rows' => $this->sourceRowsForRecord($new),
            'duplicate_line_numbers' => $this->lineNumbersForRecord($new),
        ];

        if (!$this->attributesAreIdentical($old['attributes'], $new['attributes'])) {
            return array_merge([
                'department' => $new['department'],
                'sr_number' => $new['sr_number'],
                'previous_sr_number' => $old['sr_number'],
                'current_sr_number' => $new['sr_number'],
                'product_code' => $new['product_code'],
                'line_number' => $new['line_number'],
                'previous_line_number' => $old['line_number'],
                'current_line_number' => $new['line_number'],
                'product_name' => $new['product_name'],
                'source_row' => $new['source_row'],
                'previous_source_row' => $old['source_row'],
                'current_source_row' => $new['source_row'],
                'previous_total' => null,
                'current_total' => null,
                'total_variance' => null,
                'status' => 'NEEDS_REVIEW',
                'reason' => 'PRODUCT_ATTRIBUTES_MISMATCH',
                'periods' => [],
            ], $audit);
        }

        $dates = array_unique(array_merge(
            array_keys($old['quantities']),
            array_keys($new['quantities'])
        ));
        sort($dates);

        $periods = [];
        $previousTotal = 0.0;
        $currentTotal = 0.0;
        $hasIncrease = false;
        $hasDecrease = false;
        $missingPeriod = false;

        foreach ($dates as $date) {
            $oldExists = array_key_exists($date, $old['quantities']);
            $newExists = array_key_exists($date, $new['quantities']);

            if (!$oldExists || !$newExists) {
                $missingPeriod = true;
                $periods[$date] = [
                    'forecast' => $oldExists ? $old['quantities'][$date] : null,
                    'latest' => $newExists ? $new['quantities'][$date] : null,
                    'variance' => null,
                    'status' => 'NEEDS_REVIEW',
                    'previous_cumulative_quantity' => $old['cumulative_quantities'][$date] ?? null,
                    'current_cumulative_quantity' => $new['cumulative_quantities'][$date] ?? null,
                    'cumulative_variance' => null,
                    'cum_status' => $this->resolveCumStatus($old, $new, $date),
                ];
                continue;
            }

            $oldQty = (float) $old['quantities'][$date];
            $newQty = (float) $new['quantities'][$date];
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

            $oldCum = $old['cumulative_quantities'][$date] ?? null;
            $newCum = $new['cumulative_quantities'][$date] ?? null;
            $cumVariance = (
                $oldCum !== null && $newCum !== null &&
                is_numeric($oldCum) && is_numeric($newCum)
            ) ? (float) $newCum - (float) $oldCum : null;

            $periods[$date] = [
                'forecast' => $oldQty,
                'latest' => $newQty,
                'variance' => $variance,
                'status' => $periodStatus,
                'previous_cumulative_quantity' => $oldCum,
                'current_cumulative_quantity' => $newCum,
                'cumulative_variance' => $cumVariance,
                'cum_status' => $this->resolveCumStatus($old, $new, $date),
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

        return array_merge([
            'department' => $new['department'],
            'sr_number' => $new['sr_number'],
            'previous_sr_number' => $old['sr_number'],
            'current_sr_number' => $new['sr_number'],
            'product_code' => $new['product_code'],
            'line_number' => $new['line_number'],
            'previous_line_number' => $old['line_number'],
            'current_line_number' => $new['line_number'],
            'product_name' => $new['product_name'],
            'source_row' => $new['source_row'],
            'previous_source_row' => $old['source_row'],
            'current_source_row' => $new['source_row'],
            'previous_total' => $previousTotal,
            'current_total' => $currentTotal,
            'total_variance' => $currentTotal - $previousTotal,
            'status' => $status,
            'reason' => $reason,
            'periods' => $periods,
        ], $audit);
    }

    private function newOrderRecord(array $record): array
    {
        $periods = [];
        $total = 0.0;

        foreach ($record['quantities'] as $date => $quantity) {
            $quantity = (float) $quantity;
            $total += $quantity;

            $currentCum = $record['cumulative_quantities'][$date] ?? null;
            $periods[$date] = [
                'forecast' => 0,
                'latest' => $quantity,
                'variance' => $quantity,
                'status' => $quantity > 0 ? 'INCREASED' : 'UNCHANGED',
                'previous_cumulative_quantity' => null,
                'current_cumulative_quantity' => $currentCum,
                'cumulative_variance' => null,
                'cum_status' => $record['cum_statuses'][$date] ?? 'NOT_CHECKED',
            ];
        }

        $sourceRows = $this->sourceRowsForRecord($record);
        $lineNumbers = $this->lineNumbersForRecord($record);

        return [
            'department' => $record['department'],
            'sr_number' => $record['sr_number'],
            'product_code' => $record['product_code'],
            'line_number' => $record['line_number'],
            'product_name' => $record['product_name'],
            'source_row' => $record['source_row'],
            'duplicate_count' => $this->duplicateCountForRecord($record),
            'duplicate_source_rows' => $sourceRows,
            'duplicate_line_numbers' => $lineNumbers,
            'current_duplicate_count' => $this->duplicateCountForRecord($record),
            'current_source_rows' => $sourceRows,
            'current_line_numbers' => $lineNumbers,
            'current_attributes' => $record['attributes'],
            'current_duplicate_attribute_sets' => $record['duplicate_attribute_sets'] ?? [],
            'previous_total' => 0.0,
            'current_total' => $total,
            'total_variance' => $total,
            'status' => 'NEW_ORDER',
            'reason' => 'NEW_PRODUCT',
            'periods' => $periods,
        ];
    }

    /**
     * Retain a product absent from the latest snapshot. Missing latest values
     * remain NULL: absence is not evidence that the quantity became zero.
     */
    private function retainedRecord(array $record): array
    {
        $periods = [];
        foreach ($record['quantities'] as $date => $quantity) {
            $periods[$date] = [
                'forecast' => is_numeric($quantity) ? (float) $quantity : null,
                'latest' => null,
                'variance' => null,
                'status' => 'RETAIN',
                'previous_cumulative_quantity' => $record['cumulative_quantities'][$date] ?? null,
                'current_cumulative_quantity' => null,
                'cumulative_variance' => null,
                'cum_status' => $record['cum_statuses'][$date] ?? 'NOT_CHECKED',
            ];
        }

        $rows = $this->sourceRowsForRecord($record);
        $lines = $this->lineNumbersForRecord($record);

        return [
            'department' => $record['department'],
            'sr_number' => $record['sr_number'],
            'product_code' => $record['product_code'],
            'line_number' => $record['line_number'],
            'product_name' => $record['product_name'],
            'source_row' => $record['source_row'],
            'previous_line_number' => $record['line_number'],
            'current_line_number' => null,
            'previous_source_row' => $record['source_row'],
            'current_source_row' => null,
            'previous_total' => array_sum(array_map('floatval', $record['quantities'])),
            'current_total' => null,
            'total_variance' => null,
            'status' => 'RETAIN',
            'reason' => 'PRODUCT_NOT_PRESENT_IN_LATEST_SNAPSHOT',
            'periods' => $periods,
            'duplicate_count' => $this->duplicateCountForRecord($record),
            'previous_duplicate_count' => $this->duplicateCountForRecord($record),
            'current_duplicate_count' => 0,
            'previous_source_rows' => $rows,
            'current_source_rows' => [],
            'previous_line_numbers' => $lines,
            'current_line_numbers' => [],
            'previous_attributes' => $record['attributes'],
            'current_attributes' => null,
        ];
    }

    private function reviewRecord(
        array $record,
        string $reason,
        string $side = 'current'
    ): array {
        $metadata = [
            'duplicate_count' => $this->duplicateCountForRecord($record),
            'previous_duplicate_count' => $side === 'previous'
                ? $this->duplicateCountForRecord($record) : 0,
            'current_duplicate_count' => $side === 'current'
                ? $this->duplicateCountForRecord($record) : 0,
            'previous_source_rows' => $side === 'previous'
                ? $this->sourceRowsForRecord($record) : [],
            'current_source_rows' => $side === 'current'
                ? $this->sourceRowsForRecord($record) : [],
            'previous_line_numbers' => $side === 'previous'
                ? $this->lineNumbersForRecord($record) : [],
            'current_line_numbers' => $side === 'current'
                ? $this->lineNumbersForRecord($record) : [],
            'previous_attributes' => $side === 'previous' ? $record['attributes'] : null,
            'current_attributes' => $side === 'current' ? $record['attributes'] : null,
        ];

        return array_merge([
            'department' => $record['department'],
            'sr_number' => $record['sr_number'],
            'product_code' => $record['product_code'],
            'line_number' => $record['line_number'],
            'product_name' => $record['product_name'],
            'source_row' => $record['source_row'],
            'previous_source_row' => $side === 'previous' ? $record['source_row'] : null,
            'current_source_row' => $side === 'current' ? $record['source_row'] : null,
            'previous_line_number' => $side === 'previous' ? $record['line_number'] : null,
            'current_line_number' => $side === 'current' ? $record['line_number'] : null,
            'previous_total' => null,
            'current_total' => null,
            'total_variance' => null,
            'status' => 'NEEDS_REVIEW',
            'reason' => $reason,
            'periods' => [],
        ], $metadata);
    }

    private function addRecord(array &$details, array &$summary, array $record): void
    {
        $details[] = $record;

        // RETAIN is the detail status; the summary contract uses `retained`.
        // Keep the mapping explicit so summary keys remain stable.
        $statusKey = match ($record['status']) {
            'NEW_ORDER' => 'new_order',
            'INCREASED' => 'increased',
            'DECREASED' => 'decreased',
            'MIXED' => 'mixed',
            'UNCHANGED' => 'unchanged',
            'RETAIN' => 'retained',
            'NEEDS_REVIEW' => 'needs_review',
            default => throw new RuntimeException(
                'Status variance tidak dikenal: ' . $record['status']
            ),
        };

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