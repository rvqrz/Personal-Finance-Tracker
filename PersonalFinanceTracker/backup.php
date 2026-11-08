<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$backupPath = null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Metode request tidak diizinkan.');
    }

    verifyCsrfToken();

    // Tentukan direktori backup di luar folder publik proyek.
    $localAppData = getenv('LOCALAPPDATA');

    if ($localAppData === false || $localAppData === '') {
        throw new RuntimeException('Direktori penyimpanan Windows tidak ditemukan.');
    }

    $backupDirectory = rtrim($localAppData, '\\/')
        . DIRECTORY_SEPARATOR
        . 'FinTrack'
        . DIRECTORY_SEPARATOR
        . 'Backups';

    if (
        !is_dir($backupDirectory) &&
        !mkdir($backupDirectory, 0700, true) &&
        !is_dir($backupDirectory)
    ) {
        throw new RuntimeException('Gagal membuat direktori backup.');
    }

    if (!is_writable($backupDirectory)) {
        throw new RuntimeException('Direktori backup tidak dapat ditulis.');
    }

    // Buat nama file unik agar tidak menimpa backup sebelumnya.
    $fileName = 'fintrack_backup_'
        . date('Ymd_His')
        . '_'
        . bin2hex(random_bytes(4))
        . '.sqlite';

    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR . $fileName;

    // Membuat backup database menggunakan SQLite VACUUM INTO.
    $pdo = getPDO();

    $pdo->exec(
        'VACUUM INTO ' . $pdo->quote($backupPath)
    );

    // Pastikan file backup berhasil dibuat.
    if (!is_file($backupPath) || filesize($backupPath) === 0) {
        throw new RuntimeException('File backup gagal dibuat.');
    }

    // Periksa integritas database hasil backup.
    $checkPdo = new PDO(
        'sqlite:' . $backupPath,
        null,
        null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $integrity = $checkPdo->query(
        'PRAGMA integrity_check'
    )->fetchColumn();

    $checkPdo = null;

    if ($integrity !== 'ok') {
        throw new RuntimeException('Integritas file backup tidak valid.');
    }

    // Bersihkan output buffer sebelum mengirim file.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($backupPath));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // Kirim file backup ke browser.
    $result = readfile($backupPath);

    if ($result === false) {
        throw new RuntimeException('Gagal mengirim file backup.');
    }

    // Hapus file sementara setelah dikirim.
    unlink($backupPath);
    $backupPath = null;

    exit;

} catch (Throwable $e) {
    error_log(
        'FinTrack Backup Error: ' . $e->getMessage()
    );

    if ($backupPath !== null && is_file($backupPath)) {
        unlink($backupPath);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    exit(
        'Backup gagal dibuat. Periksa log PHP untuk informasi lebih lanjut.'
    );
}
