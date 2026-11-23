<?php

require __DIR__ . '/vendor/autoload.php';

use App\Services\Imports\PemiIsuzuReader;
use App\Services\Imports\RollingVarianceService;

ini_set('memory_limit', '512M');

$previousFile = $argv[1] ?? null;
$currentFile = $argv[2] ?? null;

if (!$previousFile || !$currentFile) {
    exit(
        "Gunakan:\n" .
        "php test_variance.php previous.xlsm current.xlsm\n"
    );
}

try {

    echo "\nMembaca snapshot sebelumnya...\n";

    $reader = new PemiIsuzuReader();

    $previous = $reader->read($previousFile);

    echo "Membaca snapshot terbaru...\n";

    $current = $reader->read($currentFile);

    echo "Menjalankan Rolling Variance Engine...\n";

    $engine = new RollingVarianceService();

    $result = $engine->compare(
        $previous,
        $current
    );

    echo "\n====================================\n";
    echo "ROLLING VARIANCE RESULT\n";
    echo "====================================\n";

    echo "Total produk dibandingkan: "
        . count($result['details']) . "\n";

    foreach ($result['summary'] as $name => $value) {
        echo strtoupper($name) . ": " . $value . "\n";
    }

    echo "\nDepartment summary:\n";

    $departments = [];

    foreach ($result['details'] as $detail) {

        $department = $detail['department'];

        $departments[$department] =
            ($departments[$department] ?? 0) + 1;
    }

    foreach ($departments as $name => $count) {
        echo $name . ": " . $count . " produk\n";
    }

    echo "\nPROSES PERBANDINGAN SELESAI.\n";

} catch (Throwable $e) {

    echo "\nERROR: " . $e->getMessage() . "\n";

}