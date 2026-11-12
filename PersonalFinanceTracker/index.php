
<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pdo = getPDO();

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

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

$openingBalance = (int) $settings['opening_balance'];
$openingDate = (string) $settings['opening_balance_date'];

/*
|--------------------------------------------------------------------------
| Periode pencatatan
|--------------------------------------------------------------------------
| Hanya transaksi pada atau setelah tanggal mulai pencatatan
| dan tidak melewati tanggal hari ini yang ikut dihitung.
*/

$periodStart = max($monthStart, $openingDate);

/*
|--------------------------------------------------------------------------
| Saldo saat ini
|--------------------------------------------------------------------------
*/

$balanceStmt = $pdo->prepare(
    "SELECT
        COALESCE(
            SUM(
                CASE
                    WHEN type = 'income' THEN amount
                    WHEN type = 'expense' THEN -amount
                    ELSE 0
                END
            ),
            0
        ) AS net_transactions
     FROM transactions
     WHERE transaction_date >= :opening_date
       AND transaction_date <= :today"
);

$balanceStmt->execute([
    ':opening_date' => $openingDate,
    ':today' => $today,
]);

$currentBalance = getAvailableBalance(
    $pdo,
    $openingBalance,
    $openingDate,
    $today
);
/*
|--------------------------------------------------------------------------
| Ringkasan bulan berjalan
|--------------------------------------------------------------------------
*/

$monthStmt = $pdo->prepare(
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
     WHERE transaction_date >= :period_start
       AND transaction_date <= :today"
);

$monthStmt->execute([
    ':period_start' => $periodStart,
    ':today' => $today,
]);

$monthSummary = $monthStmt->fetch();

$incomeThisMonth = (int) $monthSummary['total_income'];
$expenseThisMonth = (int) $monthSummary['total_expense'];
$transactionCountThisMonth = (int) $monthSummary['transaction_count'];


/*
|--------------------------------------------------------------------------
| Ringkasan anggaran bulan berjalan
|--------------------------------------------------------------------------
*/

$dashboardBudgetMonth = date('Y-m');

$dashboardBudgetStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(amount), 0)
     FROM budgets
     WHERE period_month = :period_month'
);

$dashboardBudgetStmt->execute([
    ':period_month' => $dashboardBudgetMonth,
]);

$dashboardBudgetTotal = (int) $dashboardBudgetStmt->fetchColumn();

$dashboardSpentStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(t.amount), 0)

     FROM transactions t

     INNER JOIN budgets b
        ON b.category_id = t.category_id
       AND b.period_month = :period_month

     WHERE t.type = 'expense'
       AND t.transaction_date >= :period_start
       AND t.transaction_date <= :today"
);

$dashboardSpentStmt->execute([
    ':period_month' => $dashboardBudgetMonth,
    ':period_start' => $periodStart,
    ':today' => $today,
]);

$dashboardBudgetSpent = (int) $dashboardSpentStmt->fetchColumn();

$dashboardBudgetRemaining =
    $dashboardBudgetTotal - $dashboardBudgetSpent;

$dashboardBudgetPercent = $dashboardBudgetTotal > 0
    ? ($dashboardBudgetSpent / $dashboardBudgetTotal) * 100
    : 0;

$dashboardBudgetBarPercent = min(
    100,
    $dashboardBudgetPercent
);

if (
    $dashboardBudgetPercent > 100 &&
    $dashboardBudgetTotal > 0
) {
    $dashboardBudgetState = 'exceeded';
    $dashboardBudgetStateLabel = 'Anggaran terlampaui';
} elseif (
    $dashboardBudgetPercent >= 100 &&
    $dashboardBudgetTotal > 0
) {
    $dashboardBudgetState = 'warning';
    $dashboardBudgetStateLabel = 'Batas anggaran tercapai';
} elseif ($dashboardBudgetPercent >= 90) {
    $dashboardBudgetState = 'warning';
    $dashboardBudgetStateLabel = 'Anggaran hampir habis';
} else {
    $dashboardBudgetState = 'normal';
    $dashboardBudgetStateLabel = 'Anggaran masih tersedia';
}


/*
|--------------------------------------------------------------------------
| Grafik pemasukan dan pengeluaran selama enam bulan
|--------------------------------------------------------------------------
*/

$graphStartDate = (new DateTimeImmutable('first day of this month'))
    ->modify('-5 months');

$graphStart = $graphStartDate->format('Y-m-01');

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
     WHERE transaction_date >= :graph_start
       AND transaction_date >= :opening_date
       AND transaction_date <= :today
     GROUP BY substr(transaction_date, 1, 7)
     ORDER BY month_key ASC"
);

