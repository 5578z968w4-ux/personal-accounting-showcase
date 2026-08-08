<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/accounting_month_repair_apply.php';

function accounting_month_apply_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function accounting_month_apply_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE payment_methods (
        id INTEGER PRIMARY KEY,
        name TEXT,
        settlement_start_day INTEGER,
        settlement_end_day INTEGER,
        cycle_start_day INTEGER,
        cycle_end_day INTEGER,
        is_active INTEGER,
        deleted_at TEXT DEFAULT NULL,
        updated_at TEXT DEFAULT NULL
    )');
    $pdo->exec('CREATE TABLE expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        record_date TEXT,
        item TEXT,
        amount REAL,
        payment_method_id INTEGER,
        payment_method TEXT,
        accounting_month TEXT,
        source TEXT,
        is_deleted INTEGER DEFAULT 0,
        deleted_at TEXT DEFAULT NULL,
        updated_at TEXT DEFAULT NULL
    )');
    $pdo->exec("INSERT INTO payment_methods (id, name, settlement_start_day, settlement_end_day, cycle_start_day, cycle_end_day, is_active)
        VALUES (1, '現金', 11, 10, 11, 10, 1)");
    $pdo->exec("INSERT INTO payment_methods (id, name, settlement_start_day, settlement_end_day, cycle_start_day, cycle_end_day, is_active)
        VALUES (2, '展示方式 C', 7, 6, 7, 6, 1)");
    $pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method_id, payment_method, accounting_month, source, is_deleted, deleted_at)
        VALUES ('2026-06-27', 'cash active', 200, 1, '現金', '2026/07', 'fixture', 0, NULL)");
    $pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method_id, payment_method, accounting_month, source, is_deleted, deleted_at)
        VALUES ('2026-06-27', 'card active', 500, 2, '展示方式 C', '2026/06', 'fixture', 0, NULL)");
    $pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method_id, payment_method, accounting_month, source, is_deleted, deleted_at)
        VALUES ('2026-06-28', 'deleted flag', 999, 2, '展示方式 C', '2026/06', 'fixture', 1, NULL)");
    $pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method_id, payment_method, accounting_month, source, is_deleted, deleted_at)
        VALUES ('2026-06-29', 'deleted timestamp', 888, 2, '展示方式 C', '2026/06', 'fixture', 0, '2026-06-29 12:00:00')");

    return $pdo;
}

$gate = [
    'APP_ENV' => 'production',
    'DB_DATABASE' => 'personal_accounting',
    'SELECT_DATABASE' => 'personal_accounting',
];

$blocked = false;
try {
    accounting_month_repair_apply_run(accounting_month_apply_pdo(), false, $gate);
} catch (RuntimeException) {
    $blocked = true;
}
accounting_month_apply_assert($blocked, 'Apply tool should require explicit confirmation.');

$blocked = false;
try {
    accounting_month_repair_apply_run(accounting_month_apply_pdo(), true, [
        'APP_ENV' => 'testing',
        'DB_DATABASE' => 'personal_accounting_test',
        'SELECT_DATABASE' => 'personal_accounting_test',
    ]);
} catch (RuntimeException) {
    $blocked = true;
}
accounting_month_apply_assert($blocked, 'Apply tool should reject the wrong DB gate.');

$pdo = accounting_month_apply_pdo();
$result = accounting_month_repair_apply_run($pdo, true, $gate);
accounting_month_apply_assert($result['cash_repair']['updated'] === true, 'Cash method should be updated.');
accounting_month_apply_assert($result['cash_repair']['after']['cycle_start_day'] === 1, 'Cash legacy cycle start should be updated.');
accounting_month_apply_assert($result['cash_repair']['after']['cycle_end_day'] === 31, 'Cash legacy cycle end should be updated.');
accounting_month_apply_assert($result['preview_after_cash']['mismatch_count'] === 2, 'Both active fixture expenses should mismatch after cash repair.');
accounting_month_apply_assert($result['expense_repair']['updated_count'] === 2, 'Both active fixture expenses should be repaired.');
accounting_month_apply_assert((int) $result['final_preview']['active_expenses_count'] === 2, 'Active expense count mismatch.');
accounting_month_apply_assert((int) $result['final_preview']['mismatch_count'] === 0, 'Final mismatch count should be zero.');
accounting_month_apply_assert(
    (string) $pdo->query("SELECT accounting_month FROM expenses WHERE item = 'cash active'")->fetchColumn() === '2026/06',
    'Cash active expense should use record-date month.'
);
accounting_month_apply_assert(
    (string) $pdo->query("SELECT accounting_month FROM expenses WHERE item = 'card active'")->fetchColumn() === '2026/07',
    'Card active expense should be repaired to expected month.'
);
accounting_month_apply_assert(
    (string) $pdo->query("SELECT accounting_month FROM expenses WHERE item = 'deleted flag'")->fetchColumn() === '2026/06',
    'is_deleted expense must not be updated.'
);
accounting_month_apply_assert(
    (string) $pdo->query("SELECT accounting_month FROM expenses WHERE item = 'deleted timestamp'")->fetchColumn() === '2026/06',
    'deleted_at expense must not be updated.'
);
accounting_month_apply_assert(
    $result['source_counts_before'] === $result['source_counts_after'],
    'Active source counts should not change.'
);

$pdo = accounting_month_apply_pdo();
accounting_month_repair_apply_update_cash_method($pdo);
$pdo->exec("CREATE TRIGGER fail_second_expense_update
    BEFORE UPDATE OF accounting_month ON expenses
    WHEN OLD.item = 'card active'
    BEGIN
        SELECT RAISE(ABORT, 'fixture rollback');
    END");

$blocked = false;
try {
    accounting_month_repair_apply_expenses($pdo);
} catch (RuntimeException|PDOException) {
    $blocked = true;
}
accounting_month_apply_assert($blocked, 'Expense repair should surface update failures.');
accounting_month_apply_assert(
    (string) $pdo->query("SELECT accounting_month FROM expenses WHERE item = 'card active'")->fetchColumn() === '2026/06',
    'Failed repair should rollback card active expense.'
);

$pdo = accounting_month_apply_pdo();
$pdo->exec("INSERT INTO payment_methods (id, name, settlement_start_day, settlement_end_day, cycle_start_day, cycle_end_day, is_active)
    VALUES (3, '現金', 1, 31, 1, 31, 0)");
$blocked = false;
try {
    accounting_month_repair_apply_unique_cash_method($pdo);
} catch (RuntimeException) {
    $blocked = true;
}
accounting_month_apply_assert($blocked, 'Multiple cash payment methods should stop repair.');

echo "AccountingMonthRepairApplyTest passed\n";
