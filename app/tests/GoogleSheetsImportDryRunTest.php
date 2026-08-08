<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/google_sheets_import_dry_run.php';

function dry_run_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        exit(1);
    }
}

function dry_run_test_pdo(): PDO
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
    $pdo->exec("INSERT INTO accounts VALUES (2, '現金', 1, 20)");
    $pdo->exec("INSERT INTO leave_types VALUES (1, '特休', 1, 10)");
    $pdo->exec("INSERT INTO leave_types VALUES (2, '事假', 1, 20)");

    return $pdo;
}

$fixtureSourceDir = __DIR__ . '/fixtures';
$baseDir = sys_get_temp_dir() . '/google_sheets_import_dry_run_' . getmypid();
$fixtureTargetDir = $baseDir . '/imports/google_sheets';
mkdir($fixtureTargetDir, 0775, true);
foreach ([
    'google_sheets_expenses_sample.csv' => 'expenses.csv',
    'google_sheets_incomes_sample.csv' => 'incomes.csv',
    'google_sheets_overtime_logs_sample.csv' => 'overtime_logs.csv',
    'google_sheets_leave_logs_sample.csv' => 'leave_logs.csv',
] as $source => $target) {
    copy($fixtureSourceDir . '/' . $source, $fixtureTargetDir . '/' . $target);
}
$gate = [
    'APP_ENV' => 'testing',
    'DB_DATABASE' => 'personal_accounting_test',
    'SELECT_DATABASE' => 'personal_accounting_test',
];

$pdo = dry_run_test_pdo();
$result = google_sheets_import_run($pdo, $baseDir, true, $gate);

dry_run_test_assert($result['gate']['DB_DATABASE'] === 'personal_accounting_test', 'Gate should use test database.');
dry_run_test_assert($result['results']['expenses']['row_count'] === 2, 'Expense fixture row count mismatch.');
dry_run_test_assert($result['results']['expenses']['inserted'] === 2, 'Expense fixture inserted count mismatch.');
dry_run_test_assert($result['results']['incomes']['inserted'] === 2, 'Income fixture inserted count mismatch.');
dry_run_test_assert($result['results']['overtime_logs']['inserted'] === 2, 'Overtime fixture inserted count mismatch.');
dry_run_test_assert($result['results']['leave_logs']['inserted'] === 2, 'Leave fixture inserted count mismatch.');
dry_run_test_assert(($result['results']['leave_logs']['skipped_reasons']['summary_row'] ?? 0) === 0, 'Normal leave fixture should not report summary rows.');
dry_run_test_assert((float) $result['totals']['expenses_amount_total'] === 1280.0, 'Expense total mismatch.');
dry_run_test_assert((float) $result['totals']['incomes_amount_total'] === 50120.0, 'Income total mismatch.');
dry_run_test_assert((float) $result['totals']['overtime_hours_total'] === 3.5, 'Overtime hours total mismatch.');
dry_run_test_assert((float) $result['totals']['leave_days_total'] === 1.0, 'Leave days total mismatch.');
dry_run_test_assert((float) $result['totals']['leave_hours_total'] === 2.0, 'Leave hours total mismatch.');
dry_run_test_assert((int) $pdo->query("SELECT COUNT(*) FROM expenses WHERE source = 'import_google_sheets'")->fetchColumn() === 2, 'Expense rows should be tagged with import source.');
dry_run_test_assert((string) $pdo->query('SELECT accounting_month FROM expenses ORDER BY id LIMIT 1')->fetchColumn() === '2026/06', 'Expense accounting month should use record_date.');

$expenseStats = google_sheets_import_empty_type_result('expenses', 'fixture', 1);
$expenseRefs = google_sheets_import_reference_maps($pdo);
$cashExpense = google_sheets_import_normalize_expense(
    $expenseStats,
    ['日期' => '2026-06-27', '項目' => 'fixture-cash', '金額' => '200', '支付' => '現金', '分類' => '餐飲'],
    ['record_date' => '日期', 'item' => '項目', 'amount' => '金額', 'payment_method' => '支付', 'category' => '分類'],
    $expenseRefs
);
dry_run_test_assert($cashExpense !== null && $cashExpense['accounting_month'] === '2026/06', 'Cash import accounting month mismatch.');
$cardExpense = google_sheets_import_normalize_expense(
    $expenseStats,
    ['日期' => '2026-06-27', '項目' => 'fixture-card', '金額' => '500', '支付' => '展示方式 C', '分類' => '生活'],
    ['record_date' => '日期', 'item' => '項目', 'amount' => '金額', 'payment_method' => '支付', 'category' => '分類'],
    $expenseRefs
);
dry_run_test_assert($cardExpense !== null && $cardExpense['accounting_month'] === '2026/07', 'Credit-card import accounting month mismatch.');