$monthlyStmt->execute([
    ':graph_start' => $graphStart,
    ':opening_date' => $openingDate,
    ':today' => $today,
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

$chartLabels = [];
$chartIncome = [];
$chartExpense = [];

for ($i = 0; $i < 6; $i++) {
    $monthDate = $graphStartDate->modify("+{$i} months");
    $monthKey = $monthDate->format('Y-m');

    $monthNumber = (int) $monthDate->format('n');
    $year = $monthDate->format('Y');

    $chartLabels[] = $monthNames[$monthNumber] . ' ' . $year;

    $chartIncome[] = $monthlyMap[$monthKey]['income'] ?? 0;
    $chartExpense[] = $monthlyMap[$monthKey]['expense'] ?? 0;
}

/*
|--------------------------------------------------------------------------
| Pengeluaran berdasarkan kategori bulan berjalan
|--------------------------------------------------------------------------
*/

$categoryStmt = $pdo->prepare(
    "SELECT
        c.name AS category_name,
        SUM(t.amount) AS total_amount

     FROM transactions t

     INNER JOIN categories c
        ON c.id = t.category_id

     WHERE t.type = 'expense'
       AND t.transaction_date >= :period_start
       AND t.transaction_date <= :today

     GROUP BY c.id, c.name
     ORDER BY total_amount DESC"
);

$categoryStmt->execute([
    ':period_start' => $periodStart,
    ':today' => $today,
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
| Lima transaksi terbaru
|--------------------------------------------------------------------------
*/

$recentStmt = $pdo->prepare(
    "SELECT
        t.id,
        t.type,
        t.amount,
        t.description,
        t.transaction_date,
        c.name AS category_name

     FROM transactions t

     INNER JOIN categories c
        ON c.id = t.category_id

     WHERE t.transaction_date >= :opening_date
       AND t.transaction_date <= :today

     ORDER BY t.transaction_date DESC, t.id DESC
     LIMIT 5"
);

$recentStmt->execute([
    ':opening_date' => $openingDate,
    ':today' => $today,
]);

$recentTransactions = $recentStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Data format tampilan
|--------------------------------------------------------------------------
*/

$balanceDisplay = ($currentBalance < 0 ? '-' : '')
    . formatRupiah(abs($currentBalance));

$openingDateDisplay = date('d/m/Y', strtotime($openingDate));

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
    <title>Dashboard | FinTrack</title>
    <link rel="stylesheet" href="assets/css/style.css?v=20261113-dashboard-summary">
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
            <a href="index.php" class="nav-item active">
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
                <span class="breadcrumb">FinTrack / Dashboard</span>
                <h1>Dashboard</h1>
            </div>

            <a href="transactions.php" class="btn btn-primary">
                + Tambah Transaksi
            </a>
        </header>

        <section class="page-intro dashboard-intro">
            <div>
                <h2>Ringkasan keuanganmu</h2>
                <p>
                    Pantau saldo, pemasukan, dan pengeluaran secara berkala.
                </p>
            </div>

            <a href="settings.php" class="settings-link">
                ⚙ Atur saldo awal
            </a>
        </section>

        <div class="opening-info">
            Saldo dihitung sejak
            <strong><?= e($openingDateDisplay) ?></strong>.
            Transaksi sebelum tanggal tersebut tidak termasuk perhitungan saldo.
        </div>

        <section class="dashboard-stat-grid">

            <article class="stat-card balance-stat">
                <div class="stat-topline">
                    <span>Saldo saat ini</span>
                    <span class="stat-icon">Rp</span>
                </div>

                <div class="stat-value">
                    <?= e($balanceDisplay) ?>
                </div>

                <div class="stat-note">
                    Saldo awal + pemasukan − pengeluaran − alokasi tabungan
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Pemasukan bulan ini</span>
                    <span class="stat-icon income-icon">↗</span>
                </div>

                <div class="stat-value income-text">
                    <?= e(formatRupiah($incomeThisMonth)) ?>
                </div>

                <div class="stat-note">
                    Berdasarkan transaksi pada periode pencatatan
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Pengeluaran bulan ini</span>
                    <span class="stat-icon expense-icon">↘</span>
                </div>

                <div class="stat-value expense-text">
                    <?= e(formatRupiah($expenseThisMonth)) ?>
                </div>

                <div class="stat-note">
                    Berdasarkan transaksi pada periode pencatatan
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-topline">
                    <span>Transaksi bulan ini</span>
                    <span class="stat-icon count-icon">#</span>
                </div>

                <div class="stat-value">
                    <?= e($transactionCountThisMonth) ?>
                </div>

                <div class="stat-note">
                    Jumlah transaksi yang tercatat
                </div>
            </article>

        </section>


<section class="card dashboard-budget-summary">

    <div class="budget-summary-top">
        <div>
            <h2>Anggaran Bulan Ini</h2>
            <p>
                Pemakaian anggaran berdasarkan kategori yang
                telah kamu tetapkan.
            </p>
        </div>

        <a href="budgets.php" class="settings-link">
            Kelola anggaran →
        </a>
    </div>

    <?php if ($dashboardBudgetTotal === 0): ?>

        <div class="settings-note">
            <strong>Belum ada anggaran untuk bulan ini.</strong>
            <p>
                Buat anggaran kategori makanan, transportasi, atau
                kebutuhan lainnya agar penggunaan uang lebih terarah.
            </p>

            <p style="margin-top: 12px;">
                <a href="budgets.php" class="settings-link">
                    + Buat Anggaran
                </a>
            </p>
        </div>

    <?php else: ?>

        <div class="budget-summary-numbers">

            <div>
                <span>Total anggaran</span>
                <strong>
                    <?= e(formatRupiah($dashboardBudgetTotal)) ?>
                </strong>
            </div>

            <div>
                <span>Terpakai</span>
                <strong class="expense-text">
                    <?= e(formatRupiah($dashboardBudgetSpent)) ?>
                </strong>
            </div>

            <div>
                <span>Sisa anggaran</span>
                <strong class="<?= $dashboardBudgetRemaining < 0
                    ? 'expense-text'
                    : '' ?>">
                    <?= $dashboardBudgetRemaining < 0 ? '−' : '' ?>
                    <?= e(formatRupiah(abs($dashboardBudgetRemaining))) ?>
                </strong>
            </div>

        </div>

        <div class="budget-summary-progress">
            <div
                class="<?= e($dashboardBudgetState) ?>"
                style="width: <?= e((string) $dashboardBudgetBarPercent) ?>%;"
            ></div>
        </div>

        <div class="budget-summary-footer">
            <span>
                <?= number_format(
                    $dashboardBudgetPercent,
                    1,
                    ',',
                    '.'
                ) ?>% anggaran digunakan
            </span>

            <span class="budget-state <?= e($dashboardBudgetState) ?>">
                <?= e($dashboardBudgetStateLabel) ?>
            </span>
        </div>

    <?php endif; ?>

</section>


        <section class="dashboard-chart-grid">

            <article class="card chart-card">
                <div class="section-heading">
                    <div>
                        <h2>Pemasukan vs Pengeluaran</h2>
                        <p>Perbandingan enam bulan terakhir.</p>
                    </div>
                </div>

                <div class="chart-container">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </article>

            <article class="card chart-card">
                <div class="section-heading">
                    <div>
                        <h2>Pengeluaran per Kategori</h2>
                        <p>Komposisi pengeluaran bulan ini.</p>
                    </div>
                </div>

                <div class="chart-container doughnut-container">
                    <canvas id="categoryChart"></canvas>

                    <?php if (count($categoryRows) === 0): ?>
                        <div class="chart-empty">
                            Belum ada pengeluaran pada periode ini.
                        </div>
                    <?php endif; ?>
                </div>
            </article>

        </section>

        <section class="card recent-card">

            <div class="section-heading">
                <div>
                    <h2>Transaksi Terbaru</h2>
                    <p>Lima transaksi terakhir pada periode pencatatan.</p>
                </div>

                <a href="transactions.php" class="settings-link">
                    Lihat semua →
                </a>
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
                                Belum ada transaksi. Tambahkan transaksi pertamamu.
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

        </section>

        <footer class="page-footer">
            FinTrack · Personal Expense Tracker
        </footer>

    </main>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
const chartLabels = <?= json_encode($chartLabels, $jsonFlags) ?>;
const chartIncome = <?= json_encode($chartIncome, $jsonFlags) ?>;
const chartExpense = <?= json_encode($chartExpense, $jsonFlags) ?>;

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

const monthlyCanvas = document.getElementById("monthlyChart");

if (monthlyCanvas && typeof Chart !== "undefined") {
    new Chart(monthlyCanvas, {
        type: "line",

        data: {
            labels: chartLabels,

            datasets: [
                {
                    label: "Pemasukan",
                    data: chartIncome,
                    borderColor: "#347c68",
                    backgroundColor: "rgba(52, 124, 104, 0.12)",
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3
                },
                {
                    label: "Pengeluaran",
                    data: chartExpense,
                    borderColor: "#d47570",
                    backgroundColor: "rgba(212, 117, 112, 0.08)",
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3
                }
            ]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,

            interaction: {
                intersect: false,
                mode: "index"
            },

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

const categoryCanvas = document.getElementById("categoryChart");

if (
    categoryCanvas &&
    typeof Chart !== "undefined" &&
    categoryLabels.length > 0
) {
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

                borderWidth: 2,
                borderColor: "#ffffff"
            }]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: "66%",

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
</script>

</body>
</html>
