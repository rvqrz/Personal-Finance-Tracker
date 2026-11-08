PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS app_settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    opening_balance INTEGER NOT NULL DEFAULT 0
        CHECK (opening_balance >= 0),
    opening_balance_date TEXT NOT NULL,
    currency TEXT NOT NULL DEFAULT 'IDR',
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT OR IGNORE INTO app_settings (
    id,
    opening_balance,
    opening_balance_date,
    currency
)
VALUES (
    1,
    0,
    DATE('now', 'localtime'),
    'IDR'
);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    type TEXT NOT NULL
        CHECK (type IN ('income', 'expense')),
    icon TEXT,
    is_active INTEGER NOT NULL DEFAULT 1
        CHECK (is_active IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (name, type)
);

CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    type TEXT NOT NULL
        CHECK (type IN ('income', 'expense')),
    amount INTEGER NOT NULL CHECK (amount > 0),
    description TEXT NOT NULL DEFAULT '',
    transaction_date TEXT NOT NULL,
    payment_method TEXT NOT NULL DEFAULT 'cash',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS budgets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    period_month TEXT NOT NULL,
    amount INTEGER NOT NULL CHECK (amount > 0),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE (category_id, period_month),

    FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE RESTRICT
);


-- =========================================
-- RECURRING TRANSACTIONS
-- =========================================

CREATE TABLE IF NOT EXISTS recurring_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    category_id INTEGER NOT NULL,

    type TEXT NOT NULL
        CHECK (type IN ('income', 'expense')),

    name TEXT NOT NULL,

    amount INTEGER NOT NULL
        CHECK (amount > 0),

    frequency TEXT NOT NULL
        CHECK (
            frequency IN (
                'daily',
                'weekly',
                'monthly',
                'yearly'
            )
        ),

    start_date TEXT NOT NULL,

    end_date TEXT,

    next_due_date TEXT NOT NULL,

    anchor_day INTEGER NOT NULL
        CHECK (anchor_day BETWEEN 1 AND 31),

    anchor_month INTEGER NOT NULL
        CHECK (anchor_month BETWEEN 1 AND 12),

    is_active INTEGER NOT NULL DEFAULT 1
        CHECK (is_active IN (0, 1)),

    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CHECK (end_date IS NULL OR end_date >= start_date),

    FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE RESTRICT
);


-- =========================================
-- RECURRING OCCURRENCES
-- =========================================

CREATE TABLE IF NOT EXISTS recurring_occurrences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    recurring_id INTEGER NOT NULL,

    scheduled_date TEXT NOT NULL,

    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (
            status IN (
                'pending',
                'recorded',
                'skipped'
            )
        ),

    transaction_id INTEGER UNIQUE,

    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    processed_at TEXT,

    UNIQUE (recurring_id, scheduled_date),

    FOREIGN KEY (recurring_id)
        REFERENCES recurring_transactions(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (transaction_id)
        REFERENCES transactions(id)
        ON DELETE SET NULL
);


-- =========================================
-- SAVINGS GOALS
-- =========================================

CREATE TABLE IF NOT EXISTS savings_goals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    target_amount INTEGER NOT NULL CHECK (target_amount > 0),
    target_date TEXT,
    status TEXT NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'completed', 'cancelled')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- =========================================
-- SAVINGS CONTRIBUTIONS
-- =========================================

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
);

-- =========================================
-- SAVINGS INDEXES
-- =========================================

CREATE INDEX IF NOT EXISTS idx_savings_goals_status
    ON savings_goals(status);

CREATE INDEX IF NOT EXISTS idx_savings_contributions_goal
    ON savings_contributions(goal_id, contribution_date);

CREATE INDEX IF NOT EXISTS idx_savings_contributions_date
    ON savings_contributions(contribution_date);



-- =========================================
-- INDEXES
-- =========================================

CREATE INDEX IF NOT EXISTS idx_recurring_active_due
    ON recurring_transactions(is_active, next_due_date);

CREATE INDEX IF NOT EXISTS idx_occurrences_status_date
    ON recurring_occurrences(status, scheduled_date);

CREATE INDEX IF NOT EXISTS idx_occurrences_recurring
    ON recurring_occurrences(recurring_id);

CREATE INDEX IF NOT EXISTS idx_transactions_date
    ON transactions(transaction_date);

CREATE INDEX IF NOT EXISTS idx_transactions_type_date
    ON transactions(type, transaction_date);

CREATE INDEX IF NOT EXISTS idx_transactions_category
    ON transactions(category_id);