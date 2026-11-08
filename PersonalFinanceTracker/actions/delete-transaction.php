
<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metode permintaan tidak diizinkan.');
}

verifyCsrfToken();

$idRaw = $_POST['id'] ?? '';

if (!is_string($idRaw)) {
    redirectToTransactions('invalid');
}

$id = filter_var($idRaw, FILTER_VALIDATE_INT);

if ($id === false || $id < 1) {
    redirectToTransactions('invalid');
}

try {
    $pdo = getPDO();

    $deleteStmt = $pdo->prepare(
        'DELETE FROM transactions WHERE id = :id'
    );

    $deleteStmt->execute([
        ':id' => $id,
    ]);

    if ($deleteStmt->rowCount() === 0) {
        redirectToTransactions('not_found');
    }

} catch (Throwable $e) {
    error_log('Gagal menghapus transaksi: ' . $e->getMessage());
    redirectToTransactions('error');
}

redirectToTransactions('deleted');
