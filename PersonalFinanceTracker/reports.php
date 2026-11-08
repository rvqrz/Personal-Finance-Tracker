
<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pdo = getPDO();

$today = date('Y-m-d');

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
| Filter tanggal laporan
|--------------------------------------------------------------------------
*/

$defaultFrom = max(date('Y-m-01'), $openingDate);
$defaultTo = $today;

$rawFrom = $_GET['from'] ?? $defaultFrom;
$rawTo = $_GET['to'] ?? $defaultTo;

$notice = null;

if (
    !is_string($rawFrom) ||
    !is_string($rawTo) ||
    !isValidDate($rawFrom) ||
    !isValidDate($rawTo)
) {
    $from = $defaultFrom;
    $to = $defaultTo;

    $notice = [
        'error',
        'Format tanggal tidak valid. Periode laporan dikembalikan ke pengaturan awal.'
    ];

} elseif ($rawFrom > $today || $rawTo < $openingDate) {
    $from = $defaultFrom;
    $to = $defaultTo;

    $notice = [
        'error',
        'Tanggal laporan berada di luar periode pencatatan.'
    ];

} else {
    $from = max($rawFrom, $openingDate);
    $to = min($rawTo, $today);

    if ($from > $to) {
        $from = $defaultFrom;
        $to = $defaultTo;

        $notice = [
            'error',
            'Tanggal awal tidak boleh melewati tanggal akhir.'
        ];
    } elseif ($from !== $rawFrom || $to !== $rawTo) {
        $notice = [
            'success',
            'Rentang laporan disesuaikan dengan periode pencatatan yang tersedia.'
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Ringkasan pemasukan dan pengeluaran
|--------------------------------------------------------------------------
*/

$summaryStmt = $pdo->prepare(
    "SELECT
        COALESCE(
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END),
            0
        ) AS total_income,

        COALESCE(
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END),
            0
        ) AS total_expense,

        COUNT(*) AS transaction_count

     FROM transactions

     WHERE transaction_date >= :from_date
       AND transaction_date <= :to_date"
);

$summaryStmt->execute([
    ':from_date' => $from,
    ':to_date' => $to,
]);

$summary = $summaryStmt->fetch();

$totalIncome = (int) $summary['total_income'];
$totalExpense = (int) $summary['total_expense'];
$totalTransactions = (int) $summary['transaction_count'];

$netMovement = $totalIncome - $totalExpense;

$averageExpense = $totalTransactions > 0
    ? $totalExpense
    : 0;

/*
|--------------------------------------------------------------------------
| Pengeluaran berdasarkan kategori
|--------------------------------------------------------------------------
*/

$categoryStmt = $pdo->prepare(
    "SELECT
        c.id,
        c.name AS category_name,
        SUM(t.amount) AS total_amount,
        COUNT(t.id) AS transaction_count

     FROM transactions t

     INNER JOIN categories c
        ON c.id = t.category_id

     WHERE t.type = 'expense'
       AND t.transaction_date >= :from_date
       AND t.transaction_date <= :to_date

     GROUP BY c.id, c.name
     ORDER BY total_amount DESC"
);

$categoryStmt->execute([
    ':from_date' => $from,
    ':to_date' => $to,
]);

$categoryRows = $categoryStmt->fetchAll();

$categoryLabels = [];
$categoryAmounts = [];

foreach ($categoryRows as $row) {
    $categoryLabels[] = $row['category_name'];
    $categoryAmounts[] = (int) $row['total_amount'];
}

/*
|--------------------------------------------------------------------------
| Tren pemasukan dan pengeluaran per bulan
|--------------------------------------------------------------------------
*/

$firstMonth = (new DateTimeImmutable($from))
    ->modify('first day of this month');

$lastMonth = (new DateTimeImmutable($to))
    ->modify('first day of this month');

$monthlyStmt = $pdo->prepare(
    "SELECT
        substr(transaction_date, 1, 7) AS month_key,

        COALESCE(
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END),
            0
        ) AS total_income,

        COALESCE(
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END),
            0
        ) AS total_expense

     FROM transactions

     WHERE transaction_date >= :from_date
       AND transaction_date <= :to_date

     GROUP BY substr(transaction_date, 1, 7)
     ORDER BY month_key ASC"
);

