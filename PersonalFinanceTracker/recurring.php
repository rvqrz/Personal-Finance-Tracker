
<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pdo = getPDO();

$today = date('Y-m-d');

/*
|--------------------------------------------------------------------------
| Pengaturan aplikasi
|--------------------------------------------------------------------------
*/

$settings = $pdo->query(
    'SELECT opening_balance_date
     FROM app_settings
     WHERE id = 1'
)->fetch();

if (!$settings) {
    throw new RuntimeException('Pengaturan aplikasi tidak ditemukan.');
}

$openingDate = (string) $settings['opening_balance_date'];

/*
|--------------------------------------------------------------------------
| Fungsi redirect
|--------------------------------------------------------------------------
*/

function redirectToRecurring(string $status): void
{
    header(
        'Location: recurring.php?status=' . rawurlencode($status)
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| Membuat occurrence untuk jadwal yang sudah jatuh tempo
|--------------------------------------------------------------------------
|
| Dipanggil ketika halaman Recurring Transactions dibuka.
| Tidak membuat transaksi keuangan secara otomatis.
|
*/

function generateDueOccurrences(PDO $pdo, string $today): void
{
    $pdo->beginTransaction();

    try {
        $scheduleStmt = $pdo->query(
            "SELECT
                id,
                frequency,
                next_due_date,
                end_date,
                anchor_day,
                anchor_month

             FROM recurring_transactions

             WHERE is_active = 1

             ORDER BY id"
        );

        $schedules = $scheduleStmt->fetchAll();

        $insertOccurrence = $pdo->prepare(
            "INSERT OR IGNORE INTO recurring_occurrences (
                recurring_id,
                scheduled_date,
                status
            ) VALUES (
                :recurring_id,
                :scheduled_date,
                'pending'
            )"
        );

        $updateSchedule = $pdo->prepare(
            'UPDATE recurring_transactions
             SET
                next_due_date = :next_due_date,
                is_active = :is_active,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        foreach ($schedules as $schedule) {

            $nextDueDate = $schedule['next_due_date'];
            $endDate = $schedule['end_date'];

            $generatedCount = 0;

            // Batasi 500 occurrence per jadwal dalam satu proses.
            while (
                $nextDueDate <= $today &&
                ($endDate === null || $nextDueDate <= $endDate) &&
                $generatedCount < 500
            ) {

                $insertOccurrence->execute([
                    ':recurring_id' => $schedule['id'],
                    ':scheduled_date' => $nextDueDate,
                ]);

                $nextDueDate = calculateNextRecurringDate(
                    $nextDueDate,
                    $schedule['frequency'],
                    (int) $schedule['anchor_day'],
                    (int) $schedule['anchor_month']
                );

                $generatedCount++;
            }

            $completed = (
                $endDate !== null &&
                $nextDueDate > $endDate
            );

            $updateSchedule->execute([
                ':next_due_date' => $nextDueDate,
                ':is_active' => $completed ? 0 : 1,
                ':id' => $schedule['id'],
            ]);
        }

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/*
|--------------------------------------------------------------------------
| Pembangkitan jadwal jatuh tempo
|--------------------------------------------------------------------------
*/

$generationError = false;

try {
    generateDueOccurrences($pdo, $today);
} catch (Throwable $e) {
    error_log(
        'Gagal memproses jadwal berulang: ' . $e->getMessage()
    );

    $generationError = true;
}

/*
|--------------------------------------------------------------------------
| Notifikasi
|--------------------------------------------------------------------------
*/

$statusMessages = [
    'saved' => ['success', 'Jadwal transaksi berhasil dibuat.'],
    'updated' => ['success', 'Jadwal transaksi berhasil diperbarui.'],
    'confirmed' => ['success', 'Transaksi berhasil dikonfirmasi dan dicatat.'],
    'skipped' => ['success', 'Jadwal berhasil dilewati.'],
    'activated' => ['success', 'Jadwal berhasil diaktifkan kembali.'],
    'deactivated' => ['success', 'Jadwal berhasil dinonaktifkan.'],
    'duplicate' => ['error', 'Jadwal transaksi duplikat tidak diizinkan.'],
    'invalid' => ['error', 'Data tidak valid. Periksa kembali formulir.'],
    'not_found' => ['error', 'Data transaksi atau jadwal tidak ditemukan.'],
    'pending' => ['error', 'Jadwal sudah diproses atau tidak lagi menunggu.'],
    'category_mismatch' => ['error', 'Kategori tidak sesuai dengan jenis transaksi.'],
    'category_inactive' => [
        'error',
        'Kategori sudah nonaktif. Aktifkan kategori terlebih dahulu.'
    ],
    'completed' => [
        'error',
        'Jadwal telah mencapai tanggal berakhir dan tidak dapat diaktifkan kembali.'
    ],
    'error' => ['error', 'Operasi gagal. Periksa log PHP.'],
];

$status = $_GET['status'] ?? '';
$notice = null;

if (is_string($status) && isset($statusMessages[$status])) {
    $notice = $statusMessages[$status];
}

if ($generationError) {
    $notice = [
        'error',
        'Sebagian jadwal gagal diproses. Periksa log PHP.'
    ];
}

/*
|--------------------------------------------------------------------------
| Proses POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verifyCsrfToken();

    $action = $_POST['action'] ?? '';

    if (!is_string($action)) {
        redirectToRecurring('invalid');
    }

    /*
    |--------------------------------------------------------------------------
    | Tambah atau edit jadwal
    |--------------------------------------------------------------------------
    */

    if ($action === 'save') {

        $idRaw = $_POST['id'] ?? '';
        $nameRaw = $_POST['name'] ?? '';
        $categoryRaw = $_POST['category_id'] ?? '';
        $amountRaw = $_POST['amount'] ?? '';

        if (
            !is_string($idRaw) ||
            !is_string($nameRaw) ||
            !is_string($categoryRaw) ||
            !is_string($amountRaw)
        ) {
            redirectToRecurring('invalid');
        }

        $name = trim($nameRaw);
        $categoryId = filter_var(
            $categoryRaw,
            FILTER_VALIDATE_INT
        );

        $amount = filter_var(
            $amountRaw,
            FILTER_VALIDATE_INT
        );

        $nameLength = function_exists('mb_strlen')
            ? mb_strlen($name, 'UTF-8')
            : strlen($name);

        if (
            $name === '' ||
            $nameLength > 100 ||
            $categoryId === false ||
            $categoryId < 1 ||
            $amount === false ||
            $amount < 1 ||
            $amount > 9000000000000000
        ) {
            redirectToRecurring('invalid');
        }

        try {

            if ($idRaw === '') {

                /*
                |--------------------------------------------------------------------------
                | Pembuatan jadwal baru
                |--------------------------------------------------------------------------
                */

                $type = $_POST['type'] ?? '';
                $frequency = $_POST['frequency'] ?? '';
                $startDate = $_POST['start_date'] ?? '';
                $endDateRaw = $_POST['end_date'] ?? '';

                if (
                    !is_string($type) ||
                    !is_string($frequency) ||
                    !is_string($startDate) ||
                    !is_string($endDateRaw)
                ) {
                    redirectToRecurring('invalid');
                }

                $allowedTypes = ['income', 'expense'];

                $allowedFrequencies = [
                    'daily',
                    'weekly',
                    'monthly',
                    'yearly',
                ];

                $endDate = trim($endDateRaw);

                if (
                    !in_array($type, $allowedTypes, true) ||
                    !in_array($frequency, $allowedFrequencies, true) ||
                    !isValidDate($startDate) ||
                    $startDate < $openingDate ||
                    ($endDate !== '' && (
                        !isValidDate($endDate) ||
                        $endDate < $startDate
                    ))
                ) {
                    redirectToRecurring('invalid');
                }

                // Pastikan kategori aktif dan jenisnya sesuai.
                $categoryStmt = $pdo->prepare(
                    'SELECT id
                     FROM categories
                     WHERE id = :id
                       AND type = :type
                       AND is_active = 1'
                );

                $categoryStmt->execute([
                    ':id' => $categoryId,
                    ':type' => $type,
                ]);

                if ($categoryStmt->fetchColumn() === false) {
                    redirectToRecurring('category_mismatch');
                }

                $startDateObject = new DateTimeImmutable($startDate);

                $insertStmt = $pdo->prepare(
                    'INSERT INTO recurring_transactions (
                        category_id,
                        type,
                        name,
                        amount,
                        frequency,
                        start_date,
                        end_date,
                        next_due_date,
                        anchor_day,
                        anchor_month,
                        is_active
                    ) VALUES (
                        :category_id,
                        :type,
                        :name,
                        :amount,
                        :frequency,
                        :start_date,
                        :end_date,
                        :next_due_date,
                        :anchor_day,
                        :anchor_month,
                        1
                    )'
                );

                $insertStmt->execute([
                    ':category_id' => $categoryId,
                    ':type' => $type,
                    ':name' => $name,
                    ':amount' => $amount,
                    ':frequency' => $frequency,
                    ':start_date' => $startDate,
                    ':end_date' => $endDate === '' ? null : $endDate,
                    ':next_due_date' => $startDate,
                    ':anchor_day' => (int) $startDateObject->format('j'),
                    ':anchor_month' => (int) $startDateObject->format('n'),
                ]);

                redirectToRecurring('saved');

            } else {

                /*
                |--------------------------------------------------------------------------
                | Pengubahan data jadwal
                |--------------------------------------------------------------------------
                |
                | Frekuensi dan tanggal jadwal tidak diubah di sini.
                | Untuk mengubah pola, nonaktifkan jadwal lama dan
                | buat jadwal baru agar riwayat lama tetap konsisten.
                |
                */

                $id = filter_var(
                    $idRaw,
                    FILTER_VALIDATE_INT
                );

                if ($id === false || $id < 1) {
                    redirectToRecurring('invalid');
                }

                $existingStmt = $pdo->prepare(
                    'SELECT id, type
                     FROM recurring_transactions
                     WHERE id = :id'
                );

                $existingStmt->execute([':id' => $id]);
                $existing = $existingStmt->fetch();

                if (!$existing) {
                    redirectToRecurring('not_found');
                }

                $categoryStmt = $pdo->prepare(
                    'SELECT id
                     FROM categories
                     WHERE id = :id
                       AND type = :type
                       AND is_active = 1'
                );

                $categoryStmt->execute([
                    ':id' => $categoryId,
                    ':type' => $existing['type'],
                ]);

                if ($categoryStmt->fetchColumn() === false) {
                    redirectToRecurring('category_mismatch');
                }

                $updateStmt = $pdo->prepare(
                    'UPDATE recurring_transactions
                     SET
                        category_id = :category_id,
                        name = :name,
                        amount = :amount,
                        updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );

                $updateStmt->execute([
                    ':category_id' => $categoryId,
                    ':name' => $name,
                    ':amount' => $amount,
                    ':id' => $id,
                ]);

                redirectToRecurring('updated');
            }

        } catch (Throwable $e) {
            error_log('Gagal menyimpan jadwal: ' . $e->getMessage());
            redirectToRecurring('error');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Konfirmasi transaksi
    |--------------------------------------------------------------------------
    */

    if ($action === 'confirm') {

        $occurrenceRaw = $_POST['occurrence_id'] ?? '';
        $amountRaw = $_POST['actual_amount'] ?? '';
        $dateRaw = $_POST['actual_date'] ?? '';
        $paymentRaw = $_POST['payment_method'] ?? '';

        if (
            !is_string($occurrenceRaw) ||
            !is_string($amountRaw) ||
            !is_string($dateRaw) ||
            !is_string($paymentRaw)
        ) {
            redirectToRecurring('invalid');
        }

        $occurrenceId = filter_var(
            $occurrenceRaw,
            FILTER_VALIDATE_INT
        );

        $actualAmount = filter_var(
            $amountRaw,
            FILTER_VALIDATE_INT
        );

        $allowedPayments = [
            'cash',
            'transfer',
            'e-wallet',
            'debit-card',
            'credit-card',
        ];

        if (
            $occurrenceId === false ||
            $occurrenceId < 1 ||
            $actualAmount === false ||
            $actualAmount < 1 ||
            $actualAmount > 9000000000000000 ||
            !isValidDate($dateRaw) ||
            $dateRaw < $openingDate ||
            $dateRaw > $today ||
            !in_array($paymentRaw, $allowedPayments, true)
        ) {
            redirectToRecurring('invalid');
        }

        try {

            $pdo->beginTransaction();

            // Ambil data kejadian dan template jadwal.
            $occurrenceStmt = $pdo->prepare(
                "SELECT
                    o.id,
                    o.status,
                    r.category_id,
                    r.type,
                    r.name,
                    c.is_active AS category_active,
                    c.type AS category_type

                 FROM recurring_occurrences o

                 INNER JOIN recurring_transactions r
                    ON r.id = o.recurring_id

                 INNER JOIN categories c
                    ON c.id = r.category_id

                 WHERE o.id = :id"
            );

            $occurrenceStmt->execute([
                ':id' => $occurrenceId,
            ]);

            $occurrence = $occurrenceStmt->fetch();

            if (!$occurrence) {
                $pdo->rollBack();
                redirectToRecurring('not_found');
            }

            if ($occurrence['status'] !== 'pending') {
                $pdo->rollBack();
                redirectToRecurring('pending');
            }

            if (
                (int) $occurrence['category_active'] !== 1
            ) {
                $pdo->rollBack();
                redirectToRecurring('category_inactive');
            }

            if ($occurrence['category_type'] !== $occurrence['type']) {
                $pdo->rollBack();
                redirectToRecurring('category_mismatch');
            }

            // Buat transaksi aktual dengan nominal yang telah dikonfirmasi.
            $insertTransaction = $pdo->prepare(
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

            $insertTransaction->execute([
                ':category_id' => $occurrence['category_id'],
                ':type' => $occurrence['type'],
                ':amount' => $actualAmount,
                ':description' => $occurrence['name'],
                ':transaction_date' => $dateRaw,
                ':payment_method' => $paymentRaw,
            ]);

            $transactionId = (int) $pdo->lastInsertId();

            // Tandai kejadian sebagai sudah dicatat.
            $updateOccurrence = $pdo->prepare(
                "UPDATE recurring_occurrences
                 SET
                    status = 'recorded',
                    transaction_id = :transaction_id,
                    processed_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND status = 'pending'"
            );

            $updateOccurrence->execute([
                ':transaction_id' => $transactionId,
                ':id' => $occurrenceId,
            ]);

            // Pastikan hanya satu proses berhasil mengonfirmasi.
            if ($updateOccurrence->rowCount() !== 1) {
                throw new RuntimeException(
                    'Jadwal sudah diproses oleh permintaan lain.'
                );
            }

            $pdo->commit();

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Gagal mengonfirmasi transaksi: ' . $e->getMessage());

            redirectToRecurring('error');
        }

        redirectToRecurring('confirmed');
    }

    /*
    |--------------------------------------------------------------------------
    | Lewati jadwal
    |--------------------------------------------------------------------------
    */

    if ($action === 'skip') {

        $idRaw = $_POST['occurrence_id'] ?? '';

        if (!is_string($idRaw)) {
            redirectToRecurring('invalid');
        }

        $id = filter_var($idRaw, FILTER_VALIDATE_INT);

        if ($id === false || $id < 1) {
            redirectToRecurring('invalid');
        }

        try {

            $stmt = $pdo->prepare(
                "UPDATE recurring_occurrences
                 SET
                    status = 'skipped',
                    processed_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND status = 'pending'"
            );

            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() !== 1) {
                redirectToRecurring('pending');
            }

        } catch (Throwable $e) {
            error_log('Gagal melewati jadwal: ' . $e->getMessage());
            redirectToRecurring('error');
        }

        redirectToRecurring('skipped');
    }

    /*
    |--------------------------------------------------------------------------
    | Aktifkan atau nonaktifkan jadwal
    |--------------------------------------------------------------------------
    */

    if ($action === 'toggle') {

        $idRaw = $_POST['id'] ?? '';
        $targetRaw = $_POST['target_status'] ?? '';

        if (
            !is_string($idRaw) ||
            !is_string($targetRaw) ||
            !in_array($targetRaw, ['0', '1'], true)
        ) {
            redirectToRecurring('invalid');
        }

        $id = filter_var($idRaw, FILTER_VALIDATE_INT);

        if ($id === false || $id < 1) {
            redirectToRecurring('invalid');
        }

        $targetStatus = (int) $targetRaw;

        try {

            $checkStmt = $pdo->prepare(
                'SELECT id, end_date, next_due_date
                 FROM recurring_transactions
                 WHERE id = :id'
            );

            $checkStmt->execute([':id' => $id]);
            $schedule = $checkStmt->fetch();

            if (!$schedule) {
                redirectToRecurring('not_found');
            }

            // Jadwal yang masa berlakunya selesai tidak bisa diaktifkan kembali.
            if (
                $targetStatus === 1 &&
                $schedule['end_date'] !== null &&
                $schedule['next_due_date'] > $schedule['end_date']
            ) {
                redirectToRecurring('completed');
            }

            $updateStmt = $pdo->prepare(
                'UPDATE recurring_transactions
                 SET
                    is_active = :status,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );

            $updateStmt->execute([
                ':status' => $targetStatus,
                ':id' => $id,
            ]);

        } catch (Throwable $e) {
            error_log('Gagal mengubah status jadwal: ' . $e->getMessage());
            redirectToRecurring('error');
        }

        redirectToRecurring(
            $targetStatus === 1 ? 'activated' : 'deactivated'
        );
    }

    redirectToRecurring('invalid');
}

/*
|--------------------------------------------------------------------------
| Ambil jadwal yang sedang diedit
|--------------------------------------------------------------------------
*/

$editingSchedule = null;

if (isset($_GET['edit'])) {

    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($editId === false || $editId < 1) {
        $notice = ['error', 'ID jadwal tidak valid.'];
    } else {

        $editStmt = $pdo->prepare(
            'SELECT *
             FROM recurring_transactions
             WHERE id = :id'
        );

        $editStmt->execute([':id' => $editId]);
        $editingSchedule = $editStmt->fetch();

        if (!$editingSchedule) {
            $notice = ['error', 'Jadwal tidak ditemukan.'];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Ambil kategori aktif untuk formulir
|--------------------------------------------------------------------------
*/

$categorySql = '
    SELECT id, name, type
    FROM categories
    WHERE is_active = 1
';

$categoryParams = [];

if ($editingSchedule) {
    $categorySql .= ' AND type = :type';
    $categoryParams[':type'] = $editingSchedule['type'];
}

$categorySql .= ' ORDER BY name';

$categoryStmt = $pdo->prepare($categorySql);
$categoryStmt->execute($categoryParams);
$categories = $categoryStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Ringkasan jadwal
|--------------------------------------------------------------------------
*/

$activeCount = (int) $pdo->query(
    'SELECT COUNT(*)
     FROM recurring_transactions
     WHERE is_active = 1'
)->fetchColumn();

$pendingCount = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM recurring_occurrences
     WHERE status = 'pending'"
)->fetchColumn();

$recordedStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM recurring_occurrences
     WHERE status = 'recorded'
       AND substr(scheduled_date, 1, 7) = :month"
);

$recordedStmt->execute([
    ':month' => date('Y-m'),
]);

$recordedThisMonth = (int) $recordedStmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| Daftar transaksi menunggu konfirmasi
|--------------------------------------------------------------------------
*/

$pendingStmt = $pdo->query(
    "SELECT
        o.id AS occurrence_id,
        o.scheduled_date,
        r.name,
        r.type,
        r.amount,
        r.frequency,
        c.name AS category_name,
        c.is_active AS category_active

     FROM recurring_occurrences o

     INNER JOIN recurring_transactions r
        ON r.id = o.recurring_id

     INNER JOIN categories c
        ON c.id = r.category_id

     WHERE o.status = 'pending'

     ORDER BY o.scheduled_date ASC, o.id ASC
     LIMIT 100"
);

$pendingOccurrences = $pendingStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Daftar template jadwal
|--------------------------------------------------------------------------
*/

$scheduleStmt = $pdo->query(
    'SELECT
        r.id,
        r.name,
        r.type,
        r.amount,
        r.frequency,
        r.start_date,
        r.end_date,
        r.next_due_date,
        r.is_active,
        c.name AS category_name

     FROM recurring_transactions r

     INNER JOIN categories c
        ON c.id = r.category_id

     ORDER BY
        CASE WHEN r.is_active = 1 THEN 0 ELSE 1 END,
        r.next_due_date ASC'
);

$schedules = $scheduleStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Riwayat occurrence terakhir
|--------------------------------------------------------------------------
*/

$historyStmt = $pdo->query(
    "SELECT
        o.id,
        o.scheduled_date,
        o.status,
        o.processed_at,
        o.transaction_id,
        r.name,
        r.type,
        t.amount AS actual_amount,
        t.transaction_date AS actual_date

     FROM recurring_occurrences o

     INNER JOIN recurring_transactions r
        ON r.id = o.recurring_id

     LEFT JOIN transactions t
        ON t.id = o.transaction_id

     WHERE o.status IN ('recorded', 'skipped')

     ORDER BY o.processed_at DESC, o.id DESC
     LIMIT 30"
);

$historyRows = $historyStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Data tampilan formulir
|--------------------------------------------------------------------------
*/

$formId = (string) ($editingSchedule['id'] ?? '');
$formName = (string) ($editingSchedule['name'] ?? '');
$formCategoryId = (string) ($editingSchedule['category_id'] ?? '');
$formType = (string) ($editingSchedule['type'] ?? 'expense');
$formAmount = (string) ($editingSchedule['amount'] ?? '');
$formFrequency = (string) ($editingSchedule['frequency'] ?? 'monthly');
$formStartDate = (string) ($editingSchedule['start_date'] ?? $today);
$formEndDate = (string) ($editingSchedule['end_date'] ?? '');

$frequencyLabels = [
    'daily' => 'Harian',
    'weekly' => 'Mingguan',
    'monthly' => 'Bulanan',
    'yearly' => 'Tahunan',
];

$paymentMethods = [
    'cash' => 'Tunai',
    'transfer' => 'Transfer bank',
    'e-wallet' => 'E-wallet',
    'debit-card' => 'Kartu debit',
    'credit-card' => 'Kartu kredit',
];

$csrf = csrfToken();

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaksi Berulang | FinTrack</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="app-layout">

    <aside class="sidebar">

        <a href="index.php" class="brand">
            <span class="brand-icon">F</span>
            <span>
                <strong>FinTrack</strong>
                <small>Personal Finance</small>
            </span>
        </a>

        <div class="nav-label">MENU UTAMA</div>

        <nav class="sidebar-nav">

            <a href="index.php" class="nav-item">
                <span>▦</span> Dashboard
            </a>

            <a href="transactions.php" class="nav-item">
                <span>↔</span> Transaksi
            </a>

            <a href="recurring.php" class="nav-item active">
                <span>⟳</span> Transaksi Berulang
            </a>

            <a href="budgets.php" class="nav-item">
                <span>◷</span> Anggaran
            </a>
            <a href="savings.php" class="nav-item">
                <span>◈</span> Tabungan
            </a>

            <a href="categories.php" class="nav-item">
                <span>▤</span> Kategori
            </a>

            <a href="reports.php" class="nav-item">
                <span>▥</span> Laporan
            </a>

            <a href="settings.php" class="nav-item">
                <span>⚙</span> Pengaturan
            </a>

        </nav>

        <div class="sidebar-footer">
            Personal Expense Tracker<br>
            <small>Versi pengembangan 1.0</small>
        </div>

    </aside>

    <main class="main-content">

        <header class="topbar">
            <div>
                <span class="breadcrumb">
                    FinTrack / Transaksi Berulang
                </span>
                <h1>Transaksi Berulang</h1>
            </div>

            <span class="local-badge">Lokal</span>
        </header>

        <?php if ($notice !== null): ?>
            <div class="alert alert-<?= e($notice[0]) ?>">
                <?= e($notice[1]) ?>
            </div>
        <?php endif; ?>

        <section class="page-intro">
            <h2>Kelola transaksi rutin</h2>
            <p>
                Buat jadwal pemasukan dan pengeluaran rutin.
                Transaksi hanya masuk ke saldo setelah dikonfirmasi.
            </p>
        </section>

        <div class="recurring-info">
            <strong>Mode konfirmasi manual</strong>
            <p>
                Jadwal yang jatuh tempo akan muncul sebagai transaksi
                menunggu konfirmasi. Nominal dan tanggal aktual bisa
                disesuaikan sebelum dicatat ke riwayat.
            </p>
        </div>

        <!-- RINGKASAN -->

        <section class="dashboard-stat-grid">

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Jadwal aktif</span>
                    <span class="stat-icon">⟳</span>
                </div>

                <div class="stat-value">
                    <?= e($activeCount) ?>
                </div>

                <div class="stat-note">
                    Template transaksi yang berjalan
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Menunggu konfirmasi</span>
                    <span class="stat-icon expense-icon">!</span>
                </div>

                <div class="stat-value">
                    <?= e($pendingCount) ?>
                </div>

                <div class="stat-note">
                    Jadwal yang belum diproses
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Dicatat bulan ini</span>
                    <span class="stat-icon income-icon">✓</span>
                </div>

                <div class="stat-value">
                    <?= e($recordedThisMonth) ?>
                </div>

                <div class="stat-note">
                    Berdasarkan tanggal jadwal
                </div>
            </article>

        </section>

        <!-- FORM TAMBAH / EDIT -->

        <section class="card">

            <div class="section-heading">
                <div>
                    <h2>
                        <?= $editingSchedule
                            ? 'Edit Jadwal Berulang'
                            : 'Buat Jadwal Berulang Baru' ?>
                    </h2>

                    <p>
                        Tentukan transaksi yang ingin dicatat secara rutin.
                    </p>
                </div>
            </div>

            <?php if (count($categories) === 0): ?>

                <div class="alert alert-error">
                    Belum ada kategori aktif yang tersedia.
                    Silakan aktifkan kategori terlebih dahulu.
                </div>

            <?php else: ?>

                <form method="POST" action="recurring.php">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="save"
                    >

                    <input
                        type="hidden"
                        name="id"
                        value="<?= e($formId) ?>"
                    >

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="name">Nama transaksi</label>

                            <input
                                type="text"
                                id="name"
                                name="name"
                                maxlength="100"
                                value="<?= e($formName) ?>"
                                placeholder="Contoh: Pembayaran Wi-Fi"
                                required
                            >
                        </div>

                        <?php if ($editingSchedule): ?>

                            <div class="form-group">
                                <label for="type">Jenis transaksi</label>

                                <input
                                    type="text"
                                    id="type"
                                    value="<?= $formType === 'income'
                                        ? 'Pemasukan'
                                        : 'Pengeluaran' ?>"
                                    disabled
                                >
                            </div>

                        <?php else: ?>

                            <div class="form-group">
                                <label for="type">Jenis transaksi</label>

                                <select
                                    id="type"
                                    name="type"
                                    required
                                >
                                    <option value="expense" selected>
                                        Pengeluaran
                                    </option>

                                    <option value="income">
                                        Pemasukan
                                    </option>
                                </select>
                            </div>

                        <?php endif; ?>

                        <div class="form-group">
                            <label for="category_id">Kategori</label>

                            <select
                                id="category_id"
                                name="category_id"
                                required
                            >
                                <option value="">Pilih kategori</option>

                                <?php foreach ($categories as $category): ?>

                                    <option
                                        value="<?= e($category['id']) ?>"
                                        data-type="<?= e($category['type']) ?>"
                                        <?= (string) $category['id'] === $formCategoryId
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= e($category['name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="form-group">
                            <label for="amount">Nominal (Rupiah)</label>

                            <div class="input-prefix">
                                <span>Rp</span>

                                <input
                                    type="number"
                                    id="amount"
                                    name="amount"
                                    min="1"
                                    max="9000000000000000"
                                    step="1"
                                    value="<?= e($formAmount) ?>"
                                    placeholder="Contoh: 250000"
                                    required
                                >
                            </div>
                        </div>

                        <?php if (!$editingSchedule): ?>

                            <div class="form-group">
                                <label for="frequency">Frekuensi pengulangan</label>

                                <select
                                    id="frequency"
                                    name="frequency"
                                    required
                                >
                                    <option value="daily">Setiap hari</option>
                                    <option value="weekly">Setiap minggu</option>
                                    <option value="monthly" selected>Setiap bulan</option>
                                    <option value="yearly">Setiap tahun</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="start_date">Tanggal mulai</label>

                                <input
                                    type="date"
                                    id="start_date"
                                    name="start_date"
                                    min="<?= e($openingDate) ?>"
                                    value="<?= e($formStartDate) ?>"
                                    required
                                >
                            </div>

                            <div class="form-group">
                                <label for="end_date">
                                    Tanggal berakhir (opsional)
                                </label>

                                <input
                                    type="date"
                                    id="end_date"
                                    name="end_date"
                                    min="<?= e($openingDate) ?>"
                                    value="<?= e($formEndDate) ?>"
                                >

                                <small class="field-help">
                                    Kosongkan apabila jadwal terus berjalan.
                                </small>
                            </div>

                        <?php else: ?>

                            <div class="form-group">
                                <label>Frekuensi</label>
                                <input
                                    type="text"
                                    value="<?= e($frequencyLabels[$formFrequency]) ?>"
                                    disabled
                                >
                            </div>

                            <div class="form-group">
                                <label>Tanggal mulai</label>
                                <input
                                    type="date"
                                    value="<?= e($formStartDate) ?>"
                                    disabled
                                >
                            </div>

                            <div class="form-group">
                                <label>Tanggal berakhir</label>
                                <input
                                    type="text"
                                    value="<?= e(
                                        $formEndDate !== ''
                                            ? $formEndDate
                                            : 'Tidak ada tanggal berakhir'
                                    ) ?>"
                                    disabled
                                >
                            </div>

                        <?php endif; ?>

                    </div>

                    <?php if ($editingSchedule): ?>
                        <div class="settings-note">
                            <strong>Pengaturan jadwal</strong>
                            <p>
                                Nama, kategori, dan nominal dapat diubah.
                                Untuk mengubah frekuensi atau tanggal jadwal,
                                nonaktifkan jadwal lama dan buat jadwal baru.
                                Riwayat kejadian sebelumnya tetap dipertahankan.
                            </p>
                        </div>
                    <?php endif; ?>

                    <div class="form-actions">

                        <?php if ($editingSchedule): ?>

                            <a href="recurring.php" class="btn btn-secondary">
                                Batal
                            </a>

                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary">
                            <?= $editingSchedule
                                ? 'Simpan Perubahan'
                                : '+ Buat Jadwal' ?>
                        </button>

                    </div>

                </form>

            <?php endif; ?>

        </section>

        <!-- MENUNGGU KONFIRMASI -->

        <section class="card">

            <div class="section-heading">
                <div>
                    <h2>Menunggu Konfirmasi</h2>
                    <p>
                        Pastikan transaksi benar-benar terjadi sebelum
                        mencatatnya ke saldo keuangan.
                    </p>
                </div>

                <badge color="warning">
                    <?= count($pendingOccurrences) ?> pending
                </badge>
            </div>

            <?php if (count($pendingOccurrences) === 0): ?>

                <div class="empty-state">
                    Tidak ada jadwal yang menunggu konfirmasi.
                </div>

            <?php else: ?>

                <div class="recurring-pending-list">

                    <?php foreach ($pendingOccurrences as $occurrence): ?>

                        <article class="recurring-pending-card">

                            <div class="recurring-pending-header">

                                <div>
                                    <h3>
                                        <?= e($occurrence['name']) ?>
                                    </h3>

                                    <p>
                                        <?= e($occurrence['category_name']) ?>
                                        ·
                                        <?= $occurrence['type'] === 'income'
                                            ? 'Pemasukan'
                                            : 'Pengeluaran' ?>
                                    </p>
                                </div>

                                <span class="type-badge <?= e($occurrence['type']) ?>">
                                    <?= e(
                                        $frequencyLabels[$occurrence['frequency']]
                                    ) ?>
                                </span>

                            </div>

                            <div class="recurring-pending-details">

                                <div>
                                    <span>Nominal rencana</span>
                                    <strong>
                                        <?= e(formatRupiah((int) $occurrence['amount'])) ?>
                                    </strong>
                                </div>

                                <div>
                                    <span>Tanggal jadwal</span>
                                    <strong>
                                        <?= e(date(
                                            'd/m/Y',
                                            strtotime($occurrence['scheduled_date'])
                                        )) ?>
                                    </strong>
                                </div>

                            </div>

                            <?php if ((int) $occurrence['category_active'] !== 1): ?>

                                <div class="alert alert-error">
                                    Kategori ini sudah nonaktif.
                                    Aktifkan kembali kategori sebelum
                                    mengonfirmasi transaksi.
                                </div>

                            <?php else: ?>

                                <form method="POST" action="recurring.php">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($csrf) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="confirm"
                                    >

                                    <input
                                        type="hidden"
                                        name="occurrence_id"
                                        value="<?= e($occurrence['occurrence_id']) ?>"
                                    >

                                    <div class="form-grid">

                                        <div class="form-group">
                                            <label>Nominal aktual</label>

                                            <div class="input-prefix">
                                                <span>Rp</span>

                                                <input
                                                    type="number"
                                                    name="actual_amount"
                                                    min="1"
                                                    max="9000000000000000"
                                                    step="1"
                                                    value="<?= e($occurrence['amount']) ?>"
                                                    required
                                                >
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label>Tanggal transaksi aktual</label>

                                            <input
                                                type="date"
                                                name="actual_date"
                                                min="<?= e($openingDate) ?>"
                                                max="<?= e($today) ?>"
                                                value="<?= e($today) ?>"
                                                required
                                            >
                                        </div>

                                        <div class="form-group">
                                            <label>Metode pembayaran</label>

                                            <select
                                                name="payment_method"
                                                required
                                            >
                                                <?php foreach ($paymentMethods as $key => $label): ?>

                                                    <option
                                                        value="<?= e($key) ?>"
                                                        <?= $key === 'cash' ? 'selected' : '' ?>
                                                    >
                                                        <?= e($label) ?>
                                                    </option>

                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                    </div>

                                    <div class="recurring-pending-actions">

                                        <button
                                            type="submit"
                                            class="btn btn-primary"
                                        >
                                            ✓ Konfirmasi & Catat
                                        </button>

                                    </div>

                                </form>

                            <?php endif; ?>

                            <form
                                method="POST"
                                action="recurring.php"
                                onsubmit="return confirm('Lewati jadwal ini? Tidak ada transaksi yang akan dibuat.')"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrf) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="skip"
                                >

                                <input
                                    type="hidden"
                                    name="occurrence_id"
                                    value="<?= e($occurrence['occurrence_id']) ?>"
                                >

                                <button type="submit" class="btn btn-text">
                                    Lewati jadwal
                                </button>

                            </form>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>

        <!-- DAFTAR JADWAL -->

        <section class="card">

            <div class="section-heading">
                <div>
                    <h2>Daftar Jadwal Berulang</h2>
                    <p>
                        Semua template transaksi rutin yang pernah dibuat.
                    </p>
                </div>
            </div>

            <div class="table-wrapper">

                <table class="transaction-table">

                    <thead>
                        <tr>
                            <th>Nama Jadwal</th>
                            <th>Jenis</th>
                            <th>Nominal</th>
                            <th>Frekuensi</th>
                            <th>Jatuh Tempo Berikutnya</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (count($schedules) === 0): ?>

                        <tr>
                            <td colspan="7" class="empty-state">
                                Belum ada jadwal transaksi berulang.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($schedules as $schedule): ?>

                            <?php
                            $isCompleted = (
                                $schedule['end_date'] !== null &&
                                $schedule['next_due_date'] > $schedule['end_date']
                            );

                            $scheduleStatus = $isCompleted
                                ? 'Selesai'
                                : ((int) $schedule['is_active'] === 1
                                    ? 'Aktif'
                                    : 'Nonaktif');
                            ?>

                            <tr>

                                <td class="description-cell">
                                    <?= e($schedule['name']) ?>
                                    <small class="schedule-category">
                                        <?= e($schedule['category_name']) ?>
                                    </small>
                                </td>

                                <td>
                                    <span class="type-badge <?= e($schedule['type']) ?>">
                                        <?= $schedule['type'] === 'income'
                                            ? 'Pemasukan'
                                            : 'Pengeluaran' ?>
                                    </span>
                                </td>

                                <td class="amount-cell <?= e($schedule['type']) ?>">
                                    <?= $schedule['type'] === 'income' ? '+' : '−' ?>
                                    <?= e(formatRupiah((int) $schedule['amount'])) ?>
                                </td>

                                <td>
                                    <?= e($frequencyLabels[$schedule['frequency']]) ?>
                                </td>

                                <td>
                                    <?= $isCompleted
                                        ? 'Selesai'
                                        : e(date(
                                            'd/m/Y',
                                            strtotime($schedule['next_due_date'])
                                        )) ?>
                                </td>

                                <td>
                                    <span class="recurring-status <?= $isCompleted
                                        ? 'completed'
                                        : ((int) $schedule['is_active'] === 1
                                            ? 'active'
                                            : 'inactive') ?>">
                                        <?= e($scheduleStatus) ?>
                                    </span>
                                </td>

                                <td>

                                    <div class="action-buttons">

                                        <a
                                            href="recurring.php?edit=<?= e($schedule['id']) ?>"
                                            class="btn btn-small btn-secondary"
                                        >
                                            Edit
                                        </a>

                                        <?php if (!$isCompleted): ?>

                                            <form
                                                method="POST"
                                                action="recurring.php"
                                                onsubmit="return confirm('<?= (int) $schedule['is_active'] === 1
                                                    ? 'Nonaktifkan'
                                                    : 'Aktifkan kembali' ?> jadwal ini?')"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($csrf) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="toggle"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= e($schedule['id']) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="target_status"
                                                    value="<?= (int) $schedule['is_active'] === 1
                                                        ? '0'
                                                        : '1' ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-small <?= (int) $schedule['is_active'] === 1
                                                        ? 'btn-danger'
                                                        : 'btn-secondary' ?>"
                                                >
                                                    <?= (int) $schedule['is_active'] === 1
                                                        ? 'Nonaktifkan'
                                                        : 'Aktifkan' ?>
                                                </button>

                                            </form>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <div class="table-footer">
                Jadwal nonaktif tidak menghasilkan kejadian baru.
                Kejadian yang sudah terbuat tetap dapat diproses.
            </div>

        </section>

        <!-- RIWAYAT -->

        <section class="card">

            <div class="section-heading">
                <div>
                    <h2>Riwayat Transaksi Berulang</h2>
                    <p>
                        Kejadian yang sudah dicatat atau dilewati.
                    </p>
                </div>
            </div>

            <div class="table-wrapper">

                <table class="transaction-table">

                    <thead>
                        <tr>
                            <th>Nama Jadwal</th>
                            <th>Tanggal Jadwal</th>
                            <th>Tanggal Transaksi</th>
                            <th>Nominal Aktual</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (count($historyRows) === 0): ?>

                        <tr>
                            <td colspan="5" class="empty-state">
                                Belum ada riwayat jadwal yang diproses.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($historyRows as $history): ?>

                            <tr>

                                <td class="description-cell">
                                    <?= e($history['name']) ?>
                                </td>

                                <td>
                                    <?= e(date(
                                        'd/m/Y',
                                        strtotime($history['scheduled_date'])
                                    )) ?>
                                </td>

                                <td>
                                    <?= $history['actual_date'] !== null
                                        ? e(date(
                                            'd/m/Y',
                                            strtotime($history['actual_date'])
                                        ))
                                        : '—' ?>
                                </td>

                                <td>
                                    <?= $history['actual_amount'] !== null
                                        ? e(formatRupiah(
                                            (int) $history['actual_amount']
                                        ))
                                        : '—' ?>
                                </td>

                                <td>
                                    <span class="recurring-status <?= e($history['status']) ?>">
                                        <?= $history['status'] === 'recorded'
                                            ? 'Sudah dicatat'
                                            : 'Dilewati' ?>
                                    </span>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <div class="table-footer">
                Maksimal 30 kejadian terakhir ditampilkan.
            </div>

        </section>

        <footer class="page-footer">
            FinTrack · Personal Expense Tracker
        </footer>

    </main>

</div>

<script src="assets/js/app.js"></script>

</body>
</html>
