
<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pdo = getPDO();

$today = date('Y-m-d');

$settings = $pdo->query(
    'SELECT opening_balance, opening_balance_date
     FROM app_settings
     WHERE id = 1'
)->fetch();

if (!$settings) {
    throw new RuntimeException(
        'Pengaturan saldo awal tidak ditemukan.'
    );
}

$openingBalance = (int) $settings['opening_balance'];
$openingDate = (string) $settings['opening_balance_date'];

$successMessage = '';
$errorMessage = '';

/*
|--------------------------------------------------------------------------
| Pemrosesan formulir
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrfToken();

        $action = $_POST['action'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | Membuat target tabungan
        |--------------------------------------------------------------------------
        */

        if ($action === 'create_goal') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $targetAmount = filter_var(
                $_POST['target_amount'] ?? null,
                FILTER_VALIDATE_INT
            );

            $targetDate = trim(
                (string) ($_POST['target_date'] ?? '')
            );

            if ($name === '' || mb_strlen($name) > 100) {
                throw new InvalidArgumentException(
                    'Nama target wajib diisi dan maksimal 100 karakter.'
                );
            }

            if ($targetAmount === false || $targetAmount <= 0) {
                throw new InvalidArgumentException(
                    'Nominal target harus lebih besar dari nol.'
                );
            }

            if ($targetDate !== '') {
                if (!isValidDate($targetDate)) {
                    throw new InvalidArgumentException(
                        'Tanggal target tidak valid.'
                    );
                }

                if ($targetDate < $today) {
                    throw new InvalidArgumentException(
                        'Tanggal target tidak boleh sebelum hari ini.'
                    );
                }
            }

            $stmt = $pdo->prepare(
                'INSERT INTO savings_goals (
                    name,
                    target_amount,
                    target_date
                ) VALUES (
                    :name,
                    :target_amount,
                    :target_date
                )'
            );

            $stmt->execute([
                ':name' => $name,
                ':target_amount' => $targetAmount,
                ':target_date' => $targetDate !== ''
                    ? $targetDate
                    : null,
            ]);

            $successMessage = 'Target tabungan berhasil dibuat.';
        }

        /*
        |--------------------------------------------------------------------------
        | Setor atau tarik tabungan
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'movement') {
            $goalId = filter_var(
                $_POST['goal_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $amount = filter_var(
                $_POST['amount'] ?? null,
                FILTER_VALIDATE_INT
            );

            $type = (string) ($_POST['type'] ?? '');

            $description = trim(
                (string) ($_POST['description'] ?? '')
            );

            $contributionDate = trim(
                (string) ($_POST['contribution_date'] ?? '')
            );

            if ($goalId === false || $goalId <= 0) {
                throw new InvalidArgumentException(
                    'Target tabungan tidak valid.'
                );
            }

            if ($amount === false || $amount <= 0) {
                throw new InvalidArgumentException(
                    'Nominal harus lebih besar dari nol.'
                );
            }

            if (mb_strlen($description) > 255) {
                throw new InvalidArgumentException(
                    'Deskripsi maksimal 255 karakter.'
                );
            }

            recordSavingsMovement(
                $pdo,
                $goalId,
                $amount,
                $type,
                $description,
                $contributionDate
            );

            $successMessage = $type === 'deposit'
                ? 'Setoran tabungan berhasil dicatat.'
                : 'Penarikan tabungan berhasil dicatat.';
        }

        /*
        |--------------------------------------------------------------------------
        | Membatalkan target tabungan
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'cancel_goal') {
            $goalId = filter_var(
                $_POST['goal_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if ($goalId === false || $goalId <= 0) {
                throw new InvalidArgumentException(
                    'Target tabungan tidak valid.'
                );
            }

            $pdo->beginTransaction();

            try {
                $goalStmt = $pdo->prepare(
                    'SELECT id, status
                     FROM savings_goals
                     WHERE id = :id'
                );

                $goalStmt->execute([
                    ':id' => $goalId,
                ]);

                $goal = $goalStmt->fetch();

                if (!$goal) {
                    throw new RuntimeException(
                        'Target tabungan tidak ditemukan.'
                    );
                }

                if ($goal['status'] === 'cancelled') {
                    throw new RuntimeException(
                        'Target sudah dibatalkan.'
                    );
                }

                $goalBalance = getSavingsGoalBalance(
                    $pdo,
                    $goalId,
                    $openingDate,
                    $today
                );

                if ($goalBalance !== 0) {
                    throw new RuntimeException(
                        'Tarik seluruh saldo tabungan sebelum membatalkan target.'
                    );
                }

                $updateStmt = $pdo->prepare(
                    "UPDATE savings_goals
                     SET status = 'cancelled',
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );

                $updateStmt->execute([
                    ':id' => $goalId,
                ]);

                $pdo->commit();

                $successMessage = 'Target tabungan berhasil dibatalkan.';

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }
        }

        else {
            throw new InvalidArgumentException(
                'Jenis permintaan tidak dikenal.'
            );
        }

    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Ringkasan saldo
|--------------------------------------------------------------------------
*/

