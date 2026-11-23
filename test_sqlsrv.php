
<?php

try {
    $pdo = new PDO(
        "sqlsrv:Server=localhost;Database=variance_order;TrustServerCertificate=1"
    );

    echo "KONEKSI DATABASE VARIANCE ORDER BERHASIL!\n";

    $stmt = $pdo->query("SELECT DB_NAME() AS database_name");

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "Database aktif: " . $result['database_name'] . "\n";

} catch (PDOException $e) {
    echo "KONEKSI GAGAL!\n";
    echo $e->getMessage() . "\n";
}
