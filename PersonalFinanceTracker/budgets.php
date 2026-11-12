
<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pdo = getPDO();

$settings = $pdo->query(
    'SELECT opening_balance_date
     FROM app_settings
     WHERE id = 1'
)->fetch();

if (!$settings) {
    throw new RuntimeException('Pengaturan aplikasi tidak ditemukan.');
}

$openingDate = (string) $settings['opening_balance_date'];
$minimumMonth = substr($openingDate, 0, 7);
$today = date('Y-m-d');
$currentMonth = date('Y-m');

function isValidMonth(string $month): bool
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        return false;
    }

    $year = (int) substr($month, 0, 4);
    $monthNumber = (int) substr($month, 5, 2);

    return checkdate($monthNumber, 1, $year);
}

function redirectToBudgets(string $month, string $status): void
{
    header(
        'Location: budgets.php?period='
        . rawurlencode($month)
        . '&status='
        . rawurlencode($status)
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Periode dan notifikasi
|--------------------------------------------------------------------------
*/

$period = $_GET['period'] ?? $currentMonth;

if (
    !is_string($period) ||
    !isValidMonth($period)
) {
    $period = $currentMonth;
}

$statusMessages = [
    'saved' => ['success', 'Anggaran berhasil disimpan.'],
    'updated' => ['success', 'Anggaran berhasil diperbarui.'],
    'deleted' => ['success', 'Anggaran berhasil dihapus.'],
    'invalid' => ['error', 'Data anggaran tidak valid. Periksa kembali formulir.'],
    'duplicate' => [
        'error',
        'Kategori tersebut sudah memiliki anggaran pada bulan yang dipilih.'
    ],
    'not_found' => ['error', 'Anggaran tidak ditemukan.'],
    'error' => ['error', 'Operasi gagal. Periksa log PHP.'],
];

$status = $_GET['status'] ?? '';
$notice = null;

if (is_string($status) && isset($statusMessages[$status])) {
    $notice = $statusMessages[$status];
}

/*
|--------------------------------------------------------------------------
| Proses penghapusan anggaran
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    $action = $_POST['action'] ?? '';

    if (!is_string($action)) {
        redirectToBudgets($period, 'invalid');
    }

    if ($action === 'delete') {
        $idRaw = $_POST['id'] ?? '';
        $periodRaw = $_POST['period'] ?? $period;

        if (
            !is_string($idRaw) ||
            !is_string($periodRaw) ||
            !isValidMonth($periodRaw)
        ) {
            redirectToBudgets($period, 'invalid');
        }

        $id = filter_var($idRaw, FILTER_VALIDATE_INT);

        if ($id === false || $id < 1) {
            redirectToBudgets($periodRaw, 'invalid');
        }

        try {
            $stmt = $pdo->prepare(
                'DELETE FROM budgets
                 WHERE id = :id'
            );

            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() === 0) {
                redirectToBudgets($periodRaw, 'not_found');
            }

        } catch (Throwable $e) {
            error_log('Gagal menghapus anggaran: ' . $e->getMessage());
            redirectToBudgets($periodRaw, 'error');
        }

        redirectToBudgets($periodRaw, 'deleted');
    }

    /*
    |--------------------------------------------------------------------------
    | Proses tambah dan edit anggaran
    |--------------------------------------------------------------------------
    */

    if ($action === 'save') {
        $idRaw = $_POST['id'] ?? '';
        $categoryRaw = $_POST['category_id'] ?? '';
        $amountRaw = $_POST['amount'] ?? '';
        $periodRaw = $_POST['period_month'] ?? '';

        if (
            !is_string($idRaw) ||
            !is_string($categoryRaw) ||
            !is_string($amountRaw) ||
            !is_string($periodRaw) ||
            !isValidMonth($periodRaw) ||
            $periodRaw < $minimumMonth
        ) {
            redirectToBudgets($period, 'invalid');
        }

        $id = null;

        if ($idRaw !== '') {
            $validatedId = filter_var(
                $idRaw,
                FILTER_VALIDATE_INT
            );

            if ($validatedId === false || $validatedId < 1) {
                redirectToBudgets($periodRaw, 'invalid');
            }

            $id = $validatedId;
        }

        $categoryId = filter_var(
            $categoryRaw,
            FILTER_VALIDATE_INT
        );

        $amount = filter_var(
            $amountRaw,
            FILTER_VALIDATE_INT
        );

        if (
            $categoryId === false ||
            $categoryId < 1 ||
            $amount === false ||
            $amount < 1 ||
            $amount > 9000000000000000
        ) {
            redirectToBudgets($periodRaw, 'invalid');
        }

        try {
            // Anggaran hanya diperbolehkan untuk kategori pengeluaran aktif.
            $categoryStmt = $pdo->prepare(
                "SELECT id
                 FROM categories
                 WHERE id = :id
                   AND type = 'expense'
                   AND is_active = 1"
            );

            $categoryStmt->execute([
                ':id' => $categoryId,
            ]);

            if ($categoryStmt->fetchColumn() === false) {
                redirectToBudgets($periodRaw, 'invalid');
            }

            // Pastikan pasangan kategori dan periode tidak duplikat.
            $duplicateSql = '
                SELECT id
                FROM budgets
                WHERE category_id = :category_id
                  AND period_month = :period_month
            ';

            $duplicateParams = [
                ':category_id' => $categoryId,
                ':period_month' => $periodRaw,
            ];

            if ($id !== null) {
                $duplicateSql .= ' AND id <> :id';
                $duplicateParams[':id'] = $id;
            }

            $duplicateStmt = $pdo->prepare($duplicateSql);
            $duplicateStmt->execute($duplicateParams);

            if ($duplicateStmt->fetchColumn() !== false) {
                redirectToBudgets($periodRaw, 'duplicate');
            }

            if ($id === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO budgets (
                        category_id,
                        period_month,
                        amount
                    ) VALUES (
                        :category_id,
                        :period_month,
                        :amount
                    )'
                );

                $stmt->execute([
                    ':category_id' => $categoryId,
                    ':period_month' => $periodRaw,
                    ':amount' => $amount,
                ]);

                $resultStatus = 'saved';

            } else {
                $existsStmt = $pdo->prepare(
                    'SELECT id FROM budgets WHERE id = :id'
                );

                $existsStmt->execute([':id' => $id]);

                if ($existsStmt->fetchColumn() === false) {
                    redirectToBudgets($periodRaw, 'not_found');
                }

                $stmt = $pdo->prepare(
                    'UPDATE budgets
                     SET
                        category_id = :category_id,
                        period_month = :period_month,
                        amount = :amount
                     WHERE id = :id'
                );

                $stmt->execute([
                    ':category_id' => $categoryId,
                    ':period_month' => $periodRaw,
                    ':amount' => $amount,
                    ':id' => $id,
                ]);

                $resultStatus = 'updated';
            }

        } catch (Throwable $e) {
            error_log('Gagal menyimpan anggaran: ' . $e->getMessage());
            redirectToBudgets($periodRaw, 'error');
        }

        redirectToBudgets($periodRaw, $resultStatus);
    }

    redirectToBudgets($period, 'invalid');
}

