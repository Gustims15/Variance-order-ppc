<?php

require __DIR__ . '/vendor/autoload.php';

use App\Services\Imports\PemiIsuzuReader;

ini_set('memory_limit', '512M');

$files = [
    'WEEK_01' => $argv[1] ?? null,
    'WEEK_02' => $argv[2] ?? null,
];

$reader = new PemiIsuzuReader();

function buildIdentityCounts(array $snapshot): array
{
    $groups = [];

    foreach ($snapshot['worksheets'] as $sheet) {

        foreach ($sheet['products'] as $product) {

            $base = json_encode([
                $sheet['department'],
                $sheet['sr_number'],
                $product['product_code'],
            ]);

            $line = trim((string) $product['line_number']);

            $groups[$base][$line] =
                ($groups[$base][$line] ?? 0) + 1;
        }
    }

    return $groups;
}

$snapshots = [];

foreach ($files as $name => $file) {

    if (!$file || !is_file($file)) {
        exit("File tidak ditemukan.\n");
    }

    $snapshots[$name] = buildIdentityCounts(
        $reader->read($file)
    );
}

$old = $snapshots['WEEK_01'];
$new = $snapshots['WEEK_02'];

$keys = array_unique(array_merge(
    array_keys($old),
    array_keys($new)
));

$stats = [
    'exact_identity' => 0,
    'unique_line_changed' => 0,
    'ambiguous' => 0,
    'missing_base' => 0,
    'new_base' => 0,
];

foreach ($keys as $key) {

    $oldLines = $old[$key] ?? [];
    $newLines = $new[$key] ?? [];

    if (!$oldLines) {
        $stats['new_base']++;
        continue;
    }

    if (!$newLines) {
        $stats['missing_base']++;
        continue;
    }

    $oldCount = array_sum($oldLines);
    $newCount = array_sum($newLines);

    if ($oldLines === $newLines) {
        $stats['exact_identity']++;
        continue;
    }

    if (
        $oldCount === 1 &&
        $newCount === 1 &&
        count($oldLines) === 1 &&
        count($newLines) === 1
    ) {
        $stats['unique_line_changed']++;
        continue;
    }

    $stats['ambiguous']++;
}

echo "\n=== FALLBACK MATCHING AUDIT V2 ===\n";

foreach ($stats as $name => $count) {
    echo strtoupper($name) . ': ' . $count . "\n";
}

echo "\nAUDIT COMPLETE.\n";
