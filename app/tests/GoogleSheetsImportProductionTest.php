<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/google_sheets_import_production.php';

function production_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        exit(1);
    }
}

function production_test_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE payment_methods (
        id INTEGER PRIMARY KEY, name TEXT, settlement_start_day INTEGER, settlement_end_day INTEGER,
        is_active INTEGER, sort_order INTEGER
    )');
    $pdo->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY, name TEXT, is_active INTEGER, sort_order INTEGER)');
    $pdo->exec('CREATE TABLE leave_types (id INTEGER PRIMARY KEY, name TEXT, is_active INTEGER, sort_order INTEGER)');
    $pdo->exec('CREATE TABLE expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT, record_date TEXT, item TEXT, amount REAL,
        payment_method_id INTEGER, payment_method TEXT, accounting_month TEXT, category TEXT,
        raw_input TEXT, source TEXT, user_name TEXT, is_deleted INTEGER DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE incomes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, record_date TEXT, source_name TEXT, amount REAL,
        account_id INTEGER, account_name TEXT, accounting_month TEXT, category TEXT,
        raw_input TEXT, source TEXT, user_name TEXT, is_deleted INTEGER DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE overtime_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, work_date TEXT UNIQUE, overtime_hours REAL,
        raw_input TEXT, note TEXT, user_name TEXT, source TEXT, is_deleted INTEGER DEFAULT 0,
        deleted_at TEXT
    )');
    $pdo->exec('CREATE TABLE leave_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, leave_date TEXT, leave_type TEXT, leave_days REAL,
        leave_hours REAL, note TEXT, raw_input TEXT, user_name TEXT, source TEXT, is_deleted INTEGER DEFAULT 0,
        deleted_at TEXT
    )');
    $pdo->exec("INSERT INTO payment_methods VALUES (1, '現金', 1, 31, 1, 10)");
    $pdo->exec("INSERT INTO payment_methods VALUES (2, '展示方式 C', 7, 6, 1, 20)");
    $pdo->exec("INSERT INTO accounts VALUES (1, '銀行', 1, 10)");
    $pdo->exec("INSERT INTO leave_types VALUES (1, '特休', 1, 10)");

    return $pdo;
}

function production_test_base_dir(): string
{
    $baseDir = sys_get_temp_dir() . '/google_sheets_import_production_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $targetDir = $baseDir . '/imports/google_sheets';
    mkdir($targetDir, 0775, true);

    file_put_contents(
        $targetDir . '/expenses.csv',
        implode("\n", [
            '日期,項目,金額,支付,分類',
            '2026-06-01,fixture-expense-a,100,現金,其他',
            '2026-06-01,fixture-expense-a,100,現金,其他',
            '2026-06-27,fixture-card,500,展示方式 C,生活',
            '',
        ])
    );
    file_put_contents(
        $targetDir . '/incomes.csv',
        implode("\n", [
            '日期,來源,金額,帳戶,分類',
            '2026-06-02,fixture-income,500,銀行,薪資',
            '',
        ])
    );
    file_put_contents(
        $targetDir . '/overtime_logs.csv',
        implode("\n", [
            '日期,加班時數',
            '2026-06-03,2',
            '',
        ])
    );
    file_put_contents(
        $targetDir . '/leave_logs.csv',
        implode("\n", [
            '日期,假別,天,H,換算,備註',
            '請假統計,,1,2,1.25,fixture-summary-row',
            '2026-06-04,特休,1,0,1,fixture-leave',
            '',
        ])
    );

    return $baseDir;
}

$gate = [
    'APP_ENV' => 'production',
    'DB_DATABASE' => 'personal_accounting',
    'SELECT_DATABASE' => 'personal_accounting',
];

$blocked = false;
try {
    google_sheets_import_production_run(production_test_pdo(), production_test_base_dir(), false, $gate);
} catch (RuntimeException) {
    $blocked = true;
}
production_test_assert($blocked, 'Production import should require explicit confirmation.');

$blocked = false;
try {
    google_sheets_import_production_run(
        production_test_pdo(),
        production_test_base_dir(),
        true,
        ['APP_ENV' => 'testing', 'DB_DATABASE' => 'personal_accounting_test', 'SELECT_DATABASE' => 'personal_accounting_test']
    );
} catch (RuntimeException) {
    $blocked = true;
}
production_test_assert($blocked, 'Production import should reject the wrong DB gate.');

$pdo = production_test_pdo();
$pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method_id, payment_method, accounting_month, category, source) VALUES ('2026-01-01', 'fixture-existing', 1, 1, '現金', '2026/01', '其他', 'fixture')");
$blocked = false;
try {
    google_sheets_import_production_run($pdo, production_test_base_dir(), true, $gate);
} catch (RuntimeException) {
    $blocked = true;
}
production_test_assert($blocked, 'Production import should reject non-empty business tables.');

$pdo = production_test_pdo();
$result = google_sheets_import_production_run($pdo, production_test_base_dir(), true, $gate);

production_test_assert($result['gate']['DB_DATABASE'] === 'personal_accounting', 'Production gate should target personal_accounting.');
production_test_assert($result['results']['expenses']['row_count'] === 3, 'Expense fixture row count mismatch.');
production_test_assert($result['results']['expenses']['inserted'] === 3, 'Duplicate expense candidates should both insert.');
production_test_assert($result['results']['expenses']['skipped'] === 0, 'Duplicate expense candidates should not be skipped.');
production_test_assert($result['results']['expenses']['duplicates'] === 0, 'Production import should not mark duplicate expense candidates.');
production_test_assert((int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === 3, 'Production fixture should write all expense rows.');
production_test_assert(
    (string) $pdo->query("SELECT accounting_month FROM expenses WHERE payment_method = '現金' ORDER BY id LIMIT 1")->fetchColumn() === '2026/06',
    'Production import cash accounting month mismatch.'
);
production_test_assert(
    (string) $pdo->query("SELECT accounting_month FROM expenses WHERE payment_method = '展示方式 C' ORDER BY id LIMIT 1")->fetchColumn() === '2026/07',
    'Production import credit-card accounting month mismatch.'
);
production_test_assert($result['results']['leave_logs']['inserted'] === 1, 'Leave detail row should insert.');
production_test_assert($result['results']['leave_logs']['skipped'] === 1, 'Leave summary row should be skipped.');
production_test_assert(($result['results']['leave_logs']['skipped_reasons']['summary_row'] ?? 0) === 1, 'Leave summary row should use summary_row reason.');
production_test_assert($result['results']['leave_logs']['errors'] === 0, 'Leave summary row should not create errors.');
production_test_assert((float) $result['totals']['expenses_amount_total'] === 700.0, 'Expense duplicate total should include all rows.');
production_test_assert((float) $result['totals']['leave_days_total'] === 1.0, 'Leave days total mismatch.');
production_test_assert((float) $result['totals']['leave_hours_total'] === 0.0, 'Leave hours total mismatch.');

echo "[PASS] GoogleSheetsImportProductionTest\n";
