
<?php

$dbPath = dirname(__DIR__) . '/database/expense_tracker.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->beginTransaction();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS savings_goals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            target_amount INTEGER NOT NULL CHECK (target_amount > 0),
            target_date TEXT,
            status TEXT NOT NULL DEFAULT 'active'
                CHECK (status IN ('active', 'completed', 'cancelled')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS savings_contributions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            goal_id INTEGER NOT NULL,
            amount INTEGER NOT NULL CHECK (amount > 0),
            type TEXT NOT NULL
                CHECK (type IN ('deposit', 'withdrawal')),
            description TEXT NOT NULL DEFAULT '',
            contribution_date TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (goal_id)
                REFERENCES savings_goals(id)
                ON DELETE RESTRICT
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_savings_goals_status
        ON savings_goals(status)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_savings_contributions_goal
        ON savings_contributions(goal_id, contribution_date)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_savings_contributions_date
        ON savings_contributions(contribution_date)
    ");

    $pdo->commit();

    echo "Migrasi Savings Goals berhasil.\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo "Migrasi gagal: " . $e->getMessage() . "\n";
    exit(1);
}
