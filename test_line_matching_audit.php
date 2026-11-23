<?php

require __DIR__ . '/vendor/autoload.php';

use App\Services\Imports\PemiIsuzuReader;

ini_set('memory_limit', '512M');

$previousFile = $argv[1] ?? null;
$currentFile = $argv[2] ?? null;

if (!$previousFile || !$currentFile) {
    exit("Masukkan kedua file snapshot.\n");
}

$reader = new PemiIsuzuReader();

$previous = $reader->read($previousFile);
$current = $reader->read($currentFile);

function createIndexes(array $snapshot): array
{
    $base = [];
    $full = [];

    foreach ($snapshot['worksheets'] as $sheet) {

        foreach ($sheet['products'] as $product) {

            $department = trim($sheet['department']);
            $sr = trim($sheet['sr_number']);
            $code = trim($product['product_code']);
            $line = trim((string) $product['line_number']);

            $baseKey = json_encode([
                $department,
                $sr,
                $code,
            ]);

            $fullKey = json_encode([
                $department,
                $sr,
                $code,
                $line,
            ]);

            $base[$baseKey][] = $line;
            $full[$fullKey] = true;
        }
    }

    return [
        'base' => $base,
        'full' => $full,
    ];
}

$old = createIndexes($previous);
$new = createIndexes($current);

$oldMissing = 0;
$newAdded = 0;
$oldLineChanged = 0;
$newLineChanged = 0;

foreach ($old['base'] as $key => $lines) {

    if (isset($new['base'][$key])) {

        foreach ($lines as $line) {

            $fullKey = json_encode([
                json_decode($key)[0],
                json_decode($key)[1],
                json_decode($key)[2],
                $line,
            ]);

            if (!isset($new['full'][$fullKey])) {
                $oldLineChanged++;
            }
        }

    } else {
        $oldMissing += count($lines);
    }
}

foreach ($new['base'] as $key => $lines) {

    if (!isset($old['base'][$key])) {
        $newAdded += count($lines);
        continue;
    }

    foreach ($lines as $line) {

        $fullKey = json_encode([
            json_decode($key)[0],
            json_decode($key)[1],
            json_decode($key)[2],
            $line,
        ]);

        if (!isset($old['full'][$fullKey])) {
            $newLineChanged++;
        }
    }
}

echo "\n=== LINE NUMBER STABILITY AUDIT ===\n";

echo "Previous records: "
    . array_sum(array_map('count', $old['base'])) . "\n";

echo "Current records: "
    . array_sum(array_map('count', $new['base'])) . "\n";

echo "\nOLD SNAPSHOT:\n";
echo "Products with no base match: "
    . $oldMissing . "\n";

echo "Products with changed line candidate: "
    . $oldLineChanged . "\n";

echo "\nNEW SNAPSHOT:\n";
echo "New base identities: "
    . $newAdded . "\n";

echo "Products with changed line candidate: "
    . $newLineChanged . "\n";

echo "\nAUDIT COMPLETE.\n";
