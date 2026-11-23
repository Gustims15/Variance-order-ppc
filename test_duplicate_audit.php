<?php

require __DIR__ . '/vendor/autoload.php';

use App\Services\Imports\PemiIsuzuReader;

ini_set('memory_limit', '512M');

$files = [
    'WEEK_01' => $argv[1] ?? null,
    'WEEK_02' => $argv[2] ?? null,
];

$reader = new PemiIsuzuReader();

$fieldNames = [
    'car_model',
    'family',
    'jig_type',
    'line_number',
];

foreach ($files as $label => $file) {

    if (!$file || !is_file($file)) {
        exit("File snapshot tidak ditemukan.\n");
    }

    $snapshot = $reader->read($file);

    $groups = [];

    foreach ($snapshot['worksheets'] as $sheet) {

        foreach ($sheet['products'] as $product) {

            $key = implode('|', [
                $sheet['department'],
                $sheet['sr_number'],
                $product['product_code'],
            ]);

            $groups[$key][] = $product;
        }
    }

    $duplicates = array_filter(
        $groups,
        fn($items) => count($items) > 1
    );

    $fieldDiffCount = array_fill_keys(
        $fieldNames,
        0
    );

    echo "\n=== {$label} ATTRIBUTE AUDIT ===\n";

    echo "Duplicate groups: "
        . count($duplicates) . "\n";

    foreach ($duplicates as $items) {

        foreach ($fieldNames as $field) {

            $values = array_unique(
                array_map(
                    fn($item) => trim(
                        (string) ($item[$field] ?? '')
                    ),
                    $items
                )
            );

            if (count($values) > 1) {
                $fieldDiffCount[$field]++;
            }
        }
    }

    foreach ($fieldDiffCount as $field => $count) {

        echo strtoupper($field)
            . " DIFFERENCES: "
            . $count . "\n";
    }
}

echo "\nAUDIT COMPLETE.\n";
