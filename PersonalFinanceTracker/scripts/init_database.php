<?php

declare(strict_types=1);

// Skrip ini hanya boleh dijalankan melalui terminal.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip inisialisasi hanya dapat dijalankan melalui CLI.');
}

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getPDO();

    $tables = $pdo->query(
        "SELECT name
         FROM sqlite_master
         WHERE type = 'table'
         AND name NOT LIKE 'sqlite_%'
         ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);

    echo "====================================\n";
    echo " FINTRACK\n";
    echo " INISIALISASI DATABASE\n";
    echo "====================================\n";
    echo "Status   : Berhasil\n";
    echo "Database : database/expense_tracker.sqlite\n";
    echo "Tabel tersedia:\n";

    foreach ($tables as $table) {
        echo "- {$table}\n";
    }

    echo "====================================\n";
    echo "Database siap digunakan.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "Inisialisasi gagal: " . $e->getMessage() . "\n"
    );

    exit(1);
}
