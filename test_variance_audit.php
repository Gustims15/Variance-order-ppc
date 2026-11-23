<?php

require __DIR__ . '/vendor/autoload.php';

use App\Services\Imports\PemiIsuzuReader;
use App\Services\Imports\RollingVarianceService;

ini_set('memory_limit', '512M');

$previousFile = $argv[1] ?? null;
$currentFile = $argv[2] ?? null;

if (!$previousFile || !$currentFile) {
    exit("Masukkan kedua file XLSM.\n");
}

$reader = new PemiIsuzuReader();
$engine = new RollingVarianceService();

$previous = $reader->read($previousFile);
$current = $reader->read($currentFile);

$result = $engine->compare($previous, $current);

echo "\n=== VARIANCE AUDIT ===\n";

$reasons = [];
$departmentStats = [];

foreach ($result['details'] as $detail) {

    $status = $detail['status'];
    $reason = $detail['reason'] ?? 'NONE';
    $department = $detail['department'];

    $reasons[$status][$reason] =
        ($reasons[$status][$reason] ?? 0) + 1;

    $departmentStats[$department][$status] =
        ($departmentStats[$department][$status] ?? 0) + 1;
}

echo "\nSTATUS BREAKDOWN:\n";

foreach ($reasons as $status => $items) {

    echo "\n$status\n";

    foreach ($items as $reason => $count) {
        echo "  $reason: $count\n";
    }
}

echo "\nDEPARTMENT BREAKDOWN:\n";

foreach ($departmentStats as $department => $items) {

    echo "\n$department\n";

    foreach ($items as $status => $count) {
        echo "  $status: $count\n";
    }
}

echo "\n=== AUDIT SELESAI ===\n";