/*
|--------------------------------------------------------------------------
| Data kategori pengeluaran
|--------------------------------------------------------------------------
*/

$categoryStmt = $pdo->query(
    "SELECT id, name
     FROM categories
     WHERE type = 'expense'
       AND is_active = 1
     ORDER BY name"
);

$categories = $categoryStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Data anggaran yang sedang diedit
|--------------------------------------------------------------------------
*/

$editingBudget = null;
$editId = null;

if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($editId === false || $editId < 1) {
        $notice = ['error', 'ID anggaran tidak valid.'];
        $editId = null;
    } else {
        $editStmt = $pdo->prepare(
            'SELECT id, category_id, period_month, amount
             FROM budgets
             WHERE id = :id'
        );

        $editStmt->execute([':id' => $editId]);
        $editingBudget = $editStmt->fetch();

        if (!$editingBudget) {
            $notice = ['error', 'Anggaran tidak ditemukan.'];
            $editId = null;
        } else {
            $period = (string) $editingBudget['period_month'];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Ringkasan dan daftar anggaran per bulan
|--------------------------------------------------------------------------
*/

$periodStart = $period . '-01';
$periodEnd = (new DateTimeImmutable($periodStart))
    ->modify('last day of this month')
    ->format('Y-m-d');

$spendStart = max($periodStart, $openingDate);
$spendEnd = min($periodEnd, $today);

$listStmt = $pdo->prepare(
    "SELECT
        b.id,
        b.category_id,
        b.period_month,
        b.amount,
        c.name AS category_name,
        COALESCE(SUM(t.amount), 0) AS spent

     FROM budgets b

     INNER JOIN categories c
        ON c.id = b.category_id

     LEFT JOIN transactions t
        ON t.category_id = b.category_id
       AND t.type = 'expense'
       AND t.transaction_date >= :spend_start
       AND t.transaction_date <= :spend_end

     WHERE b.period_month = :period_month

     GROUP BY
        b.id,
        b.category_id,
        b.period_month,
        b.amount,
        c.name

     ORDER BY c.name"
);

$listStmt->execute([
    ':spend_start' => $spendStart,
    ':spend_end' => $spendEnd,
    ':period_month' => $period,
]);

$budgets = $listStmt->fetchAll();

$totalBudget = 0;
$totalSpent = 0;

foreach ($budgets as &$budget) {
    $budget['amount'] = (int) $budget['amount'];
    $budget['spent'] = (int) $budget['spent'];

    $budget['remaining'] = $budget['amount'] - $budget['spent'];

    $budget['percent'] = $budget['amount'] > 0
        ? ($budget['spent'] / $budget['amount']) * 100
        : 0;

    $budget['bar_percent'] = min(100, $budget['percent']);

    if ($budget['percent'] > 100) {
    $budget['state'] = 'exceeded';
    $budget['state_label'] = 'Terlampaui';
} elseif ($budget['percent'] >= 100) {
    $budget['state'] = 'warning';
    $budget['state_label'] = 'Batas tercapai';
} elseif ($budget['percent'] >= 90) {
    $budget['state'] = 'warning';
    $budget['state_label'] = 'Hampir habis';
} else {
    $budget['state'] = 'normal';
    $budget['state_label'] = 'Dalam batas';
}

    $totalBudget += $budget['amount'];
    $totalSpent += $budget['spent'];
}
unset($budget);

$totalRemaining = $totalBudget - $totalSpent;

$totalPercent = $totalBudget > 0
    ? ($totalSpent / $totalBudget) * 100
    : 0;

$periodLabel = (new DateTimeImmutable($periodStart))
    ->format('F Y');

$monthNames = [
    'January' => 'Januari',
    'February' => 'Februari',
    'March' => 'Maret',
    'April' => 'April',
    'May' => 'Mei',
    'June' => 'Juni',
    'July' => 'Juli',
    'August' => 'Agustus',
    'September' => 'September',
    'October' => 'Oktober',
    'November' => 'November',
    'December' => 'Desember',
];

foreach ($monthNames as $english => $indonesian) {
    $periodLabel = str_replace($english, $indonesian, $periodLabel);
}

$formId = (string) ($editingBudget['id'] ?? '');
$formCategoryId = (string) ($editingBudget['category_id'] ?? '');
$formAmount = (string) ($editingBudget['amount'] ?? '');
$formPeriod = (string) ($editingBudget['period_month'] ?? $period);

$csrf = csrfToken();

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anggaran | FinTrack</title>
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

            <a href="recurring.php" class="nav-item">
                <span>⟳</span> Transaksi Berulang
            </a>

            <a href="budgets.php" class="nav-item active">
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

            <a href="tutorial.php" class="nav-item">
                <span>ⓘ</span> Tutorial
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
                <span class="breadcrumb">FinTrack / Anggaran</span>
                <h1>Anggaran Bulanan</h1>
            </div>

            <span class="local-badge">Lokal</span>
        </header>

        <?php if ($notice !== null): ?>
            <div class="alert alert-<?= e($notice[0]) ?>">
                <?= e($notice[1]) ?>
            </div>
        <?php endif; ?>

        <section class="page-intro">
            <h2>Rencanakan pengeluaranmu</h2>
            <p>
                Tentukan batas pengeluaran setiap kategori dan pantau
                penggunaannya selama satu bulan.
            </p>
        </section>

        <section class="card">

            <div class="section-heading">
                <div>
                    <h2>Pilih Periode</h2>
                    <p>Anggaran dan pemakaian ditampilkan berdasarkan bulan pilihan.</p>
                </div>
            </div>

            <form method="GET" action="budgets.php" class="filter-form">
                <div class="form-group">
                    <label for="period">Bulan anggaran</label>

                    <input
                        type="month"
                        id="period"
                        name="period"
                        min="<?= e($minimumMonth) ?>"
                        value="<?= e($period) ?>"
                        required
                    >
                </div>

                <button class="btn btn-secondary" type="submit">
                    Tampilkan
                </button>
            </form>

        </section>

        <section class="dashboard-stat-grid budget-stat-grid">

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Total anggaran</span>
                    <span class="stat-icon">Rp</span>
                </div>

                <div class="stat-value">
                    <?= e(formatRupiah($totalBudget)) ?>
                </div>

                <div class="stat-note">
                    <?= count($budgets) ?> kategori dianggarkan
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Pengeluaran aktual</span>
                    <span class="stat-icon expense-icon">↘</span>
                </div>

                <div class="stat-value expense-text">
                    <?= e(formatRupiah($totalSpent)) ?>
                </div>

                <div class="stat-note">
                    Pengeluaran pada kategori yang dianggarkan
                </div>
            </article>

            <article class="stat-card <?= $totalRemaining < 0 ? 'budget-negative' : '' ?>">
                <div class="stat-topline">
                    <span>Sisa anggaran</span>
                    <span class="stat-icon">✓</span>
                </div>

                <div class="stat-value <?= $totalRemaining < 0 ? 'expense-text' : '' ?>">
                    <?= $totalRemaining < 0 ? '−' : '' ?>
                    <?= e(formatRupiah(abs($totalRemaining))) ?>
                </div>

                <div class="stat-note">
                    <?= $totalRemaining < 0
                        ? 'Total pengeluaran melampaui anggaran'
                        : 'Anggaran dikurangi pengeluaran aktual' ?>
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Penggunaan anggaran</span>
                    <span class="stat-icon count-icon">%</span>
                </div>

                <div class="stat-value">
                    <?= number_format($totalPercent, 1, ',', '.') ?>%
                </div>

                <div class="stat-note">
                    Untuk periode <?= e($periodLabel) ?>
                </div>
            </article>

        </section>

        <section class="card">

            <div class="section-heading">
                <div>
                    <h2><?= $editingBudget ? 'Edit Anggaran' : 'Tambah Anggaran' ?></h2>
                    <p>Satu kategori hanya dapat memiliki satu anggaran untuk setiap bulan.</p>
                </div>
            </div>

            <?php if (count($categories) === 0): ?>

                <div class="alert alert-error">
                    Belum ada kategori pengeluaran aktif.
                </div>

            <?php else: ?>

                <form method="POST" action="budgets.php?period=<?= e($period) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= e($formId) ?>">

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="category_id">Kategori pengeluaran</label>

                            <select
                                id="category_id"
                                name="category_id"
                                required
                            >
                                <option value="">Pilih kategori</option>

                                <?php foreach ($categories as $category): ?>
                                    <option
                                        value="<?= e($category['id']) ?>"
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
                            <label for="amount">Batas anggaran (Rupiah)</label>

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
                                    placeholder="Contoh: 600000"
                                    required
                                >
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="period_month">Bulan anggaran</label>

                            <input
                                type="month"
                                id="period_month"
                                name="period_month"
                                min="<?= e($minimumMonth) ?>"
                                value="<?= e($formPeriod) ?>"
                                required
                            >
                        </div>

                    </div>

                    <div class="form-actions">

                        <?php if ($editingBudget): ?>
                            <a
                                href="budgets.php?period=<?= e($period) ?>"
                                class="btn btn-secondary"
                            >
                                Batal
                            </a>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary">
                            <?= $editingBudget
                                ? 'Simpan Perubahan'
                                : '+ Simpan Anggaran' ?>
                        </button>

                    </div>
                </form>

            <?php endif; ?>

        </section>

        <section class="card">

            <div class="section-heading">
                <div>
                    <h2>Daftar Anggaran</h2>
                    <p>
                        Periode: <?= e($periodLabel) ?>.
                        Pengeluaran masa depan akan tetap bernilai nol sampai transaksi tercatat.
                    </p>
                </div>
            </div>

            <div class="table-wrapper">
                <table class="transaction-table">

                    <thead>
                        <tr>
                            <th>Kategori</th>
                            <th>Anggaran</th>
                            <th>Terpakai</th>
                            <th>Sisa</th>
                            <th>Progres</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (count($budgets) === 0): ?>

                        <tr>
                            <td colspan="6" class="empty-state">
                                Belum ada anggaran untuk periode ini.
                                Tambahkan anggaran melalui formulir di atas.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($budgets as $budget): ?>

                            <tr>
                                <td class="description-cell">
                                    <?= e($budget['category_name']) ?>
                                </td>

                                <td>
                                    <?= e(formatRupiah($budget['amount'])) ?>
                                </td>

                                <td>
                                    <?= e(formatRupiah($budget['spent'])) ?>
                                </td>

                                <td class="<?= $budget['remaining'] < 0 ? 'expense-text' : '' ?>">
                                    <?= $budget['remaining'] < 0 ? '−' : '' ?>
                                    <?= e(formatRupiah(abs($budget['remaining']))) ?>
                                </td>

                                <td class="budget-progress-cell">
                                    <div class="budget-progress">
                                        <div
                                            class="budget-progress-fill <?= e($budget['state']) ?>"
                                            style="width: <?= e((string) $budget['bar_percent']) ?>%;"
                                        ></div>
                                    </div>

                                    <div class="budget-progress-meta">
                                        <span>
                                            <?= number_format(
                                                $budget['percent'],
                                                1,
                                                ',',
                                                '.'
                                            ) ?>%
                                        </span>

                                        <span class="budget-state <?= e($budget['state']) ?>">
                                            <?= e($budget['state_label']) ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <div class="action-buttons">

                                        <a
                                            href="budgets.php?period=<?= e($period) ?>&edit=<?= e($budget['id']) ?>"
                                            class="btn btn-small btn-secondary"
                                        >
                                            Edit
                                        </a>

                                        <form
                                            method="POST"
                                            action="budgets.php?period=<?= e($period) ?>"
                                            onsubmit="return confirm('Yakin ingin menghapus anggaran ini? Transaksi tidak akan ikut terhapus.')"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($csrf) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= e($budget['id']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="period"
                                                value="<?= e($period) ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-small btn-danger"
                                            >
                                                Hapus
                                            </button>
                                        </form>

                                    </div>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>
            </div>

            <div class="table-footer">
                Anggaran dihitung terpisah dari saldo keuangan.
            </div>

        </section>

        <footer class="page-footer">
            FinTrack · Personal Expense Tracker
        </footer>

    </main>

</div>

</body>
</html>