$availableBalance = getAvailableBalance(
    $pdo,
    $openingBalance,
    $openingDate,
    $today
);

$totalSavingsStmt = $pdo->prepare(
    "SELECT COALESCE(
        SUM(
            CASE
                WHEN sc.type = 'deposit' THEN sc.amount
                WHEN sc.type = 'withdrawal' THEN -sc.amount
                ELSE 0
            END
        ), 0
    )
    FROM savings_contributions sc
    INNER JOIN savings_goals sg
        ON sg.id = sc.goal_id
    WHERE sg.status != 'cancelled'
      AND sc.contribution_date >= :opening_date
      AND sc.contribution_date <= :today"
);

$totalSavingsStmt->execute([
    ':opening_date' => $openingDate,
    ':today' => $today,
]);

$totalSavings = (int) $totalSavingsStmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| Mengambil semua target tabungan
|--------------------------------------------------------------------------
*/

$goalsStmt = $pdo->prepare(
    "SELECT
        sg.id,
        sg.name,
        sg.target_amount,
        sg.target_date,
        sg.status,
        sg.created_at,

        COALESCE(
            SUM(
                CASE
                    WHEN sc.type = 'deposit' THEN sc.amount
                    WHEN sc.type = 'withdrawal' THEN -sc.amount
                    ELSE 0
                END
            ), 0
        ) AS current_amount

     FROM savings_goals sg

     LEFT JOIN savings_contributions sc
        ON sc.goal_id = sg.id
       AND sc.contribution_date >= :opening_date
       AND sc.contribution_date <= :today

     GROUP BY sg.id

     ORDER BY
        CASE sg.status
            WHEN 'active' THEN 1
            WHEN 'completed' THEN 2
            ELSE 3
        END,
        sg.created_at DESC"
);

$goalsStmt->execute([
    ':opening_date' => $openingDate,
    ':today' => $today,
]);

$goals = $goalsStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Mengambil riwayat aktivitas tabungan
|--------------------------------------------------------------------------
*/

$historyStmt = $pdo->prepare(
    "SELECT
        sc.id,
        sc.amount,
        sc.type,
        sc.description,
        sc.contribution_date,
        sg.name AS goal_name

     FROM savings_contributions sc

     INNER JOIN savings_goals sg
        ON sg.id = sc.goal_id

     ORDER BY sc.contribution_date DESC, sc.id DESC

     LIMIT 30"
);

$historyStmt->execute();

$history = $historyStmt->fetchAll();

$totalGoals = count(array_filter(
    $goals,
    fn($goal) => $goal['status'] !== 'cancelled'
));

$completedGoals = count(array_filter(
    $goals,
    fn($goal) => $goal['status'] === 'completed'
));

