<?php
date_default_timezone_set('Asia/Jakarta');

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Gunakan direktori sesi khusus di akun Windows pengguna.
    $localAppData = getenv('LOCALAPPDATA');

    if (!is_string($localAppData) || $localAppData === '') {
        throw new RuntimeException(
            'Direktori AppData Windows tidak ditemukan.'
        );
    }

    $sessionPath = $localAppData
        . DIRECTORY_SEPARATOR
        . 'PersonalFinanceTrackerSessions';

    // Buat folder sesi jika belum tersedia.
    if (
        !is_dir($sessionPath) &&
        !mkdir($sessionPath, 0700, true) &&
        !is_dir($sessionPath)
    ) {
        throw new RuntimeException(
            'Folder penyimpanan sesi gagal dibuat.'
        );
    }

    if (!is_writable($sessionPath)) {
        throw new RuntimeException(
            'Folder penyimpanan sesi tidak dapat ditulis.'
        );
    }

    // Atur lokasi sebelum session_start().
    session_save_path($sessionPath);

    session_start();
}


/**
 * Mengamankan teks sebelum ditampilkan ke HTML.
 */
function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * Membuat token CSRF untuk melindungi formulir.
 */
function csrfToken(): string
{
    if (
        !isset($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token'])
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Memastikan formulir dikirim dengan token yang benar.
 */
function verifyCsrfToken(): void
{
    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !is_string($sessionToken) ||
        $sessionToken === '' ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Permintaan ditolak. Silakan muat ulang halaman dan coba lagi.');
    }
}

/**
 * Memvalidasi tanggal dalam format YYYY-MM-DD.
 */
function isValidDate(string $date): bool
{
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsedDate !== false
        && $parsedDate->format('Y-m-d') === $date;
}

/**
 * Mengarahkan pengguna kembali ke halaman transaksi.
 */
function redirectToTransactions(string $status): void
{
    header(
        'Location: ../transactions.php?status=' . rawurlencode($status)
    );
    exit;
}

/**
 * Memformat nominal Rupiah.
 */
function formatRupiah(int $amount): string
{
    return 'Rp' . number_format($amount, 0, ',', '.');
}


/**
 * Menghitung tanggal pengulangan berikutnya.
 * anchor_day dan anchor_month mempertahankan tanggal awal jadwal.
 */
function calculateNextRecurringDate(
    string $currentDate,
    string $frequency,
    int $anchorDay,
    int $anchorMonth
): string {
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $currentDate
    );

    if ($date === false) {
        throw new InvalidArgumentException(
            'Tanggal jadwal tidak valid.'
        );
    }

    switch ($frequency) {

        case 'daily':
            $nextDate = $date->modify('+1 day');
            break;

        case 'weekly':
            $nextDate = $date->modify('+7 days');
            break;

        case 'monthly':
            $nextMonth = $date->modify('first day of next month');

            $year = (int) $nextMonth->format('Y');
            $month = (int) $nextMonth->format('n');
            $lastDay = (int) $nextMonth->format('t');

            $day = min($anchorDay, $lastDay);

            $nextDate = $nextMonth->setDate(
                $year,
                $month,
                $day
            );
            break;

        case 'yearly':
            $year = (int) $date->format('Y') + 1;

            $monthStart = new DateTimeImmutable(
                sprintf('%04d-%02d-01', $year, $anchorMonth)
            );

            $lastDay = (int) $monthStart->format('t');
            $day = min($anchorDay, $lastDay);

            $nextDate = $monthStart->setDate(
                $year,
                $anchorMonth,
                $day
            );
            break;

        default:
            throw new InvalidArgumentException(
                'Frekuensi pengulangan tidak dikenal.'
            );
    }

    return $nextDate->format('Y-m-d');
}


/**
 * Menghitung saldo tersedia setelah memperhitungkan alokasi tabungan.
 */
function getAvailableBalance(
    PDO $pdo,
    int $openingBalance,
    string $openingDate,
    string $today
): int {
    $stmt = $pdo->prepare(
        "SELECT
            (
                SELECT COALESCE(
                    SUM(
                        CASE
                            WHEN type = 'income' THEN amount
                            WHEN type = 'expense' THEN -amount
                            ELSE 0
                        END
                    ), 0
                )
                FROM transactions
                WHERE transaction_date >= :transaction_start
                  AND transaction_date <= :transaction_end
            ) AS net_transactions,

            (
                SELECT COALESCE(
                    SUM(
                        CASE
                            WHEN type = 'deposit' THEN amount
                            WHEN type = 'withdrawal' THEN -amount
                            ELSE 0
                        END
                    ), 0
                )
                FROM savings_contributions
                WHERE contribution_date >= :savings_start
                  AND contribution_date <= :savings_end
            ) AS net_savings"
    );

    $stmt->execute([
        ':transaction_start' => $openingDate,
        ':transaction_end' => $today,
        ':savings_start' => $openingDate,
        ':savings_end' => $today,
    ]);

    $result = $stmt->fetch();

    return $openingBalance
        + (int) $result['net_transactions']
        - (int) $result['net_savings'];
}

/**
 * Menghitung saldo tabungan dari satu target.
 */
function getSavingsGoalBalance(
    PDO $pdo,
    int $goalId,
    string $openingDate,
    string $today
): int {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(
            SUM(
                CASE
                    WHEN type = 'deposit' THEN amount
                    WHEN type = 'withdrawal' THEN -amount
                    ELSE 0
                END
            ), 0
        )
        FROM savings_contributions
        WHERE goal_id = :goal_id
          AND contribution_date >= :opening_date
          AND contribution_date <= :today"
    );

    $stmt->execute([
        ':goal_id' => $goalId,
        ':opening_date' => $openingDate,
        ':today' => $today,
    ]);

    return (int) $stmt->fetchColumn();
}

