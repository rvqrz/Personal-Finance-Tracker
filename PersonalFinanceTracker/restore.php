<?php

require_once __DIR__ . '/includes/functions.php';

$databasePath = __DIR__ . '/database/expense_tracker.sqlite';
$schemaPath = __DIR__ . '/database/schema.sql';

$localAppData = getenv('LOCALAPPDATA');

if ($localAppData === false || $localAppData === '') {
    http_response_code(500);
    exit('Direktori penyimpanan Windows tidak ditemukan.');
}

$baseDirectory = rtrim($localAppData, '\\/')
    . DIRECTORY_SEPARATOR
    . 'FinTrack';

$restoreDirectory = $baseDirectory . DIRECTORY_SEPARATOR . 'Restore';
$backupDirectory = $baseDirectory . DIRECTORY_SEPARATOR . 'Backups';

$uploadPath = null;
$replacementPath = null;
$rollbackPath = null;
$oldDatabaseMoved = false;
$newDatabaseInstalled = false;

function validateFinTrackDatabase(
    string $path,
    string $schemaPath
): void {
    if (!is_file($path) || filesize($path) === 0) {
        throw new RuntimeException('File database kosong atau tidak ditemukan.');
    }

    // Buka database backup.
    $candidate = new PDO('sqlite:' . $path);
    $candidate->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    // Periksa integritas database.
    $integrity = $candidate->query(
        'PRAGMA integrity_check'
    )->fetchColumn();

    if ($integrity !== 'ok') {
        throw new RuntimeException(
            'Integritas database tidak valid.'
        );
    }

    $foreignKeyErrors = $candidate->query(
        'PRAGMA foreign_key_check'
    )->fetchAll();

    if (count($foreignKeyErrors) > 0) {
        throw new RuntimeException(
            'Database memiliki pelanggaran foreign key.'
        );
    }

    // Pastikan pengaturan utama tersedia.
    $settingsCount = $candidate->query(
        'SELECT COUNT(*) FROM app_settings WHERE id = 1'
    )->fetchColumn();

    if ((int) $settingsCount !== 1) {
        throw new RuntimeException(
            'Pengaturan utama FinTrack tidak ditemukan.'
        );
    }

    // Ambil seluruh objek schema dari backup.
    $candidateSchema = $candidate->query(
        "SELECT type, name, tbl_name, sql
         FROM sqlite_master
         WHERE name NOT LIKE 'sqlite_%'
         ORDER BY type, name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $candidate = null;

    if (!is_file($schemaPath)) {
        throw new RuntimeException(
            'Schema resmi FinTrack tidak ditemukan.'
        );
    }

    $canonicalPath = $path . '.schema-check';

    try {
        $canonical = new PDO('sqlite:' . $canonicalPath);
        $canonical->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $schemaSql = file_get_contents($schemaPath);

        if ($schemaSql === false) {
            throw new RuntimeException('Schema FinTrack gagal dibaca.');
        }

        $canonical->exec($schemaSql);

        $canonicalSchema = $canonical->query(
            "SELECT type, name, tbl_name, sql
             FROM sqlite_master
             WHERE name NOT LIKE 'sqlite_%'
             ORDER BY type, name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $canonical = null;

        // Normalisasi whitespace pada definisi SQL.
        // Perbedaan indentasi tidak dianggap sebagai
        // perbedaan struktur database.
        $normalizeSchema = static function (
            array $objects
        ): array {
            $normalized = [];

            foreach ($objects as $object) {
                $sql = $object['sql'];

                if ($sql !== null) {
                    $sql = trim(
                        preg_replace('/\s+/', ' ', $sql)
                    );
                }

                $normalized[] = [
                    'type' => $object['type'],
                    'name' => $object['name'],
                    'tbl_name' => $object['tbl_name'],
                    'sql' => $sql,
                ];
            }

            return $normalized;
        };

        $candidateNormalized = $normalizeSchema(
            $candidateSchema
        );

        $canonicalNormalized = $normalizeSchema(
            $canonicalSchema
        );

        // Bandingkan struktur setelah normalisasi.
        if ($candidateNormalized !== $canonicalNormalized) {
            throw new RuntimeException(
                'Struktur database tidak sesuai dengan schema FinTrack.'
            );
        }

    } finally {
        if (isset($canonical)) {
            $canonical = null;
        }

        if (is_file($canonicalPath)) {
            unlink($canonicalPath);
        }

        if (is_file($canonicalPath . '-journal')) {
            unlink($canonicalPath . '-journal');
        }
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Metode request tidak diizinkan.');
    }

    verifyCsrfToken();

    if (
        !isset($_FILES['backup_file']) ||
        $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException('File backup gagal diunggah.');
    }

    $uploadedFile = $_FILES['backup_file'];

    // Batasi ukuran file backup maksimal 100 MB.
    $maxFileSize = 100 * 1024 * 1024;

    if (
        !is_string($uploadedFile['name']) ||
        strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION)) !== 'sqlite'
    ) {
        throw new RuntimeException('Hanya file backup FinTrack berekstensi .sqlite yang diterima.');
    }

    if (
        !is_uploaded_file($uploadedFile['tmp_name']) ||
        $uploadedFile['size'] <= 0 ||
        $uploadedFile['size'] > $maxFileSize
    ) {
        throw new RuntimeException('Ukuran atau sumber file tidak valid.');
    }

    foreach ([$restoreDirectory, $backupDirectory] as $directory) {
        if (
            !is_dir($directory) &&
            !mkdir($directory, 0700, true) &&
            !is_dir($directory)
        ) {
            throw new RuntimeException('Gagal membuat direktori penyimpanan.');
        }
    }

    $token = bin2hex(random_bytes(8));

    $uploadPath = $restoreDirectory
        . DIRECTORY_SEPARATOR
        . 'upload_' . $token . '.sqlite';

    if (!move_uploaded_file($uploadedFile['tmp_name'], $uploadPath)) {
        throw new RuntimeException('File backup gagal dipindahkan.');
    }

    // Validasi file sebelum menyentuh database aktif.
    validateFinTrackDatabase($uploadPath, $schemaPath);

    if (!is_file($databasePath)) {
        throw new RuntimeException('Database utama FinTrack tidak ditemukan.');
    }

    // Buat backup otomatis database aktif.
    $automaticBackup = $backupDirectory
        . DIRECTORY_SEPARATOR
        . 'fintrack_before_restore_'
        . date('Ymd_His')
        . '_' . $token
        . '.sqlite';

    $activePdo = new PDO('sqlite:' . $databasePath);
    $activePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $activePdo->exec('PRAGMA busy_timeout = 5000');

    $activePdo->exec(
        'VACUUM INTO ' . $activePdo->quote($automaticBackup)
    );

    if (!is_file($automaticBackup) || filesize($automaticBackup) === 0) {
        throw new RuntimeException('Backup otomatis database aktif gagal dibuat.');
    }

    $activePdo = null;

    // Salin file valid ke direktori database agar penggantian
    // berlangsung pada volume yang sama.
    $replacementPath = $databasePath . '.replacement_' . $token;

    if (!copy($uploadPath, $replacementPath)) {
        throw new RuntimeException('Gagal menyiapkan database pengganti.');
    }

    $rollbackPath = $databasePath . '.rollback_' . $token;

    // Simpan database lama terlebih dahulu.
    if (!rename($databasePath, $rollbackPath)) {
        throw new RuntimeException('Database lama gagal diamankan.');
    }

    $oldDatabaseMoved = true;

    // Pasang database hasil restore.
    if (!rename($replacementPath, $databasePath)) {
        throw new RuntimeException('Database baru gagal dipasang.');
    }

    $replacementPath = null;
    $newDatabaseInstalled = true;

    // Validasi ulang database setelah dipasang.
    validateFinTrackDatabase($databasePath, $schemaPath);

    // Hapus database rollback setelah restore benar-benar berhasil.
    if (is_file($rollbackPath)) {
        unlink($rollbackPath);
    }

    $rollbackPath = null;
    $oldDatabaseMoved = false;

    header('Location: settings.php?restore=success');
    exit;

} catch (Throwable $e) {
    error_log('FinTrack Restore Error: ' . $e->getMessage());

    // Jika database baru gagal dipasang atau divalidasi,
    // kembalikan database lama.
    if ($oldDatabaseMoved && $rollbackPath !== null) {
        if ($newDatabaseInstalled && is_file($databasePath)) {
            unlink($databasePath);
        }

        if (is_file($rollbackPath)) {
            if (rename($rollbackPath, $databasePath)) {
                $oldDatabaseMoved = false;
                $newDatabaseInstalled = false;
            } else {
                error_log('FinTrack: pemulihan database lama gagal.');
            }
        }
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">';
    echo '<title>Restore Gagal | FinTrack</title></head><body>';
    echo '<h2>Restore database gagal</h2>';
    echo '<p>File backup ditolak atau proses pemulihan mengalami kegagalan.</p>';
    echo '<p>Periksa log PHP untuk informasi teknis.</p>';
    echo '<a href="settings.php">Kembali ke Pengaturan</a>';
    echo '</body></html>';

} finally {
    if ($uploadPath !== null && is_file($uploadPath)) {
        unlink($uploadPath);
    }

    if ($replacementPath !== null && is_file($replacementPath)) {
        unlink($replacementPath);
    }

    if (!$oldDatabaseMoved && $rollbackPath !== null && is_file($rollbackPath)) {
        unlink($rollbackPath);
    }
}