$monthlyStmt->execute([
    ':from_date' => $from,
    ':to_date' => $to,
]);

$monthlyRows = $monthlyStmt->fetchAll();

$monthlyMap = [];

foreach ($monthlyRows as $row) {
    $monthlyMap[$row['month_key']] = [
        'income' => (int) $row['total_income'],
        'expense' => (int) $row['total_expense'],
    ];
}

$monthNames = [
    1 => 'Jan',
    2 => 'Feb',
    3 => 'Mar',
    4 => 'Apr',
    5 => 'Mei',
    6 => 'Jun',
    7 => 'Jul',
    8 => 'Agu',
    9 => 'Sep',
    10 => 'Okt',
    11 => 'Nov',
    12 => 'Des',
];

$monthlyLabels = [];
$monthlyIncome = [];
$monthlyExpense = [];

$cursor = $firstMonth;

while ($cursor <= $lastMonth) {
    $monthKey = $cursor->format('Y-m');

    $monthNumber = (int) $cursor->format('n');
    $year = $cursor->format('Y');

    $monthlyLabels[] = $monthNames[$monthNumber] . ' ' . $year;

    $monthlyIncome[] = $monthlyMap[$monthKey]['income'] ?? 0;
    $monthlyExpense[] = $monthlyMap[$monthKey]['expense'] ?? 0;

    $cursor = $cursor->modify('+1 month');
}

/*
|--------------------------------------------------------------------------
| Sepuluh transaksi terbaru dalam periode laporan
|--------------------------------------------------------------------------
*/

$transactionStmt = $pdo->prepare(
    "SELECT
        t.id,
        t.type,
        t.amount,
        t.description,
        t.transaction_date,
        t.payment_method,
        c.name AS category_name

     FROM transactions t

     INNER JOIN categories c
        ON c.id = t.category_id

     WHERE t.transaction_date >= :from_date
       AND t.transaction_date <= :to_date

     ORDER BY t.transaction_date DESC, t.id DESC
     LIMIT 10"
);

$transactionStmt->execute([
    ':from_date' => $from,
    ':to_date' => $to,
]);

$recentTransactions = $transactionStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Informasi tampilan
|--------------------------------------------------------------------------
*/

$fromDisplay = date('d/m/Y', strtotime($from));
$toDisplay = date('d/m/Y', strtotime($to));

$exportUrl = 'actions/export-report.php?from='
    . rawurlencode($from)
    . '&to='
    . rawurlencode($to);

$csrf = csrfToken();

