<?php

declare(strict_types=1);

/**
 * Menyiapkan struktur database dan kategori bawaan.
 */
function initializeFinTrackDatabase(PDO $pdo): void
{
    $schemaPath = dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . 'database'
        . DIRECTORY_SEPARATOR
        . 'schema.sql';

    if (!is_file($schemaPath)) {
        throw new RuntimeException(
            'File database/schema.sql tidak ditemukan.'
        );
    }

    $schema = file_get_contents($schemaPath);

    if ($schema === false || trim($schema) === '') {
        throw new RuntimeException(
            'File database/schema.sql kosong atau tidak dapat dibaca.'
        );
    }

    // Membuat tabel dan indeks jika belum tersedia.
    $pdo->exec($schema);

    // Kategori bawaan FinTrack.
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

    foreach ($defaultCategories as $category) {
        $stmt->execute([
            ':name' => $category[0],
            ':type' => $category[1],
            ':icon' => $category[2],
        ]);
    }
}

/**
 * Membuat koneksi PDO ke database SQLite.
 */
function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $databasePath = dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . 'database'
        . DIRECTORY_SEPARATOR
        . 'expense_tracker.sqlite';

    $databaseDirectory = dirname($databasePath);

    if (!is_dir($databaseDirectory)) {
        throw new RuntimeException(
            'Folder database tidak ditemukan: ' . $databaseDirectory
        );
    }

    try {
        // SQLite otomatis membuat file jika belum tersedia.
        $connection = new PDO('sqlite:' . $databasePath);

        $connection->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $connection->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );

        $connection->setAttribute(
            PDO::ATTR_EMULATE_PREPARES,
            false
        );

        // Aktifkan foreign key dan waktu tunggu database.
        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->exec('PRAGMA busy_timeout = 5000');

        // Siapkan tabel dan kategori secara otomatis.
        initializeFinTrackDatabase($connection);

        $pdo = $connection;

    } catch (Throwable $e) {
        throw new RuntimeException(
            'Persiapan database FinTrack gagal. '
            . 'Pastikan ekstensi PDO SQLite aktif, '
            . 'folder database dapat ditulis, dan file schema.sql tersedia. '
            . 'Detail: ' . $e->getMessage(),
            0,
            $e
        );
    }

    return $pdo;
}
