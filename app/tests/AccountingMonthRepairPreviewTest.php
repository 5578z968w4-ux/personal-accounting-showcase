<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/accounting_month_repair_preview.php';

function accounting_month_preview_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE payment_methods (
    id INTEGER PRIMARY KEY, name TEXT, settlement_start_day INTEGER, settlement_end_day INTEGER
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
    deleted_at TEXT DEFAULT NULL
)');
$pdo->exec("INSERT INTO payment_methods VALUES (1, '現金', 1, 31)");
$pdo->exec("INSERT INTO payment_methods VALUES (2, '展示方式 C', 7, 6)");
$pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method_id, payment_method, accounting_month, source, is_deleted, deleted_at)
    VALUES ('2026-06-27', 'cash fixture', 200, 1, '現金', '2026/06', 'fixture', 0, NULL)");
$pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method_id, payment_method, accounting_month, source, is_deleted, deleted_at)
    VALUES ('2026-06-27', 'private card item', 500, 2, '展示方式 C', '2026/06', 'fixture', 0, NULL)");
$pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method_id, payment_method, accounting_month, source, is_deleted, deleted_at)
    VALUES ('2026-06-28', 'deleted by flag', 999, 2, '展示方式 C', '2026/06', 'deleted-fixture', 1, NULL)");
$pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method_id, payment_method, accounting_month, source, is_deleted, deleted_at)
    VALUES ('2026-06-29', 'deleted by timestamp', 888, 2, '展示方式 C', '2026/06', 'deleted-fixture', 0, '2026-06-29 12:00:00')");

$gate = accounting_month_repair_preview_production_gate($pdo, [
    'APP_ENV' => 'production',
    'DB_DATABASE' => 'personal_accounting',
    'SELECT_DATABASE' => 'personal_accounting',
]);
accounting_month_preview_assert($gate['APP_ENV'] === 'production', 'Production gate APP_ENV mismatch.');

$result = accounting_month_repair_preview($pdo);

accounting_month_preview_assert($result['readonly'] === true, 'Preview must identify itself as readonly.');
accounting_month_preview_assert($result['checked'] === 2, 'Preview should ignore deleted rows.');
accounting_month_preview_assert($result['mismatch_count'] === 1, 'Preview mismatch count mismatch.');
accounting_month_preview_assert($result['mismatch_amount_total'] === 500.0, 'Preview mismatch amount total mismatch.');
accounting_month_preview_assert($result['active_filters'] === ['deleted_at IS NULL', 'is_deleted = 0'], 'Preview active filters mismatch.');
accounting_month_preview_assert(count($result['summary']) === 1, 'Preview summary count mismatch.');
accounting_month_preview_assert($result['summary'][0]['payment_method'] === '展示方式 C', 'Preview payment method mismatch.');
accounting_month_preview_assert($result['summary'][0]['current_accounting_month'] === '2026/06', 'Preview current month mismatch.');
accounting_month_preview_assert($result['summary'][0]['expected_accounting_month'] === '2026/07', 'Preview expected month mismatch.');
accounting_month_preview_assert($result['mismatch_by_payment_method'][0]['count'] === 1, 'Payment method group count mismatch.');
accounting_month_preview_assert($result['mismatch_by_payment_method'][0]['amount_total'] === 500.0, 'Payment method group amount mismatch.');
accounting_month_preview_assert($result['mismatch_by_month'][0]['current_accounting_month'] === '2026/06', 'Month group current mismatch.');
accounting_month_preview_assert($result['mismatch_by_month'][0]['expected_accounting_month'] === '2026/07', 'Month group expected mismatch.');
accounting_month_preview_assert($result['samples'][0]['id'] === 2, 'Preview sample id mismatch.');
accounting_month_preview_assert($result['samples'][0]['item_length'] === 17, 'Preview sample item length mismatch.');
accounting_month_preview_assert(!array_key_exists('item', $result['samples'][0]), 'Preview sample must not expose full item.');

echo "AccountingMonthRepairPreviewTest passed\n";
