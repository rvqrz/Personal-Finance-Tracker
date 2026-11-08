<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pdo = getPDO();
$notice = null;

if (($_GET['restore'] ?? '') === 'success') {
    $notice = [
        'success',
        'Database FinTrack berhasil dipulihkan.'
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    $balanceRaw = $_POST['opening_balance'] ?? '';
    $dateRaw = $_POST['opening_balance_date'] ?? '';

    if (!is_string($balanceRaw) || !is_string($dateRaw)) {
        $notice = ['error', 'Data pengaturan tidak valid.'];
    } else {
        $balance = filter_var($balanceRaw, FILTER_VALIDATE_INT);

        if (
            $balance === false ||
            $balance < 0 ||
            $balance > 9000000000000000 ||
            !isValidDate($dateRaw) ||
            $dateRaw > date('Y-m-d')
        ) {
            $notice = [
                'error',
                'Saldo harus berupa bilangan bulat nonnegatif dan tanggal mulai tidak boleh melewati hari ini.'
            ];
        } else {

            try {
                // Cari tanggal paling awal dari seluruh transaksi dan aktivitas tabungan.
                $earliestStmt = $pdo->query(
                    "SELECT MIN(record_date)
         FROM (
             SELECT transaction_date AS record_date
             FROM transactions

             UNION ALL

             SELECT contribution_date AS record_date
             FROM savings_contributions
         )"
                );

                $earliestRecordDate = $earliestStmt->fetchColumn();

                // Jangan izinkan tanggal mulai melewati catatan yang sudah ada.
                if (
                    $earliestRecordDate !== false &&
                    $earliestRecordDate !== null &&
                    $dateRaw > $earliestRecordDate
                ) {
                    $notice = [
                        'error',
                        'Tanggal mulai pencatatan tidak boleh melewati tanggal transaksi atau aktivitas tabungan paling awal (' .
                        $earliestRecordDate .
                        ').'
                    ];
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE app_settings
                        SET
                            opening_balance = :balance,
                            opening_balance_date = :opening_date,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = 1'
                    );

                    $stmt->execute([
                        ':balance' => $balance,
                        ':opening_date' => $dateRaw,
                    ]);

                    $notice = ['success', 'Pengaturan saldo awal berhasil disimpan.'];
                }

            } catch (Throwable $e) {
                error_log('Gagal menyimpan pengaturan: ' . $e->getMessage());

                $notice = [
                    'error',
                    'Pengaturan gagal disimpan. Periksa log PHP.'
                ];
            }

        }
    }
}

$settings = $pdo->query(
    'SELECT opening_balance, opening_balance_date, currency
     FROM app_settings
     WHERE id = 1'
)->fetch();

if (!$settings) {
    throw new RuntimeException(
        'Pengaturan aplikasi tidak ditemukan. Jalankan ulang inisialisasi database.'
    );
}

$openingBalanceInput = (string) (
    $_POST['opening_balance'] ?? $settings['opening_balance']
);

$openingDateInput = (string) (
    $_POST['opening_balance_date'] ?? $settings['opening_balance_date']
);