$jsonFlags = JSON_HEX_TAG
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
    | JSON_HEX_AMP;

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Keuangan | FinTrack</title>
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

            <a href="reports.php" class="nav-item active">
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
                <span class="breadcrumb">FinTrack / Laporan</span>
                <h1>Laporan Keuangan</h1>
            </div>

            <span class="local-badge">Lokal</span>
        </header>

        <?php if ($notice !== null): ?>
            <div class="alert alert-<?= e($notice[0]) ?>">
                <?= e($notice[1]) ?>
            </div>
        <?php endif; ?>

        <section class="page-intro">
            <h2>Analisis kondisi keuanganmu</h2>
            <p>
                Lihat perkembangan pemasukan dan pengeluaran
                berdasarkan periode yang kamu tentukan.
            </p>
        </section>

        <!-- FILTER PERIODE -->

        <section class="card">

            <div class="section-heading">
                <div>
                    <h2>Filter Periode Laporan</h2>
                    <p>Pilih tanggal awal dan akhir untuk membuat laporan.</p>
                </div>
            </div>

            <form method="GET" action="reports.php" class="report-filter-form">

                <div class="form-group">
                    <label for="from">Dari tanggal</label>

                    <input
                        type="date"
                        id="from"
                        name="from"
                        min="<?= e($openingDate) ?>"
                        max="<?= e($today) ?>"
                        value="<?= e($from) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="to">Sampai tanggal</label>

                    <input
                        type="date"
                        id="to"
                        name="to"
                        min="<?= e($openingDate) ?>"
                        max="<?= e($today) ?>"
                        value="<?= e($to) ?>"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-secondary">
                    Tampilkan Laporan
                </button>

                <a href="reports.php" class="btn btn-text">
                    Reset
                </a>

            </form>

        </section>

        <!-- PERIODE AKTIF -->

        <div class="report-period-note">
            Periode laporan:
            <strong><?= e($fromDisplay) ?></strong>
            sampai
            <strong><?= e($toDisplay) ?></strong>
        </div>

        <!-- RINGKASAN -->

        <section class="dashboard-stat-grid">

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Total pemasukan</span>
                    <span class="stat-icon income-icon">↗</span>
                </div>

                <div class="stat-value income-text">
                    <?= e(formatRupiah($totalIncome)) ?>
                </div>

                <div class="stat-note">
                    Seluruh pemasukan dalam periode pilihan
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Total pengeluaran</span>
                    <span class="stat-icon expense-icon">↘</span>
                </div>

                <div class="stat-value expense-text">
                    <?= e(formatRupiah($totalExpense)) ?>
                </div>

                <div class="stat-note">
                    Seluruh pengeluaran dalam periode pilihan
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Selisih keuangan</span>
                    <span class="stat-icon">Rp</span>
                </div>

                <div class="stat-value <?= $netMovement >= 0
                    ? 'income-text'
                    : 'expense-text' ?>">
                    <?= $netMovement < 0 ? '−' : '' ?>
                    <?= e(formatRupiah(abs($netMovement))) ?>
                </div>

                <div class="stat-note">
                    Pemasukan dikurangi pengeluaran
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Jumlah transaksi</span>
                    <span class="stat-icon count-icon">#</span>
                </div>

                <div class="stat-value">
                    <?= e($totalTransactions) ?>
                </div>

                <div class="stat-note">
                    Seluruh transaksi dalam periode pilihan
                </div>
            </article>

        </section>

        <!-- GRAFIK -->

        <section class="dashboard-chart-grid">

            <article class="card chart-card">

                <div class="section-heading">
                    <div>
                        <h2>Tren Keuangan Bulanan</h2>
                        <p>
                            Perbandingan pemasukan dan pengeluaran
                            dari setiap bulan dalam periode laporan.
                        </p>
                    </div>
                </div>

                <div class="chart-container">
                    <canvas id="reportMonthlyChart"></canvas>
                </div>

            </article>

            <article class="card chart-card">

                <div class="section-heading">
                    <div>
                        <h2>Komposisi Pengeluaran</h2>
                        <p>
                            Distribusi pengeluaran berdasarkan kategori.
                        </p>
                    </div>
                </div>

                <div class="chart-container doughnut-container">

                    <canvas id="reportCategoryChart"></canvas>

                    <?php if (count($categoryRows) === 0): ?>
                        <div class="chart-empty">
                            Belum ada pengeluaran pada periode ini.
                        </div>
                    <?php endif; ?>

                </div>

            </article>

        </section>

        <!-- RINCIAN KATEGORI -->

        <section class="card">

            <div class="section-heading">
                <div>
                    <h2>Rincian Pengeluaran per Kategori</h2>
                    <p>
                        Menampilkan kategori berdasarkan nominal
                        pengeluaran terbesar.
                    </p>
                </div>
            </div>

            <div class="table-wrapper">

                <table class="transaction-table">

                    <thead>
                        <tr>
                            <th>Kategori</th>
                            <th>Total Pengeluaran</th>
                            <th>Jumlah Transaksi</th>
                            <th>Persentase</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (count($categoryRows) === 0): ?>

                        <tr>
                            <td colspan="4" class="empty-state">
                                Tidak ada data pengeluaran dalam periode ini.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($categoryRows as $category): ?>

                            <?php
                            $categoryTotal = (int) $category['total_amount'];

                            $percentage = $totalExpense > 0
                                ? ($categoryTotal / $totalExpense) * 100
                                : 0;
                            ?>

                            <tr>
                                <td class="description-cell">
                                    <?= e($category['category_name']) ?>
                                </td>

                                <td class="amount-cell expense">
                                    <?= e(formatRupiah($categoryTotal)) ?>
                                </td>

                                <td>
                                    <?= e($category['transaction_count']) ?>
                                </td>

                                <td class="category-share-cell">

                                    <div class="category-share">
                                        <div
                                            style="width: <?= e((string) $percentage) ?>%;"
                                        ></div>
                                    </div>

                                    <span>
                                        <?= number_format(
                                            $percentage,
                                            1,
                                            ',',
                                            '.'
                                        ) ?>%
                                    </span>

                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

        <!-- TRANSAKSI TERBARU -->

        <section class="card">

            <div class="section-heading">
                <div>
                    <h2>Riwayat Transaksi</h2>
                    <p>
                        Sepuluh transaksi terbaru dalam periode laporan.
                    </p>
                </div>
            </div>

            <div class="table-wrapper">

                <table class="transaction-table">

                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Deskripsi</th>
                            <th>Kategori</th>
                            <th>Jenis</th>
                            <th>Nominal</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (count($recentTransactions) === 0): ?>

                        <tr>
                            <td colspan="5" class="empty-state">
                                Tidak ada transaksi dalam periode ini.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($recentTransactions as $transaction): ?>

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
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <div class="table-footer">
                Maksimal 10 transaksi terbaru ditampilkan di halaman laporan.
                Ekspor CSV mencakup seluruh transaksi dalam periode pilihan.
            </div>

        </section>

        <!-- EKSPOR -->

        <section class="card report-export-card">

            <div>
                <h2>Unduh Laporan</h2>
                <p>
                    Ekspor seluruh transaksi pada periode yang telah dipilih
                    ke format CSV.
                </p>
            </div>

            <a href="<?= e($exportUrl) ?>" class="btn btn-primary">
                <span>↓</span> Ekspor CSV
            </a>

        </section>

        <footer class="page-footer">
            FinTrack · Personal Expense Tracker
        </footer>

    </main>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
