
<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

$pdo = getPDO();

$today = date('Y-m-d');

$settings = $pdo->query(
    'SELECT opening_balance_date
     FROM app_settings
     WHERE id = 1'
)->fetch();

if (!$settings) {
    http_response_code(500);
    exit('Pengaturan aplikasi tidak ditemukan.');
}

$openingDate = (string) $settings['opening_balance_date'];

$fromRaw = $_GET['from'] ?? '';
$toRaw = $_GET['to'] ?? '';

if (
    !is_string($fromRaw) ||
    !is_string($toRaw) ||
    !isValidDate($fromRaw) ||
    !isValidDate($toRaw)
) {
    http_response_code(400);
    exit('Format tanggal laporan tidak valid.');
}

if (
    $fromRaw > $today ||
    $toRaw < $openingDate ||
    $fromRaw > $toRaw
) {
    http_response_code(400);
    exit('Rentang tanggal laporan tidak valid.');
}

$from = max($fromRaw, $openingDate);
$to = min($toRaw, $today);

if ($from > $to) {
    http_response_code(400);
    exit('Tidak ada periode laporan yang valid.');
}

/*
|--------------------------------------------------------------------------
| Ambil seluruh transaksi sesuai filter tanggal
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    "SELECT
        t.id,
        t.transaction_date,
        t.type,
        c.name AS category_name,
        t.description,
        t.payment_method,
        t.amount

     FROM transactions t

     INNER JOIN categories c
        ON c.id = t.category_id

     WHERE t.transaction_date >= :from_date
       AND t.transaction_date <= :to_date

     ORDER BY t.transaction_date ASC, t.id ASC"
);

$stmt->execute([
    ':from_date' => $from,
    ':to_date' => $to,
]);

$transactions = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Lindungi teks CSV dari formula injection
|--------------------------------------------------------------------------
*/

function safeCsvText(string $value): string
{
    if (preg_match('/^[\s]*[=+\-@]/u', $value)) {
        return "'" . $value;
    }

    return $value;
}

/*
|--------------------------------------------------------------------------
| Konversi label
|--------------------------------------------------------------------------
*/

$paymentMethods = [
    'cash' => 'Tunai',
    'transfer' => 'Transfer Bank',
    'e-wallet' => 'E-wallet',
    'debit-card' => 'Kartu Debit',
    'credit-card' => 'Kartu Kredit',
];

/*
|--------------------------------------------------------------------------
| Persiapan unduhan
|--------------------------------------------------------------------------
*/

$filename = 'laporan-keuangan-'
    . str_replace('-', '', $from)
    . '-'
    . str_replace('-', '', $to)
    . '.csv';

header('Content-Type: text/csv; charset=UTF-8');

header(
    'Content-Disposition: attachment; filename="'
    . $filename
    . '"'
);

header('Cache-Control: no-store, no-cache, must-revalidate');

$output = fopen('php://output', 'wb');

if ($output === false) {
    http_response_code(500);
    exit('Gagal membuat file laporan.');
}

// BOM UTF-8 agar karakter Indonesia terbaca dengan baik di Excel.
fwrite($output, "\xEF\xBB\xBF");

fputcsv(
    $output,
    [
        'ID Transaksi',
        'Tanggal',
        'Jenis Transaksi',
        'Kategori',
        'Deskripsi',
        'Metode Pembayaran',
        'Nominal (Rupiah)',
    ],
    ',',
    '"',
    ''
);

/*
|--------------------------------------------------------------------------
| Tulis seluruh transaksi
|--------------------------------------------------------------------------
*/

foreach ($transactions as $transaction) {

    $typeLabel = $transaction['type'] === 'income'
        ? 'Pemasukan'
        : 'Pengeluaran';

    $paymentLabel = $paymentMethods[
        $transaction['payment_method']
    ] ?? $transaction['payment_method'];

    fputcsv(
        $output,
        [
            (int) $transaction['id'],
            $transaction['transaction_date'],
            $typeLabel,
            safeCsvText((string) $transaction['category_name']),
            safeCsvText((string) $transaction['description']),
            safeCsvText((string) $paymentLabel),
            (int) $transaction['amount'],
        ],
        ',',
        '"',
        ''
    );
}

fclose($output);
exit;