$csrf = csrfToken();

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan | FinTrack</title>
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

                <a href="categories.php" class="nav-item">
                    <span>▤</span> Kategori
                </a>

                <a href="reports.php" class="nav-item">
                    <span>▥</span> Laporan
                </a>

                <a href="settings.php" class="nav-item active">
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
                    <span class="breadcrumb">FinTrack / Pengaturan</span>
                    <h1>Pengaturan</h1>
                </div>

                <span class="local-badge">Lokal</span>
            </header>

            <?php if ($notice !== null): ?>
                <div class="alert alert-<?= e($notice[0]) ?>">
                    <?= e($notice[1]) ?>
                </div>
            <?php endif; ?>

            <section class="page-intro">
                <h2>Saldo awal keuangan</h2>
                <p>
                    Tentukan jumlah uang yang tersedia ketika kamu mulai
                    menggunakan aplikasi.
                </p>
            </section>

            <section class="card settings-card">

                <div class="section-heading">
                    <div>
                        <h2>Saldo awal dan tanggal pencatatan</h2>
                        <p>
                            Pengaturan ini menjadi dasar perhitungan saldo dashboard.
                        </p>
                    </div>
                </div>

                <form method="POST" action="settings.php">

                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="opening_balance">
                                Saldo awal (Rupiah)
                            </label>

                            <div class="input-prefix">
                                <span>Rp</span>

                                <input type="number" id="opening_balance" name="opening_balance" min="0"
                                    max="9000000000000000" step="1" value="<?= e($openingBalanceInput) ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="opening_balance_date">
                                Tanggal mulai pencatatan
                            </label>

                            <input type="date" id="opening_balance_date" name="opening_balance_date"
                                max="<?= e(date('Y-m-d')) ?>" value="<?= e($openingDateInput) ?>" required>
                        </div>

                    </div>

                    <div class="settings-note">
                        <strong>Perhatian</strong>
                        <p>
                            Transaksi sebelum tanggal mulai pencatatan tidak
                            dihitung dalam saldo dashboard. Transaksi pada
                            tanggal tersebut dan setelahnya akan diperhitungkan.
                            Mengubah tanggal ini dapat mengubah saldo yang ditampilkan,
                            tetapi tidak menghapus transaksi.
                        </p>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            Simpan Pengaturan
                        </button>
                    </div>

                </form>

            </section>

            <section class="card settings-card">

                <div class="section-heading">
                    <div>
                        <h2>Backup Database</h2>
                        <p>
                            Unduh salinan database FinTrack untuk menyimpan
                            cadangan seluruh data keuangan.
                        </p>
                    </div>
                </div>

                <div class="settings-note">
                    <strong>Informasi</strong>
                    <p>
                        File backup berisi data saldo awal, transaksi,
                        kategori, anggaran, transaksi berulang, dan tabungan.
                        Simpan file tersebut di tempat yang aman.
                    </p>
                </div>

                <form method="POST" action="backup.php">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf) ?>"
                    >

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            Unduh Backup Database
                        </button>
                    </div>
                </form>

            </section>

            <section class="card settings-card">

                <div class="section-heading">
                    <div>
                        <h2>Restore Database</h2>
                        <p>
                            Pulihkan data FinTrack menggunakan file backup
                            yang sebelumnya telah dibuat.
                        </p>
                    </div>
                </div>

                <div class="settings-note">
                    <strong>Perhatian: Data akan diganti</strong>
                    <p>
                        Proses restore akan mengganti seluruh database aktif
                        dengan isi file backup. Transaksi, saldo awal, kategori,
                        anggaran, transaksi berulang, dan data tabungan akan
                        mengikuti kondisi pada file backup.
                    </p>
                    <p>
                        Database saat ini akan dicadangkan secara otomatis
                        sebelum proses pemulihan dilakukan.
                    </p>
                </div>

                <form method="POST" action="restore.php" enctype="multipart/form-data">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrf) ?>"
                    >

                    <div class="form-group">
                        <label for="backup_file">
                            Pilih file backup FinTrack (.sqlite)
                        </label>

                        <input
                            type="file"
                            id="backup_file"
                            name="backup_file"
                            accept=".sqlite"
                            required
                        >
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            Restore Database
                        </button>
                    </div>

                </form>

            </section>

            <section class="card">
                <div class="section-heading">
                    <div>
                        <h2>Konsep perhitungan saldo</h2>
                        <p>
                            Saldo awal ditambahkan dengan pemasukan, kemudian dikurangi
                            pengeluaran dan alokasi bersih tabungan selama periode pencatatan.
                        </p>
                    </div>
                </div>

                <div class="balance-formula">
                    <div>
                        <span>Saldo awal</span>
                        <strong>+</strong>
                    </div>

                    <div>
                        <span>Total pemasukan</span>
                        <strong>−</strong>
                    </div>

                    <div>
                        <span>Total pengeluaran</span>
                        <strong>−</strong>
                    </div>

                    <div>
                        <span>Alokasi bersih tabungan</span>
                        <strong>=</strong>
                    </div>

                    <div>
                        <span>Saldo tersedia</span>
                    </div>
                </div>
            </section>

            <footer class="page-footer">
                FinTrack · Personal Expense Tracker
            </footer>

        </main>

    </div>

</body>

</html>
