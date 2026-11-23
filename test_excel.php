<?php

require __DIR__ . '/vendor/autoload.php';

use App\Services\Imports\ExcelImportService;

$service = new ExcelImportService();

$file = $argv[1] ?? null;

if (!$file) {
    exit("Gunakan: php test_excel.php lokasi_file.xlsx\n");
}

try {

    $result = $service->read($file);

    echo "\n=== HASIL PEMBACAAN EXCEL ===\n";

    echo "Worksheet: " . $result['sheet_name'] . "\n";

    echo "Total baris: " . $result['total_rows'] . "\n";

    echo "\nHeader:\n";

    print_r($result['headers']);

    echo "\nData:\n";

    print_r(array_slice($result['data'], 0, 5));

} catch (Throwable $e) {

    echo "ERROR: " . $e->getMessage() . "\n";

}