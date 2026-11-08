
<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pdo = getPDO();

/*
|--------------------------------------------------------------------------
| Status notifikasi
|--------------------------------------------------------------------------
*/

$statusMessages = [
    'saved' => ['success', 'Transaksi berhasil ditambahkan.'],
    'updated' => ['success', 'Transaksi berhasil diperbarui.'],
    'deleted' => ['success', 'Transaksi berhasil dihapus.'],
    'invalid' => ['error', 'Data transaksi tidak valid. Periksa kembali formulir.'],
    'category_mismatch' => [
        'error',
        'Kategori tidak sesuai dengan jenis transaksi.'
    ],
    'not_found' => ['error', 'Transaksi tidak ditemukan.'],
    'error' => ['error', 'Operasi gagal. Periksa konfigurasi dan log PHP.'],
];

$status = $_GET['status'] ?? '';
$notice = null;

if (is_string($status) && isset($statusMessages[$status])) {
    $notice = $statusMessages[$status];
}

/*
|--------------------------------------------------------------------------
| Data transaksi yang sedang diedit
|--------------------------------------------------------------------------
*/

$editingTransaction = null;

if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($editId === false || $editId < 1) {
        $notice = ['error', 'ID transaksi tidak valid.'];
    } else {
        $editStmt = $pdo->prepare(
            'SELECT * FROM transactions WHERE id = :id'
        );

        $editStmt->execute([':id' => $editId]);
        $editingTransaction = $editStmt->fetch();

        if (!$editingTransaction) {
            $notice = ['error', 'Transaksi yang ingin diedit tidak ditemukan.'];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Daftar kategori aktif
|--------------------------------------------------------------------------
*/

$categoryStmt = $pdo->query(
    "SELECT id, name, type
     FROM categories
     WHERE is_active = 1
     ORDER BY
        CASE WHEN type = 'income' THEN 0 ELSE 1 END,
        name"
);

$categories = $categoryStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Filter dan pencarian riwayat transaksi
|--------------------------------------------------------------------------
*/

$filterType = $_GET['type'] ?? 'all';

if (!in_array($filterType, ['all', 'income', 'expense'], true)) {
    $filterType = 'all';
}

$search = trim((string) ($_GET['q'] ?? ''));

$conditions = [];
$params = [];

if ($filterType !== 'all') {
    $conditions[] = 't.type = :type';
    $params[':type'] = $filterType;
}

if ($search !== '') {
    $conditions[] = '
        (
            t.description LIKE :description_search
            OR c.name LIKE :category_search
        )
    ';

    $searchPattern = '%' . $search . '%';

    $params[':description_search'] = $searchPattern;
    $params[':category_search'] = $searchPattern;
}

$whereSql = '';

if (count($conditions) > 0) {
    $whereSql = ' WHERE ' . implode(' AND ', $conditions);
}

$listSql = "
    SELECT
        t.id,
        t.category_id,
        t.type,
        t.amount,
        t.description,
        t.transaction_date,
        t.payment_method,
        c.name AS category_name
    FROM transactions t
    INNER JOIN categories c
        ON c.id = t.category_id
    {$whereSql}
    ORDER BY t.transaction_date DESC, t.id DESC
    LIMIT 200
";

$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);

$transactions = $listStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Nilai awal formulir
|--------------------------------------------------------------------------
*/

$formAction = $editingTransaction
    ? 'actions/update-transaction.php'
    : 'actions/save-transaction.php';

$formType = $editingTransaction['type'] ?? 'expense';
$formCategoryId = (string) ($editingTransaction['category_id'] ?? '');
$formAmount = (string) ($editingTransaction['amount'] ?? '');
$formDescription = (string) ($editingTransaction['description'] ?? '');
$formDate = (string) (
    $editingTransaction['transaction_date'] ?? date('Y-m-d')
);
$formPayment = (string) (
    $editingTransaction['payment_method'] ?? 'cash'
);

$csrf = csrfToken();

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaksi | FinTrack</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="app-layout">

    <aside class="sidebar">
        <a href="transactions.php" class="brand">
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

            <a href="transactions.php" class="nav-item active">
                <span>↔</span> Transaksi
            </a>

            <a href="recurring.php" class="nav-item">
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
                <span class="breadcrumb">FinTrack / Transaksi</span>
                <h1>Transaksi</h1>
            </div>

            <span class="local-badge">Lokal</span>
        </header>

        <?php if ($notice !== null): ?>
            <div class="alert alert-<?= e($notice[0]) ?>">
                <?= e($notice[1]) ?>
            </div>
        <?php endif; ?>

        <section class="page-intro">
            <div>
                <h2>Kelola transaksi keuangan</h2>
                <p>
                    Catat dan pantau pemasukan serta pengeluaran
                    dalam satu tempat.
                </p>
            </div>
        </section>

        <section class="transaction-form-section card">

            <div class="section-heading">
                <div>
                    <h2>
                        <?= $editingTransaction
                            ? 'Edit Transaksi'
                            : 'Tambah Transaksi' ?>
                    </h2>

                    <p>
                        Isi informasi transaksi dengan benar.
                    </p>
                </div>
            </div>

            <?php if (count($categories) === 0): ?>

                <div class="alert alert-error">
                    Belum ada kategori aktif. Periksa tabel categories
                    di database.
                </div>

            <?php else: ?>

                <form
                    method="POST"
                    action="<?= e($formAction) ?>"
                    class="transaction-form"
                >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf) ?>"
                    >

                    <?php if ($editingTransaction): ?>
                        <input
                            type="hidden"
                            name="id"
                            value="<?= e($editingTransaction['id']) ?>"
                        >
                    <?php endif; ?>

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="type">Jenis transaksi</label>

                            <select
                                id="type"
                                name="type"
                                required
                            >
                                <option
                                    value="expense"
                                    <?= $formType === 'expense'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Pengeluaran
                                </option>

                                <option
                                    value="income"
                                    <?= $formType === 'income'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Pemasukan
                                </option>
                            </select>
                        </div>

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
                                        <?= e(
                                            ($category['type'] === 'income'
                                                ? 'Pemasukan — '
                                                : 'Pengeluaran — ')
                                            . $category['name']
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>

                            <small class="field-help">
                                Pilih kategori yang sesuai dengan jenis transaksi.
                            </small>
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
                                    step="1"
                                    max="9000000000000000"
                                    placeholder="Contoh: 50000"
                                    value="<?= e($formAmount) ?>"
                                    required
                                >
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="transaction_date">Tanggal transaksi</label>

                            <input
                                type="date"
                                id="transaction_date"
                                name="transaction_date"
                                value="<?= e($formDate) ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="payment_method">Metode pembayaran</label>

                            <select
                                id="payment_method"
                                name="payment_method"
                                required
                            >
                                <option value="cash"
                                    <?= $formPayment === 'cash' ? 'selected' : '' ?>>
                                    Tunai
                                </option>

                                <option value="transfer"
                                    <?= $formPayment === 'transfer' ? 'selected' : '' ?>>
                                    Transfer bank
                                </option>

                                <option value="e-wallet"
                                    <?= $formPayment === 'e-wallet' ? 'selected' : '' ?>>
                                    E-wallet
                                </option>

                                <option value="debit-card"
                                    <?= $formPayment === 'debit-card' ? 'selected' : '' ?>>
                                    Kartu debit
                                </option>

                                <option value="credit-card"
                                    <?= $formPayment === 'credit-card' ? 'selected' : '' ?>>
                                    Kartu kredit
                                </option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="description">Deskripsi transaksi</label>

                            <input
                                type="text"
                                id="description"
                                name="description"
                                maxlength="200"
                                placeholder="Contoh: Makan siang di kampus"
                                value="<?= e($formDescription) ?>"
                                required
                            >
                        </div>

                    </div>

                    <div class="form-actions">

                        <?php if ($editingTransaction): ?>
                            <a href="transactions.php" class="btn btn-secondary">
                                Batal
                            </a>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary">
                            <?= $editingTransaction
                                ? 'Simpan Perubahan'
                                : '+ Simpan Transaksi' ?>
                        </button>

                    </div>
                </form>

            <?php endif; ?>

        </section>

        <section class="history-section card">

            <div class="section-heading">
                <div>
                    <h2>Riwayat Transaksi</h2>
                    <p>
                        Menampilkan maksimal 200 transaksi terbaru
                        sesuai filter.
                    </p>
                </div>
            </div>

            <form method="GET" action="transactions.php" class="filter-form">

                <div class="form-group search-field">
                    <label for="q">Cari transaksi</label>

                    <input
                        type="search"
                        id="q"
                        name="q"
                        value="<?= e($search) ?>"
                        placeholder="Cari deskripsi atau kategori..."
                    >
                </div>

                <div class="form-group type-filter">
                    <label for="filter_type">Jenis transaksi</label>

                    <select id="filter_type" name="type">
                        <option
                            value="all"
                            <?= $filterType === 'all' ? 'selected' : '' ?>
                        >
                            Semua jenis
                        </option>

                        <option
                            value="income"
                            <?= $filterType === 'income' ? 'selected' : '' ?>
                        >
                            Pemasukan
                        </option>

                        <option
                            value="expense"
                            <?= $filterType === 'expense' ? 'selected' : '' ?>
                        >
                            Pengeluaran
                        </option>
                    </select>
                </div>

                <button type="submit" class="btn btn-secondary">
                    Cari
                </button>

                <a href="transactions.php" class="btn btn-text">
                    Reset
                </a>

            </form>

            <div class="table-wrapper">
                <table class="transaction-table">

                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Deskripsi</th>
                            <th>Kategori</th>
                            <th>Metode</th>
                            <th>Jenis</th>
                            <th>Nominal</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (count($transactions) === 0): ?>

                        <tr>
                            <td colspan="7" class="empty-state">
                                Belum ada transaksi yang sesuai.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($transactions as $transaction): ?>

                            <tr>
                                <td>
                                    <?= e(
                                        date(
                                            'd/m/Y',
                                            strtotime($transaction['transaction_date'])
                                        )
                                    ) ?>
                                </td>

                                <td class="description-cell">
                                    <?= e($transaction['description']) ?>
                                </td>

                                <td>
                                    <?= e($transaction['category_name']) ?>
                                </td>

                                <td>
                                    <?= e(
                                        [
                                            'cash' => 'Tunai',
                                            'transfer' => 'Transfer',
                                            'e-wallet' => 'E-wallet',
                                            'debit-card' => 'Kartu debit',
                                            'credit-card' => 'Kartu kredit',
                                        ][$transaction['payment_method']]
                                        ?? $transaction['payment_method']
                                    ) ?>
                                </td>

                                <td>
                                    <span class="type-badge <?= e($transaction['type']) ?>">
                                        <?= $transaction['type'] === 'income'
                                            ? 'Pemasukan'
                                            : 'Pengeluaran' ?>
                                    </span>
                                </td>

                                <td class="amount-cell <?= e($transaction['type']) ?>">
                                    <?= $transaction['type'] === 'income' ? '+' : '−' ?>
                                    <?= e(formatRupiah((int) $transaction['amount'])) ?>
                                </td>

                                <td>
                                    <div class="action-buttons">

                                        <a
                                            href="transactions.php?edit=<?= e($transaction['id']) ?>"
                                            class="btn btn-small btn-secondary"
                                        >
                                            Edit
                                        </a>

                                        <form
                                            method="POST"
                                            action="actions/delete-transaction.php"
                                            onsubmit="return confirm('Yakin ingin menghapus transaksi ini?')"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($csrf) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= e($transaction['id']) ?>"
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
                Menampilkan <?= count($transactions) ?> transaksi.
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
