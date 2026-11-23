<?php

require __DIR__ . '/vendor/autoload.php';

use App\Services\Imports\PemiIsuzuReader;

ini_set('memory_limit', '512M');

$reader = new PemiIsuzuReader();

$file = $argv[1] ?? null;

if (!$file) {
    exit("Masukkan lokasi file XLSM.\n");
}

try {
    $result = $reader->read($file);

    echo "\n=== PEMI ISUZU READER TEST ===\n";

    echo "File berhasil dibaca.\n";
    echo "Total worksheet: "
        . count($result['worksheets']) . "\n";

    echo "Total produk: "
        . $result['total_products'] . "\n";

    echo "Total periode: "
        . $result['total_periods'] . "\n";

    foreach ($result['worksheets'] as $sheet) {
        echo "\n----------------------------\n";

        echo "Department: "
            . $sheet['department'] . "\n";

        echo "SR tersedia: "
            . ($sheet['sr_number'] !== '' ? 'YA' : 'TIDAK')
            . "\n";

        echo "Jumlah periode: "
            . $sheet['period_count'] . "\n";

        echo "Jumlah produk: "
            . $sheet['product_count'] . "\n";

        echo "Status: BERHASIL DIBACA\n";
    }

} catch (Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
}