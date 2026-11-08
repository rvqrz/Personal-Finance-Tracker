
<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pdo = getPDO();

/*
|--------------------------------------------------------------------------
| Fungsi redirect halaman kategori
|--------------------------------------------------------------------------
*/

function redirectToCategories(string $status): void
{
    header(
        'Location: categories.php?status=' . rawurlencode($status)
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| Daftar notifikasi
|--------------------------------------------------------------------------
*/

$statusMessages = [
    'saved' => ['success', 'Kategori berhasil ditambahkan.'],
    'updated' => ['success', 'Kategori berhasil diperbarui.'],
    'activated' => ['success', 'Kategori berhasil diaktifkan kembali.'],
    'deactivated' => ['success', 'Kategori berhasil dinonaktifkan.'],
    'duplicate' => ['error', 'Nama kategori dengan jenis tersebut sudah tersedia.'],
    'invalid' => ['error', 'Data kategori tidak valid.'],
    'not_found' => ['error', 'Kategori tidak ditemukan.'],
    'error' => ['error', 'Terjadi kesalahan. Periksa log PHP.'],
];

$status = $_GET['status'] ?? '';
$notice = null;

if (is_string($status) && isset($statusMessages[$status])) {
    $notice = $statusMessages[$status];
}

/*
|--------------------------------------------------------------------------
| Proses formulir
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    $action = $_POST['action'] ?? '';

    if (!is_string($action)) {
        redirectToCategories('invalid');
    }

    /*
    |--------------------------------------------------------------------------
    | Tambah atau edit kategori
    |--------------------------------------------------------------------------
    */

    if ($action === 'save') {
        $idRaw = $_POST['id'] ?? '';
        $nameRaw = $_POST['name'] ?? '';
        $iconRaw = $_POST['icon'] ?? '';

        if (
            !is_string($idRaw) ||
            !is_string($nameRaw) ||
            !is_string($iconRaw)
        ) {
            redirectToCategories('invalid');
        }

        $name = trim($nameRaw);
        $icon = trim($iconRaw);

        $nameLength = function_exists('mb_strlen')
            ? mb_strlen($name, 'UTF-8')
            : strlen($name);

        if (
            $name === '' ||
            $nameLength > 60 ||
            strlen($icon) > 80
        ) {
            redirectToCategories('invalid');
        }

        $id = null;

        if ($idRaw !== '') {
            $validatedId = filter_var(
                $idRaw,
                FILTER_VALIDATE_INT
            );

            if ($validatedId === false || $validatedId < 1) {
                redirectToCategories('invalid');
            }

            $id = $validatedId;
        }

        try {
            if ($id === null) {

                // Untuk kategori baru, jenis wajib dipilih.
                $type = $_POST['type'] ?? '';

                if (
                    !is_string($type) ||
                    !in_array($type, ['income', 'expense'], true)
                ) {
                    redirectToCategories('invalid');
                }

            } else {

                // Ambil jenis dari database agar tidak bisa diubah saat edit.
                $existingStmt = $pdo->prepare(
                    'SELECT id, type
                     FROM categories
                     WHERE id = :id'
                );

                $existingStmt->execute([':id' => $id]);

                $existingCategory = $existingStmt->fetch();

                if (!$existingCategory) {
                    redirectToCategories('not_found');
                }

                $type = $existingCategory['type'];
            }

            // Periksa apakah nama kategori sudah dipakai.
            $duplicateSql = '
                SELECT id
                FROM categories
                WHERE LOWER(name) = LOWER(:name)
                  AND type = :type
            ';

            $duplicateParams = [
                ':name' => $name,
                ':type' => $type,
            ];

            if ($id !== null) {
                $duplicateSql .= ' AND id <> :id';
                $duplicateParams[':id'] = $id;
            }

            $duplicateStmt = $pdo->prepare($duplicateSql);
            $duplicateStmt->execute($duplicateParams);

            if ($duplicateStmt->fetchColumn() !== false) {
                redirectToCategories('duplicate');
            }

            $iconValue = $icon === '' ? null : $icon;

            if ($id === null) {

                $insertStmt = $pdo->prepare(
                    'INSERT INTO categories (
                        name,
                        type,
                        icon,
                        is_active
                    ) VALUES (
                        :name,
                        :type,
                        :icon,
                        1
                    )'
                );

                $insertStmt->execute([
                    ':name' => $name,
                    ':type' => $type,
                    ':icon' => $iconValue,
                ]);

                redirectToCategories('saved');

            } else {

                $updateStmt = $pdo->prepare(
                    'UPDATE categories
                     SET
                        name = :name,
                        icon = :icon
                     WHERE id = :id'
                );

                $updateStmt->execute([
                    ':name' => $name,
                    ':icon' => $iconValue,
                    ':id' => $id,
                ]);

                redirectToCategories('updated');
            }

        } catch (Throwable $e) {
            error_log('Gagal menyimpan kategori: ' . $e->getMessage());
            redirectToCategories('error');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Aktifkan atau nonaktifkan kategori
    |--------------------------------------------------------------------------
    */

    if ($action === 'toggle') {
        $idRaw = $_POST['id'] ?? '';
        $targetStatusRaw = $_POST['target_status'] ?? '';

        if (
            !is_string($idRaw) ||
            !is_string($targetStatusRaw) ||
            !in_array($targetStatusRaw, ['0', '1'], true)
        ) {
            redirectToCategories('invalid');
        }

        $id = filter_var($idRaw, FILTER_VALIDATE_INT);

        if ($id === false || $id < 1) {
            redirectToCategories('invalid');
        }

        $targetStatus = (int) $targetStatusRaw;

        try {
            $checkStmt = $pdo->prepare(
                'SELECT id
                 FROM categories
                 WHERE id = :id'
            );

            $checkStmt->execute([':id' => $id]);

            if ($checkStmt->fetchColumn() === false) {
                redirectToCategories('not_found');
            }

            $updateStmt = $pdo->prepare(
                'UPDATE categories
                 SET is_active = :status
                 WHERE id = :id'
            );

            $updateStmt->execute([
                ':status' => $targetStatus,
                ':id' => $id,
            ]);

        } catch (Throwable $e) {
            error_log('Gagal mengubah status kategori: ' . $e->getMessage());
            redirectToCategories('error');
        }

        redirectToCategories(
            $targetStatus === 1 ? 'activated' : 'deactivated'
        );
    }

    redirectToCategories('invalid');
}

/*
|--------------------------------------------------------------------------
| Data kategori yang sedang diedit
|--------------------------------------------------------------------------
*/

$editingCategory = null;

if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($editId === false || $editId < 1) {
        $notice = ['error', 'ID kategori tidak valid.'];
    } else {
        $editStmt = $pdo->prepare(
            'SELECT id, name, type, icon, is_active
             FROM categories
             WHERE id = :id'
        );

        $editStmt->execute([':id' => $editId]);

        $editingCategory = $editStmt->fetch();

        if (!$editingCategory) {
            $notice = ['error', 'Kategori tidak ditemukan.'];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Ringkasan kategori
|--------------------------------------------------------------------------
*/

$stats = $pdo->query(
    "SELECT
        COUNT(
            CASE WHEN is_active = 1 AND type = 'income'
                 THEN 1 END
        ) AS active_income,

        COUNT(
            CASE WHEN is_active = 1 AND type = 'expense'
                 THEN 1 END
        ) AS active_expense,

        COUNT(
            CASE WHEN is_active = 0
                 THEN 1 END
        ) AS inactive_total

     FROM categories"
)->fetch();

/*
|--------------------------------------------------------------------------
| Daftar seluruh kategori
|--------------------------------------------------------------------------
*/

$categoryStmt = $pdo->query(
    "SELECT
        c.id,
        c.name,
        c.type,
        c.icon,
        c.is_active,

        (
            SELECT COUNT(*)
            FROM transactions t
            WHERE t.category_id = c.id
        ) AS transaction_count,

        (
            SELECT COUNT(*)
            FROM budgets b
            WHERE b.category_id = c.id
        ) AS budget_count

     FROM categories c

     ORDER BY
        CASE WHEN c.is_active = 1 THEN 0 ELSE 1 END,
        CASE WHEN c.type = 'income' THEN 0 ELSE 1 END,
        c.name"
);

$categories = $categoryStmt->fetchAll();

$formId = $editingCategory['id'] ?? '';
$formName = $editingCategory['name'] ?? '';
$formIcon = $editingCategory['icon'] ?? '';
$formType = $editingCategory['type'] ?? 'expense';

$csrf = csrfToken();

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kategori | FinTrack</title>
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

            <a href="budgets.php" class="nav-item">
                <span>◷</span> Anggaran
            </a>
            <a href="savings.php" class="nav-item">
                <span>◈</span> Tabungan
            </a>

            <a href="categories.php" class="nav-item active">
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
                <span class="breadcrumb">FinTrack / Kategori</span>
                <h1>Manajemen Kategori</h1>
            </div>

            <span class="local-badge">Lokal</span>
        </header>

        <?php if ($notice !== null): ?>
            <div class="alert alert-<?= e($notice[0]) ?>">
                <?= e($notice[1]) ?>
            </div>
        <?php endif; ?>

        <section class="page-intro">
            <h2>Kelola kategori keuangan</h2>
            <p>
                Sesuaikan kategori pemasukan dan pengeluaran
                dengan kebutuhan pencatatanmu.
            </p>
        </section>

        <!-- RINGKASAN -->

        <section class="dashboard-stat-grid category-stat-grid">

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Kategori pemasukan aktif</span>
                    <span class="stat-icon income-icon">↗</span>
                </div>

                <div class="stat-value">
                    <?= e($stats['active_income']) ?>
                </div>

                <div class="stat-note">
                    Bisa digunakan untuk transaksi pemasukan
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Kategori pengeluaran aktif</span>
                    <span class="stat-icon expense-icon">↘</span>
                </div>

                <div class="stat-value">
                    <?= e($stats['active_expense']) ?>
                </div>

                <div class="stat-note">
                    Bisa digunakan untuk transaksi pengeluaran
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Kategori nonaktif</span>
                    <span class="stat-icon count-icon">−</span>
                </div>

                <div class="stat-value">
                    <?= e($stats['inactive_total']) ?>
                </div>

                <div class="stat-note">
                    Tidak tersedia untuk transaksi baru
                </div>
            </article>

        </section>

        <!-- FORM TAMBAH DAN EDIT -->

        <section class="card">

            <div class="section-heading">
                <div>
                    <h2>
                        <?= $editingCategory
                            ? 'Edit Kategori'
                            : 'Tambah Kategori Baru' ?>
                    </h2>

                    <p>
                        Masukkan nama, jenis, dan ikon kategori.
                    </p>
                </div>
            </div>

            <form method="POST" action="categories.php">

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
                        <label for="name">Nama kategori</label>

                        <input
                            type="text"
                            id="name"
                            name="name"
                            maxlength="60"
                            value="<?= e($formName) ?>"
                            placeholder="Contoh: Kebutuhan Kuliah"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="type">Jenis kategori</label>

                        <?php if ($editingCategory): ?>

                            <input
                                type="text"
                                id="type"
                                value="<?= $formType === 'income'
                                    ? 'Pemasukan'
                                    : 'Pengeluaran' ?>"
                                disabled
                            >

                            <small class="field-help">
                                Jenis kategori tidak dapat diubah setelah dibuat.
                            </small>

                        <?php else: ?>

                            <select
                                id="type"
                                name="type"
                                required
                            >
                                <option value="expense"
                                    <?= $formType === 'expense' ? 'selected' : '' ?>>
                                    Pengeluaran
                                </option>

                                <option value="income"
                                    <?= $formType === 'income' ? 'selected' : '' ?>>
                                    Pemasukan
                                </option>
                            </select>

                        <?php endif; ?>

                    </div>

                    <div class="form-group">
                        <label for="icon">Ikon kategori (opsional)</label>

                        <input
                            type="text"
                            id="icon"
                            name="icon"
                            maxlength="20"
                            value="<?= e($formIcon) ?>"
                            placeholder="Contoh: 🍔"
                        >

                        <small class="field-help">
                            Kamu bisa menggunakan emoji sebagai ikon.
                        </small>
                    </div>

                </div>

                <div class="form-actions">

                    <?php if ($editingCategory): ?>
                        <a href="categories.php" class="btn btn-secondary">
                            Batal
                        </a>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary">
                        <?= $editingCategory
                            ? 'Simpan Perubahan'
                            : '+ Tambah Kategori' ?>
                    </button>

                </div>

            </form>

        </section>

        <!-- DAFTAR KATEGORI -->

        <section class="card">

            <div class="section-heading">
                <div>
                    <h2>Daftar Kategori</h2>
                    <p>
                        Kategori aktif dan nonaktif akan tetap ditampilkan
                        agar riwayat lama tidak kehilangan referensi.
                    </p>
                </div>
            </div>

            <div class="table-wrapper">

                <table class="transaction-table">

                    <thead>
                        <tr>
                            <th>Kategori</th>
                            <th>Jenis</th>
                            <th>Transaksi</th>
                            <th>Anggaran</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (count($categories) === 0): ?>

                        <tr>
                            <td colspan="6" class="empty-state">
                                Belum ada kategori yang tersedia.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($categories as $category): ?>

                            <tr class="<?= (int) $category['is_active'] === 0
                                ? 'inactive-category-row'
                                : '' ?>">

                                <td class="category-name-cell">
                                    <span class="category-icon">
                                        <?= e(
                                            $category['icon'] ?: '🏷️'
                                        ) ?>
                                    </span>

                                    <strong><?= e($category['name']) ?></strong>
                                </td>

                                <td>
                                    <span class="type-badge <?= e($category['type']) ?>">
                                        <?= $category['type'] === 'income'
                                            ? 'Pemasukan'
                                            : 'Pengeluaran' ?>
                                    </span>
                                </td>

                                <td>
                                    <?= e($category['transaction_count']) ?>
                                </td>

                                <td>
                                    <?= e($category['budget_count']) ?>
                                </td>

                                <td>
                                    <span class="category-status <?= (int) $category['is_active'] === 1
                                        ? 'active'
                                        : 'inactive' ?>">
                                        <?= (int) $category['is_active'] === 1
                                            ? 'Aktif'
                                            : 'Nonaktif' ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="action-buttons">

                                        <a
                                            href="categories.php?edit=<?= e($category['id']) ?>"
                                            class="btn btn-small btn-secondary"
                                        >
                                            Edit
                                        </a>

                                        <form
                                            method="POST"
                                            action="categories.php"
                                            onsubmit="return confirm('<?= (int) $category['is_active'] === 1
                                                ? 'Nonaktifkan'
                                                : 'Aktifkan kembali' ?> kategori ini?')"
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
                                                value="<?= e($category['id']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="target_status"
                                                value="<?= (int) $category['is_active'] === 1
                                                    ? '0'
                                                    : '1' ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-small <?= (int) $category['is_active'] === 1
                                                    ? 'btn-danger'
                                                    : 'btn-secondary' ?>"
                                            >
                                                <?= (int) $category['is_active'] === 1
                                                    ? 'Nonaktifkan'
                                                    : 'Aktifkan' ?>
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
                Kategori nonaktif tidak dapat dipilih untuk transaksi baru,
                tetapi transaksi dan anggaran lama tetap tersimpan.
            </div>

        </section>

        <footer class="page-footer">
            FinTrack · Personal Expense Tracker
        </footer>

    </main>

</div>

</body>
</html>
