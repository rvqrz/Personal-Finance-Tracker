
<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metode permintaan tidak diizinkan.');
}

verifyCsrfToken();

$type = $_POST['type'] ?? '';
$categoryRaw = $_POST['category_id'] ?? '';
$amountRaw = $_POST['amount'] ?? '';
$date = $_POST['transaction_date'] ?? '';
$description = $_POST['description'] ?? '';
$paymentMethod = $_POST['payment_method'] ?? '';

if (
    !is_string($type) ||
    !is_string($categoryRaw) ||
    !is_string($amountRaw) ||
    !is_string($date) ||
    !is_string($description) ||
    !is_string($paymentMethod)
) {
    redirectToTransactions('invalid');
}

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

    // Pastikan kategori aktif dan jenisnya cocok dengan transaksi.
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

    // Simpan nominal sebagai bilangan positif.
    $insertStmt = $pdo->prepare(
        'INSERT INTO transactions (
            category_id,
            type,
            amount,
            description,
            transaction_date,
            payment_method
        ) VALUES (
            :category_id,
            :type,
            :amount,
            :description,
            :transaction_date,
            :payment_method
        )'
    );

    $insertStmt->execute([
        ':category_id' => $categoryId,
        ':type' => $type,
        ':amount' => $amount,
        ':description' => $description,
        ':transaction_date' => $date,
        ':payment_method' => $paymentMethod,
    ]);

} catch (Throwable $e) {
    error_log('Gagal menyimpan transaksi: ' . $e->getMessage());
    redirectToTransactions('error');
}

redirectToTransactions('saved');