const monthlyLabels = <?= json_encode($monthlyLabels, $jsonFlags) ?>;
const monthlyIncome = <?= json_encode($monthlyIncome, $jsonFlags) ?>;
const monthlyExpense = <?= json_encode($monthlyExpense, $jsonFlags) ?>;

const categoryLabels = <?= json_encode($categoryLabels, $jsonFlags) ?>;
const categoryAmounts = <?= json_encode($categoryAmounts, $jsonFlags) ?>;

const moneyFormatter = new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0
});

const compactFormatter = new Intl.NumberFormat("id-ID", {
    notation: "compact",
    maximumFractionDigits: 1
});

if (typeof Chart !== "undefined") {

    const monthlyCanvas = document.getElementById("reportMonthlyChart");

    if (monthlyCanvas) {
        new Chart(monthlyCanvas, {
            type: "bar",

            data: {
                labels: monthlyLabels,

                datasets: [
                    {
                        label: "Pemasukan",
                        data: monthlyIncome,
                        backgroundColor: "#347c68",
                        borderRadius: 5
                    },
                    {
                        label: "Pengeluaran",
                        data: monthlyExpense,
                        backgroundColor: "#d47570",
                        borderRadius: 5
                    }
                ]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,

                plugins: {
                    legend: {
                        position: "bottom",
                        labels: {
                            usePointStyle: true,
                            padding: 18
                        }
                    },

                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.dataset.label + ": "
                                    + moneyFormatter.format(context.parsed.y);
                            }
                        }
                    }
                },

                scales: {
                    y: {
                        beginAtZero: true,

                        ticks: {
                            callback: function (value) {
                                return "Rp" + compactFormatter.format(value);
                            }
                        },

                        grid: {
                            color: "#edf0ed"
                        }
                    },

                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    const categoryCanvas = document.getElementById("reportCategoryChart");

    if (categoryCanvas && categoryLabels.length > 0) {
        new Chart(categoryCanvas, {
            type: "doughnut",

            data: {
                labels: categoryLabels,

                datasets: [{
                    data: categoryAmounts,

                    backgroundColor: [
                        "#234f43",
                        "#548b72",
                        "#93b5a1",
                        "#d7b879",
                        "#d98b83",
                        "#9b91bb",
                        "#7194b8",
                        "#bdc9bf"
                    ],

                    borderColor: "#ffffff",
                    borderWidth: 2
                }]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: "65%",

                plugins: {
                    legend: {
                        position: "bottom",
                        labels: {
                            usePointStyle: true,
                            padding: 15
                        }
                    },

                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.label + ": "
                                    + moneyFormatter.format(context.parsed);
                            }
                        }
                    }
                }
            }
        });
    }
}
</script>

</body>
</html>