$overtimeStats = google_sheets_import_empty_type_result('overtime_logs', 'fixture', 1);
$overtimeNormalized = google_sheets_import_normalize_overtime(
    $overtimeStats,
    ['日期' => '06月03日 星期三', '加班時數' => '2.5', '系統時間' => '2026/06/03 19:00:00'],
    ['work_date' => '日期', 'overtime_hours' => '加班時數']
);
dry_run_test_assert($overtimeNormalized !== null, 'Chinese month/day overtime date should use system-time year fallback.');
dry_run_test_assert($overtimeNormalized['work_date'] === '2026-06-03', 'Overtime fallback date mismatch.');

$leaveStats = google_sheets_import_empty_type_result('leave_logs', 'fixture', 1);
$leaveNormalized = google_sheets_import_normalize_leave(
    $leaveStats,
    ['日期' => '2026/06/03', '假別' => '事假', '天' => '', 'H' => '2', '備註' => 'fixture-leave-note'],
    ['leave_date' => '日期', 'leave_type' => '假別', 'leave_days' => '天', 'leave_hours' => 'H', 'note' => '備註'],
    ['leave_types' => ['事假' => ['id' => 2, 'name' => '事假']]]
);
dry_run_test_assert($leaveNormalized !== null, 'Blank leave days should normalize to zero when hours are present.');
dry_run_test_assert((float) $leaveNormalized['leave_days'] === 0.0, 'Blank leave days should become 0.');

$summaryBaseDir = sys_get_temp_dir() . '/google_sheets_import_dry_run_summary_' . getmypid();
$summaryTargetDir = $summaryBaseDir . '/imports/google_sheets';
mkdir($summaryTargetDir, 0775, true);
foreach ([
    'google_sheets_expenses_sample.csv' => 'expenses.csv',
    'google_sheets_incomes_sample.csv' => 'incomes.csv',
    'google_sheets_overtime_logs_sample.csv' => 'overtime_logs.csv',
] as $source => $target) {
    copy($fixtureSourceDir . '/' . $source, $summaryTargetDir . '/' . $target);
}
file_put_contents(
    $summaryTargetDir . '/leave_logs.csv',
    implode("\n", [
        '日期,假別,天,H,換算,備註',
        '請假統計,,4,16,6,fixture-summary-row',
        '2026-06-01,特休,1,0,1,fixture-leave-note',
        'not-a-date,特休,1,0,1,fixture-invalid-date',
        '',
    ])
);

$summaryResult = google_sheets_import_run(dry_run_test_pdo(), $summaryBaseDir, true, $gate);
dry_run_test_assert($summaryResult['results']['leave_logs']['row_count'] === 3, 'Summary fixture leave row count mismatch.');
dry_run_test_assert($summaryResult['results']['leave_logs']['inserted'] === 1, 'Summary fixture should insert only real leave rows.');
dry_run_test_assert($summaryResult['results']['leave_logs']['skipped'] === 2, 'Summary fixture skipped count mismatch.');
dry_run_test_assert($summaryResult['results']['leave_logs']['errors'] === 1, 'Summary row should not count as an error.');
dry_run_test_assert(($summaryResult['results']['leave_logs']['mapping_errors']['leave_date'] ?? 0) === 1, 'Only real invalid dates should count as leave_date errors.');
dry_run_test_assert(!isset($summaryResult['results']['leave_logs']['mapping_errors']['leave_type']), 'Summary row blank leave type should not count as a leave_type error.');
dry_run_test_assert(($summaryResult['results']['leave_logs']['skipped_reasons']['summary_row'] ?? 0) === 1, 'Leave summary row should be skipped with summary_row reason.');
dry_run_test_assert(($summaryResult['results']['leave_logs']['skipped_reasons']['invalid_row'] ?? 0) === 1, 'Real invalid leave row should still be skipped as invalid_row.');
dry_run_test_assert(count($summaryResult['results']['leave_logs']['summary_row_summaries']) === 1, 'Summary row diagnostics should be recorded.');
dry_run_test_assert(count($summaryResult['results']['leave_logs']['invalid_row_summaries']) === 1, 'Real invalid row diagnostics should be recorded.');

$pdo = dry_run_test_pdo();
$pdo->exec('DELETE FROM payment_methods');
$errorResult = google_sheets_import_run($pdo, $baseDir, true, $gate);
dry_run_test_assert($errorResult['results']['expenses']['inserted'] === 0, 'Missing payment methods should block expense inserts.');
dry_run_test_assert($errorResult['results']['expenses']['errors'] === 2, 'Missing payment methods should report expense errors.');
dry_run_test_assert($errorResult['results']['expenses']['unmapped_payment_methods']['現金'] === 2, 'Unmapped payment method count mismatch.');

$badGate = [
    'APP_ENV' => 'production',
    'DB_DATABASE' => 'personal_accounting',
    'SELECT_DATABASE' => 'personal_accounting',
];
$blocked = false;
try {
    google_sheets_import_run(dry_run_test_pdo(), $baseDir, true, $badGate);
} catch (RuntimeException) {
    $blocked = true;
}
dry_run_test_assert($blocked, 'Production-like DB gate should be blocked.');

echo "[PASS] GoogleSheetsImportDryRunTest\n";