$openingDateDisplay = date(
    'd/m/Y',
    strtotime($openingDate)
);

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Savings Goals | FinTrack</title>

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
            <a href="savings.php" class="nav-item active">
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
                <span class="breadcrumb">FinTrack / Tabungan</span>
                <h1>Tabungan</h1>
            </div>

        </header>

        <section class="page-intro">

            <div>
                <h2>Kelola tujuan keuanganmu</h2>

                <p>
                    Buat target tabungan, pantau progres, dan kelola
                    alokasi uang yang kamu simpan.
                </p>
            </div>

        </section>

        <?php if ($successMessage !== ''): ?>

            <div class="settings-note">
                <?= e($successMessage) ?>
            </div>

        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>

            <div class="settings-note">
                <strong>Terjadi kesalahan</strong>
                <p><?= e($errorMessage) ?></p>
            </div>

        <?php endif; ?>

        <div class="opening-info">
            Saldo dan aktivitas tabungan dihitung sejak
            <strong><?= e($openingDateDisplay) ?></strong>.
        </div>

        <!-- Ringkasan -->

        <section class="dashboard-stat-grid">

            <article class="stat-card balance-stat">

                <div class="stat-topline">
                    <span>Saldo tersedia</span>
                    <span class="stat-icon">Rp</span>
                </div>

                <div class="stat-value">
                    <?= e(formatRupiah($availableBalance)) ?>
                </div>

                <div class="stat-note">
                    Uang yang belum dialokasikan ke tabungan
                </div>

            </article>

            <article class="stat-card">

                <div class="stat-topline">
                    <span>Total tabungan</span>
                    <span class="stat-icon income-icon">↗</span>
                </div>

                <div class="stat-value income-text">
                    <?= e(formatRupiah($totalSavings)) ?>
                </div>

                <div class="stat-note">
                    Total saldo pada target yang tidak dibatalkan
                </div>

            </article>

            <article class="stat-card">

                <div class="stat-topline">
                    <span>Target aktif</span>
                    <span class="stat-icon count-icon">#</span>
                </div>

                <div class="stat-value">
                    <?= e($totalGoals) ?>
                </div>

                <div class="stat-note">
                    Target yang masih dikelola
                </div>

            </article>

            <article class="stat-card">

                <div class="stat-topline">
                    <span>Target tercapai</span>
                    <span class="stat-icon income-icon">✓</span>
                </div>

                <div class="stat-value">
                    <?= e($completedGoals) ?>
                </div>

                <div class="stat-note">
                    Target yang sudah mencapai nominal tujuan
                </div>

            </article>

        </section>

        <!-- Form tambah target -->

        <section class="card">

            <div class="section-heading">
                <div>
                    <h2>Buat Target Tabungan</h2>

                    <p>
                        Tentukan tujuan dan nominal uang yang ingin
                        kamu kumpulkan.
                    </p>
                </div>
            </div>

            <form method="POST" action="savings.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrfToken()) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="create_goal"
                >

                <div class="form-grid">

                    <div class="form-group">

                        <label for="name">Nama Target</label>

                        <input
                            type="text"
                            id="name"
                            name="name"
                            maxlength="100"
                            placeholder="Contoh: Laptop baru"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label for="target_amount">
                            Nominal Target (Rp)
                        </label>

                        <input
                            type="number"
                            id="target_amount"
                            name="target_amount"
                            min="1"
                            step="1"
                            placeholder="Contoh: 5000000"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label for="target_date">
                            Tanggal Target (Opsional)
                        </label>

                        <input
                            type="date"
                            id="target_date"
                            name="target_date"
                            min="<?= e($today) ?>"
                        >

                    </div>

                </div>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    + Buat Target
                </button>

            </form>

        </section>

        <!-- Daftar target -->

        <section class="card">

            <div class="section-heading">

                <div>
                    <h2>Daftar Target Tabungan</h2>

                    <p>
                        Pantau jumlah uang yang sudah terkumpul
                        pada setiap target.
                    </p>
                </div>

            </div>

            <?php if (count($goals) === 0): ?>

                <div class="empty-state">
                    Belum ada target tabungan. Buat target pertamamu
                    melalui formulir di atas.
                </div>

            <?php else: ?>

                <div class="table-wrapper">

                    <table class="transaction-table">

                        <thead>
                            <tr>
                                <th>Nama Target</th>
                                <th>Progres</th>
                                <th>Terkumpul</th>
                                <th>Target</th>
                                <th>Tanggal</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($goals as $goal): ?>

                            <?php

                            $goalAmount = (int) $goal['target_amount'];
                            $currentAmount = (int) $goal['current_amount'];

                            $progress = $goalAmount > 0
                                ? min(100, max(
                                    0,
                                    ($currentAmount / $goalAmount) * 100
                                ))
                                : 0;

                            $goalStatusLabels = [
                                'active' => 'Aktif',
                                'completed' => 'Tercapai',
                                'cancelled' => 'Dibatalkan',
                            ];

                            ?>

                            <tr>

                                <td class="description-cell">
                                    <strong>
                                        <?= e($goal['name']) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= number_format(
                                        $progress,
                                        1,
                                        ',',
                                        '.'
                                    ) ?>%

                                    <div
                                        class="budget-summary-progress"
                                        style="margin-top: 8px;"
                                    >
                                        <div
                                            class="normal"
                                            style="width: <?= e((string) $progress) ?>%;"
                                        ></div>
                                    </div>
                                </td>

                                <td class="amount-cell income">
                                    <?= e(formatRupiah($currentAmount)) ?>
                                </td>

                                <td>
                                    <?= e(formatRupiah($goalAmount)) ?>
                                </td>

                                <td>
                                    <?= $goal['target_date']
                                        ? e(date(
                                            'd/m/Y',
                                            strtotime($goal['target_date'])
                                        ))
                                        : '-' ?>
                                </td>

                                <td>
                                    <span class="type-badge <?= e($goal['status']) ?>">
                                        <?= e(
                                            $goalStatusLabels[$goal['status']]
                                            ?? $goal['status']
                                        ) ?>
                                    </span>
                                </td>

                            </tr>

                            <?php if ($goal['status'] !== 'cancelled'): ?>

                                <tr>

                                    <td colspan="6">

                                        <details>

                                            <summary>
                                                Kelola target:
                                                <?= e($goal['name']) ?>
                                            </summary>

                                            <div style="padding: 16px 0;">

                                                <?php if ($goal['status'] === 'active'): ?>

                                                    <form
                                                        method="POST"
                                                        action="savings.php"
                                                    >

                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= e(csrfToken()) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="movement"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="goal_id"
                                                            value="<?= e((string) $goal['id']) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="type"
                                                            value="deposit"
                                                        >

                                                        <div class="form-group">

                                                            <label>
                                                                Nominal Setoran (Rp)
                                                            </label>

                                                            <input
                                                                type="number"
                                                                name="amount"
                                                                min="1"
                                                                max="<?= e((string) max(
                                                                    0,
                                                                    $availableBalance
                                                                )) ?>"
                                                                step="1"
                                                                placeholder="Nominal setoran"
                                                                required
                                                            >

                                                        </div>

                                                        <div class="form-group">

                                                            <label>
                                                                Tanggal Setoran
                                                            </label>

                                                            <input
                                                                type="date"
                                                                name="contribution_date"
                                                                min="<?= e($openingDate) ?>"
                                                                max="<?= e($today) ?>"
                                                                value="<?= e($today) ?>"
                                                                required
                                                            >

                                                        </div>

                                                        <div class="form-group">

                                                            <label>
                                                                Keterangan (Opsional)
                                                            </label>

                                                            <input
                                                                type="text"
                                                                name="description"
                                                                maxlength="255"
                                                                placeholder="Contoh: Menyisihkan uang bulanan"
                                                            >

                                                        </div>

                                                        <button
                                                            type="submit"
                                                            class="btn btn-primary"
                                                            <?= $availableBalance <= 0
                                                                || $currentAmount >= $goalAmount
                                                                ? 'disabled'
                                                                : '' ?>
                                                        >
                                                            + Setor Tabungan
                                                        </button>

                                                    </form>

                                                <?php endif; ?>

                                                <?php if ($currentAmount > 0): ?>

                                                    <form
                                                        method="POST"
                                                        action="savings.php"
                                                        style="margin-top: 20px;"
                                                    >

                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= e(csrfToken()) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="movement"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="goal_id"
                                                            value="<?= e((string) $goal['id']) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="type"
                                                            value="withdrawal"
                                                        >

                                                        <div class="form-group">

                                                            <label>
                                                                Nominal Penarikan (Rp)
                                                            </label>

                                                            <input
                                                                type="number"
                                                                name="amount"
                                                                min="1"
                                                                max="<?= e((string) $currentAmount) ?>"
                                                                step="1"
                                                                placeholder="Nominal penarikan"
                                                                required
                                                            >

                                                        </div>

                                                        <div class="form-group">

                                                            <label>
                                                                Tanggal Penarikan
                                                            </label>

                                                            <input
                                                                type="date"
                                                                name="contribution_date"
                                                                min="<?= e($openingDate) ?>"
                                                                max="<?= e($today) ?>"
                                                                value="<?= e($today) ?>"
                                                                required
                                                            >

                                                        </div>

                                                        <div class="form-group">

                                                            <label>
                                                                Keterangan (Opsional)
                                                            </label>

                                                            <input
                                                                type="text"
                                                                name="description"
                                                                maxlength="255"
                                                                placeholder="Contoh: Mengambil sebagian tabungan"
                                                            >

                                                        </div>

                                                        <button
                                                            type="submit"
                                                            class="btn btn-primary"
                                                        >
                                                            Tarik Tabungan
                                                        </button>

                                                    </form>

                                                <?php endif; ?>

                                                <?php if ($currentAmount === 0): ?>

                                                    <form
                                                        method="POST"
                                                        action="savings.php"
                                                        style="margin-top: 20px;"
                                                        onsubmit="return confirm('Yakin ingin membatalkan target ini?');"
                                                    >

                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= e(csrfToken()) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="cancel_goal"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="goal_id"
                                                            value="<?= e((string) $goal['id']) ?>"
                                                        >

                                                        <button
                                                            type="submit"
                                                            class="btn"
                                                        >
                                                            Batalkan Target
                                                        </button>

                                                    </form>

                                                <?php endif; ?>

                                            </div>

                                        </details>

                                    </td>

                                </tr>

                            <?php endif; ?>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </section>

        <!-- Riwayat aktivitas -->

        <section class="card recent-card">

            <div class="section-heading">

                <div>
                    <h2>Riwayat Tabungan</h2>

                    <p>
                        Tiga puluh aktivitas setoran dan penarikan terakhir.
                    </p>
                </div>

            </div>

            <div class="table-wrapper">

                <table class="transaction-table">

                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Target</th>
                            <th>Keterangan</th>
                            <th>Jenis</th>
                            <th>Nominal</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (count($history) === 0): ?>

                        <tr>
                            <td colspan="5" class="empty-state">
                                Belum ada aktivitas tabungan.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($history as $item): ?>

                            <tr>

                                <td>
                                    <?= e(date(
                                        'd/m/Y',
                                        strtotime($item['contribution_date'])
                                    )) ?>
                                </td>

                                <td>
                                    <?= e($item['goal_name']) ?>
                                </td>

                                <td class="description-cell">
                                    <?= e(
                                        $item['description'] !== ''
                                            ? $item['description']
                                            : '-'
                                    ) ?>
                                </td>

                                <td>
                                    <span class="type-badge <?= $item['type'] === 'deposit'
                                        ? 'income'
                                        : 'expense' ?>">
                                        <?= $item['type'] === 'deposit'
                                            ? 'Setoran'
                                            : 'Penarikan' ?>
                                    </span>
                                </td>

                                <td class="amount-cell <?= $item['type'] === 'deposit'
                                    ? 'income'
                                    : 'expense' ?>">
                                    <?= $item['type'] === 'deposit' ? '+' : '−' ?>
                                    <?= e(formatRupiah((int) $item['amount'])) ?>
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

</body>
</html>