/**
 * Mencatat setoran atau penarikan tabungan.
 */
function recordSavingsMovement(
    PDO $pdo,
    int $goalId,
    int $amount,
    string $type,
    string $description,
    string $contributionDate
): void {
    if ($amount <= 0) {
        throw new InvalidArgumentException(
            'Nominal harus lebih besar dari nol.'
        );
    }

    if (!in_array($type, ['deposit', 'withdrawal'], true)) {
        throw new InvalidArgumentException(
            'Jenis aktivitas tabungan tidak valid.'
        );
    }

    if (!isValidDate($contributionDate)) {
        throw new InvalidArgumentException(
            'Format tanggal tidak valid.'
        );
    }

    $today = date('Y-m-d');

    if ($contributionDate > $today) {
        throw new InvalidArgumentException(
            'Tanggal aktivitas tidak boleh melebihi hari ini.'
        );
    }

    $pdo->beginTransaction();

    try {
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

        if ($contributionDate < $openingDate) {
            throw new InvalidArgumentException(
                'Tanggal aktivitas tidak boleh sebelum tanggal mulai pencatatan.'
            );
        }

        $goalStmt = $pdo->prepare(
            'SELECT id, name, target_amount, status
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
                'Target tabungan yang dibatalkan tidak dapat digunakan.'
            );
        }

        $availableBalance = getAvailableBalance(
            $pdo,
            $openingBalance,
            $openingDate,
            $today
        );

        $goalBalance = getSavingsGoalBalance(
            $pdo,
            $goalId,
            $openingDate,
            $today
        );

        $targetAmount = (int) $goal['target_amount'];

        if ($type === 'deposit') {
            if ($goal['status'] === 'completed') {
                throw new RuntimeException(
                    'Target tabungan sudah selesai.'
                );
            }

            if ($amount > $availableBalance) {
                throw new RuntimeException(
                    'Saldo tersedia tidak mencukupi.'
                );
            }

            if ($amount > ($targetAmount - $goalBalance)) {
                throw new RuntimeException(
                    'Nominal setoran melebihi sisa target tabungan.'
                );
            }
        }

        if ($type === 'withdrawal') {
            if ($amount > $goalBalance) {
                throw new RuntimeException(
                    'Saldo tabungan tidak mencukupi.'
                );
            }
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO savings_contributions (
                goal_id,
                amount,
                type,
                description,
                contribution_date
            ) VALUES (
                :goal_id,
                :amount,
                :type,
                :description,
                :contribution_date
            )'
        );

        $insertStmt->execute([
            ':goal_id' => $goalId,
            ':amount' => $amount,
            ':type' => $type,
            ':description' => trim($description),
            ':contribution_date' => $contributionDate,
        ]);

        $newGoalBalance = getSavingsGoalBalance(
            $pdo,
            $goalId,
            $openingDate,
            $today
        );

        $newStatus = $newGoalBalance >= $targetAmount
            ? 'completed'
            : 'active';

        $updateStmt = $pdo->prepare(
            'UPDATE savings_goals
             SET status = :status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $updateStmt->execute([
            ':status' => $newStatus,
            ':id' => $goalId,
        ]);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}


