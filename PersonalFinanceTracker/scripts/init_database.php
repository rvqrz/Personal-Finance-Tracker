<?php

declare(strict_types=1);

// Skrip ini hanya boleh dijalankan melalui terminal.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip inisialisasi hanya dapat dijalankan melalui CLI.');
}

require_once __DIR__ . '/../config/database.php';

$schemaPath = __DIR__ . '/../database/schema.sql';

if (!is_file($schemaPath)) {
    fwrite(
        STDERR,
        "Gagal: file database/schema.sql tidak ditemukan.\n"
    );
    exit(1);
}

$schema = file_get_contents($schemaPath);

if ($schema === false || trim($schema) === '') {
    fwrite(
        STDERR,
        "Gagal: file schema.sql kosong atau tidak dapat dibaca.\n"
    );
    exit(1);
}

try {
    $pdo = getPDO();

    // Membuat tabel berdasarkan schema.sql.
    $pdo->exec($schema);

    // Kategori bawaan untuk pemasukan dan pengeluaran.
    $defaultCategories = [
        ['Uang Bulanan', 'income', '💵'],
        ['Gaji', 'income', '💼'],
        ['Freelance', 'income', '🧑‍💻'],
        ['Bonus', 'income', '🎁'],
        ['Lainnya', 'income', '📦'],

        ['Makanan', 'expense', '🍽️'],
        ['Transportasi', 'expense', '🛵'],
        ['Belanja', 'expense', '🛍️'],
        ['Tagihan', 'expense', '🧾'],
        ['Pendidikan', 'expense', '📚'],
        ['Hiburan', 'expense', '🎮'],
        ['Kesehatan', 'expense', '💊'],
        ['Kebutuhan Rumah', 'expense', '🏠'],
        ['Lainnya', 'expense', '📌'],
    ];

    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO categories
            (name, type, icon)
         VALUES
            (:name, :type, :icon)'
    );

    $insertedCount = 0;

    foreach ($defaultCategories as $category) {
        $stmt->execute([
            ':name' => $category[0],
            ':type' => $category[1],
            ':icon' => $category[2],
        ]);

        $insertedCount += $stmt->rowCount();
    }

    // Verifikasi bahwa tabel utama sudah tersedia.
    $tables = $pdo->query(
        "SELECT name
         FROM sqlite_master
         WHERE type = 'table'
         AND name NOT LIKE 'sqlite_%'
         ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);

    echo "====================================\n";
    echo " PERSONAL EXPENSE TRACKER\n";
    echo " INISIALISASI DATABASE\n";
    echo "====================================\n";
    echo "Status       : Berhasil\n";
    echo "Database     : database/expense_tracker.sqlite\n";
    echo "Kategori baru: {$insertedCount}\n";
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
