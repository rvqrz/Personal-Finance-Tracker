
<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metode permintaan tidak diizinkan.');
}

verifyCsrfToken();

$idRaw = $_POST['id'] ?? '';
$type = $_POST['type'] ?? '';
$categoryRaw = $_POST['category_id'] ?? '';
$amountRaw = $_POST['amount'] ?? '';
$date = $_POST['transaction_date'] ?? '';
$description = $_POST['description'] ?? '';
$paymentMethod = $_POST['payment_method'] ?? '';

if (
    !is_string($idRaw) ||
    !is_string($type) ||
    !is_string($categoryRaw) ||
    !is_string($amountRaw) ||
    !is_string($date) ||
    !is_string($description) ||
    !is_string($paymentMethod)
) {
    redirectToTransactions('invalid');
}

$id = filter_var($idRaw, FILTER_VALIDATE_INT);
$categoryId = filter_var($categoryRaw, FILTER_VALIDATE_INT);
$amount = filter_var($amountRaw, FILTER_VALIDATE_INT);

$description = trim($description);

$allowedTypes = ['income', 'expense'];

$allowedPaymentMethods = [
    'cash',
    'transfer',
    'e-wallet',
    'debit-card',
    'credit-card',
];

if (
    $id === false ||
    $id < 1 ||
    !in_array($type, $allowedTypes, true) ||
    $categoryId === false ||
    $categoryId < 1 ||
    $amount === false ||
    $amount < 1 ||
    $amount > 9000000000000000 ||
    !isValidDate($date) ||
    $description === '' ||
    strlen($description) > 200 ||
    !in_array($paymentMethod, $allowedPaymentMethods, true)
) {
    redirectToTransactions('invalid');
}

try {
    $pdo = getPDO();

    $openingDate = $pdo->query(
    'SELECT opening_balance_date
     FROM app_settings
     WHERE id = 1'
)->fetchColumn();

if (
    !is_string($openingDate) ||
    $date < $openingDate ||
    $date > date('Y-m-d')
) {
    redirectToTransactions('invalid');
}

    // Pastikan transaksi yang diedit benar-benar ada.
    $existingStmt = $pdo->prepare(
        'SELECT id FROM transactions WHERE id = :id'
    );

    $existingStmt->execute([
        ':id' => $id,
    ]);

    if ($existingStmt->fetchColumn() === false) {
        redirectToTransactions('not_found');
    }

    // Pastikan kategori aktif dan sesuai dengan jenis transaksi.
    $categoryStmt = $pdo->prepare(
        'SELECT type
         FROM categories
         WHERE id = :id
           AND is_active = 1'
    );

    $categoryStmt->execute([
        ':id' => $categoryId,
    ]);

    $categoryType = $categoryStmt->fetchColumn();

    if ($categoryType === false || $categoryType !== $type) {
        redirectToTransactions('category_mismatch');
    }

    $updateStmt = $pdo->prepare(
        'UPDATE transactions
         SET
            category_id = :category_id,
            type = :type,
            amount = :amount,
            description = :description,
            transaction_date = :transaction_date,
            payment_method = :payment_method,
            updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );

    $updateStmt->execute([
        ':category_id' => $categoryId,
        ':type' => $type,
        ':amount' => $amount,
        ':description' => $description,
        ':transaction_date' => $date,
        ':payment_method' => $paymentMethod,
        ':id' => $id,
    ]);

} catch (Throwable $e) {
    error_log('Gagal memperbarui transaksi: ' . $e->getMessage());
    redirectToTransactions('error');
}

redirectToTransactions('updated');
