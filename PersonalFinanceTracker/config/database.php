<?php

declare(strict_types=1);

/**
 * Membuat koneksi PDO ke database SQLite.
 */
function getPDO(): PDO
{
    static $pdo = null;

    // Gunakan koneksi yang sama jika sudah tersedia.
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Lokasi database: folder database di direktori utama proyek.
    $databasePath = dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . 'database'
        . DIRECTORY_SEPARATOR
        . 'expense_tracker.sqlite';

    if (!is_dir(dirname($databasePath))) {
        throw new RuntimeException(
            'Folder database tidak ditemukan: '
            . dirname($databasePath)
        );
    }

    try {
        $pdo = new PDO('sqlite:' . $databasePath);

        $pdo->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $pdo->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );

        $pdo->setAttribute(
            PDO::ATTR_EMULATE_PREPARES,
            false
        );

        // Aktifkan pemeriksaan foreign key pada koneksi ini.
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Batasi waktu tunggu ketika database sedang dikunci.
        $pdo->exec('PRAGMA busy_timeout = 5000');

    } catch (PDOException $e) {
        throw new RuntimeException(
            'Koneksi SQLite gagal. Pastikan ekstensi PDO SQLite '
            . 'aktif dan folder database dapat ditulis.',
            0,
            $e
        );
    }

    return $pdo;
}
