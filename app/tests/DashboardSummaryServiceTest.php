<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/DashboardSummaryService.php';

function dashboard_summary_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function dashboard_summary_near(float $actual, float $expected, string $message): void
{
    if (abs($actual - $expected) > 0.001) {
        throw new RuntimeException($message . ' actual=' . $actual . ' expected=' . $expected);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    record_date TEXT,
    item TEXT,
    amount REAL,
    payment_method TEXT,
    accounting_month TEXT,
    entry_owner TEXT DEFAULT "profile_a",
    is_deleted INTEGER DEFAULT 0,
    deleted_at TEXT
)');
$pdo->exec('CREATE TABLE incomes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    record_date TEXT,
    source_name TEXT,
    amount REAL,
    account_name TEXT,
    accounting_month TEXT,
    is_deleted INTEGER DEFAULT 0
)');
$pdo->exec('CREATE TABLE overtime_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    work_date TEXT,
    overtime_hours REAL,
    is_deleted INTEGER DEFAULT 0
)');
$pdo->exec('CREATE TABLE leave_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    leave_date TEXT,
    leave_type TEXT,
    leave_days REAL,
    leave_hours REAL,
    total_leave_days REAL,
    is_deleted INTEGER DEFAULT 0
)');
$pdo->exec('CREATE TABLE monthly_work_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    work_month TEXT,
    expected_work_days REAL
)');
$pdo->exec('CREATE TABLE monthly_salary_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    salary_month TEXT
)');
$pdo->exec('CREATE TABLE settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key TEXT,
    numeric_value REAL,
    is_active INTEGER DEFAULT 1
)');

foreach ([
    ['base_salary', 30000],
    ['full_attendance_bonus', 1000],
    ['attendance_allowance_unit', 100],
    ['overtime_134_hourly_rate', 200],
    ['overtime_167_hourly_rate', 250],
    ['overtime_2h_meal_fee', 80],
    ['overtime_3h_night_snack_fee', 100],
    ['labor_insurance_deduction', 500],
    ['health_insurance_deduction', 700],
    ['default_work_days', 22],
    ['annual_special_leave_days', 10],
] as [$key, $value]) {
    $statement = $pdo->prepare(
        'INSERT INTO settings (setting_key, numeric_value, is_active) VALUES (:setting_key, :numeric_value, 1)'
    );
    $statement->execute(['setting_key' => $key, 'numeric_value' => $value]);
}

$pdo->exec("INSERT INTO monthly_work_settings (work_month, expected_work_days) VALUES ('2026/06', 20)");
$pdo->exec("INSERT INTO monthly_salary_records (salary_month) VALUES ('2026-05')");
$pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method, accounting_month, is_deleted)
    VALUES ('2026-06-18', '晚餐', 120, '現金', '2026/06', 0)");
$pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method, accounting_month, is_deleted)
    VALUES ('2026-06-19', '已刪除', 999, '現金', '2026/06', 1)");
$pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method, accounting_month, is_deleted)
    VALUES ('2026-07-01', '外月', 300, '現金', '2026/07', 0)");
$pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method, accounting_month, is_deleted)
    VALUES ('2026-08-01', '刪除月份', 300, '現金', '2026/08', 1)");
$pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method, accounting_month, is_deleted)
    VALUES ('2026-06-27', '同日現金', 80, '現金', '2026/06', 0)");
$pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method, accounting_month, is_deleted)
    VALUES ('2026-06-27', '同日信用卡', 200, '展示方式 C', '2026/07', 0)");
$pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method, accounting_month, is_deleted)
    VALUES ('2026-06-27', '同日已刪除', 999, '現金', '2026/06', 1)");
$pdo->exec("INSERT INTO incomes (record_date, source_name, amount, account_name, accounting_month, is_deleted)
    VALUES ('2026-06-20', '獎金', 1000, '薪轉', '2026/06', 0)");
$pdo->exec("INSERT INTO incomes (record_date, source_name, amount, account_name, accounting_month, is_deleted)
    VALUES ('2026-06-21', '已刪除', 999, '薪轉', '2026/06', 1)");
$pdo->exec("INSERT INTO incomes (record_date, source_name, amount, account_name, accounting_month, is_deleted)
    VALUES ('2026-09-01', '刪除收入月份', 999, '薪轉', '2026/09', 1)");
$pdo->exec("INSERT INTO overtime_logs (work_date, overtime_hours, is_deleted) VALUES ('2026-06-10', 2, 0)");
$pdo->exec("INSERT INTO overtime_logs (work_date, overtime_hours, is_deleted) VALUES ('2026-06-11', 3, 0)");
$pdo->exec("INSERT INTO overtime_logs (work_date, overtime_hours, is_deleted) VALUES ('2026-06-12', 3, 1)");
$pdo->exec("INSERT INTO overtime_logs (work_date, overtime_hours, is_deleted) VALUES ('2026-10-12', 3, 1)");
$pdo->exec("INSERT INTO leave_logs (leave_date, leave_type, leave_days, leave_hours, total_leave_days, is_deleted)
    VALUES ('2026-06-12', '特休', 1, 2, 1, 0)");
$pdo->exec("INSERT INTO leave_logs (leave_date, leave_type, leave_days, leave_hours, total_leave_days, is_deleted)
    VALUES ('2026-06-13', '特休', 1, 0, 1, 1)");
$pdo->exec("INSERT INTO leave_logs (leave_date, leave_type, leave_days, leave_hours, total_leave_days, is_deleted)
    VALUES ('2026-11-13', '特休', 1, 0, 1, 1)");

$service = new DashboardSummaryService($pdo);
$monthOptions = $service->monthOptions('2026/06', '2026/07');
dashboard_summary_assert($monthOptions === ['2026/07', '2026/06', '2026/05'], 'Month options should be normalized and sorted');
foreach (['2026/08', '2026/09', '2026/10', '2026/11'] as $deletedOnlyMonth) {
    dashboard_summary_assert(
        !in_array($deletedOnlyMonth, $monthOptions, true),
        'Month options should ignore deleted-only ledger month ' . $deletedOnlyMonth
    );
}

$pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method, accounting_month, is_deleted, deleted_at)
    VALUES ('2026-06-28', 'deleted_at 支出', 999, '現金', '2026/04', 0, '2026-06-28 10:00:00')");

$summary = $service->summary('2026/06');
dashboard_summary_near((float) $summary['manual_income_total'], 1000, 'Manual income total mismatch');
dashboard_summary_near((float) $summary['expense_total'], 200, 'Expense total mismatch');
dashboard_summary_assert((int) $summary['overtime_2_days'] === 1, '2H overtime day count mismatch');
dashboard_summary_assert((int) $summary['overtime_3_days'] === 1, '3H overtime day count mismatch');
dashboard_summary_near((float) $summary['overtime_hours_134'], 4, 'Overtime 1.34 hours mismatch');
dashboard_summary_near((float) $summary['overtime_hours_167'], 1, 'Overtime 1.67 hours mismatch');
dashboard_summary_near((float) $summary['leave_days'], 1, 'Leave days mismatch');
dashboard_summary_near((float) $summary['leave_hours'], 2, 'Leave hours mismatch');
dashboard_summary_near((float) $summary['expected_work_days'], 20, 'Expected work days mismatch');
dashboard_summary_assert($summary['work_days_source'] === '每月設定', 'Work days source mismatch');
dashboard_summary_near((float) $summary['salary_net_total'], 32930, 'Salary net total mismatch');
dashboard_summary_near((float) $summary['income_total'], 33930, 'Income total mismatch');
dashboard_summary_near((float) $summary['net_total'], 33730, 'Net total mismatch');
dashboard_summary_assert(count($summary['recent_expenses']) === 4, 'Recent expenses should ignore deleted rows but not other accounting months');
dashboard_summary_assert(
    $summary['recent_expenses'][0]['item'] === '外月',
    'Recent expenses should sort by record_date desc first'
);
dashboard_summary_assert(
    $summary['recent_expenses'][1]['item'] === '同日信用卡'
    && $summary['recent_expenses'][1]['accounting_month'] === '2026/07',
    'Recent expenses should include same-day credit-card spending even when accounting_month is next month'
);
dashboard_summary_assert(
    $summary['recent_expenses'][2]['item'] === '同日現金'
    && $summary['recent_expenses'][2]['accounting_month'] === '2026/06',
    'Recent expenses should include same-day cash spending in the current accounting month'
);
foreach ($summary['recent_expenses'] as $expenseRow) {
    dashboard_summary_assert($expenseRow['item'] !== 'deleted_at 支出', 'Recent expenses should exclude deleted_at rows');
    dashboard_summary_assert(isset($expenseRow['id']), 'Recent expenses should include id for quick editing');
    dashboard_summary_assert(isset($expenseRow['entry_owner']), 'Recent expenses should include entry owner');
}
dashboard_summary_assert(count($summary['recent_incomes']) === 1, 'Recent incomes should ignore deleted and other months');

$pdo->exec("INSERT INTO expenses (record_date, item, amount, payment_method, accounting_month, entry_owner, is_deleted)
    VALUES ('2026-06-29', '展示對象 B支出', 50, '現金', '2026/06', 'profile_b', 0)");
$profile_bSummary = $service->summary('2026/06', 'profile_b');
dashboard_summary_near((float) $profile_bSummary['expense_total'], 50, 'ProfileB expense total should filter by entry owner');
dashboard_summary_assert(count($profile_bSummary['recent_expenses']) === 1, 'ProfileB recent expenses should filter by entry owner');
dashboard_summary_assert($profile_bSummary['recent_expenses'][0]['item'] === '展示對象 B支出', 'ProfileB recent expense item mismatch');
$profile_bToday = $service->todaySummary('2026-06-27', 'profile_b');
dashboard_summary_assert((int) $profile_bToday['expense_count'] === 0, 'ProfileB today count should filter by entry owner');

$todayExpenses = $service->todaySummary('2026-06-27');
dashboard_summary_near((float) $todayExpenses['expense_total'], 280, 'Today expenses should sum all payment methods by record date');
dashboard_summary_assert((int) $todayExpenses['expense_count'] === 2, 'Today expense count should exclude deleted rows');

$todaySummary = $service->todaySummary('2026-06-20');
dashboard_summary_near((float) $todaySummary['income_total'], 1000, 'Today income total mismatch');
dashboard_summary_assert((int) $todaySummary['income_count'] === 1, 'Today income count mismatch');
dashboard_summary_near((float) $todaySummary['expense_total'], 0, 'Today expense total mismatch');
dashboard_summary_assert((int) $todaySummary['expense_count'] === 0, 'Today expense count mismatch');

$fallback = $service->summary('2026/07');
dashboard_summary_near((float) $fallback['expected_work_days'], 22, 'Default work days fallback mismatch');
dashboard_summary_assert($fallback['work_days_source'] === '預設值', 'Fallback work days source mismatch');
dashboard_summary_near((float) $fallback['salary_net_total'], 32000, 'Full-attendance fallback salary mismatch');

$expenseStatement = $pdo->prepare(
    'INSERT INTO expenses (record_date, item, amount, payment_method, accounting_month, is_deleted)
     VALUES (:record_date, :item, :amount, :payment_method, :accounting_month, 0)'
);
$incomeStatement = $pdo->prepare(
    'INSERT INTO incomes (record_date, source_name, amount, account_name, accounting_month, is_deleted)
     VALUES (:record_date, :source_name, :amount, :account_name, :accounting_month, 0)'
);
for ($index = 1; $index <= 11; $index++) {
    $expenseStatement->execute([
        'record_date' => '2026-12-01',
        'item' => '同日支出' . $index,
        'amount' => $index,
        'payment_method' => '現金',
        'accounting_month' => '2026/12',
    ]);
    $incomeStatement->execute([
        'record_date' => '2026-12-01',
        'source_name' => '同日收入' . $index,
        'amount' => $index,
        'account_name' => '薪轉',
        'accounting_month' => '2026/12',
    ]);
}
$recent = $service->summary('2026/12');
dashboard_summary_assert(count($recent['recent_expenses']) === 10, 'Recent expenses should be limited to 10 rows');
dashboard_summary_assert(count($recent['recent_incomes']) === 10, 'Recent incomes should be limited to 10 rows');
dashboard_summary_assert($recent['recent_expenses'][0]['item'] === '同日支出11', 'Recent expenses should sort same-day rows by id desc');
dashboard_summary_assert($recent['recent_expenses'][9]['item'] === '同日支出2', 'Recent expenses should keep the newest 10 same-day rows');
dashboard_summary_assert($recent['recent_incomes'][0]['source_name'] === '同日收入11', 'Recent incomes should sort same-day rows by id desc');
dashboard_summary_assert($recent['recent_incomes'][9]['source_name'] === '同日收入2', 'Recent incomes should keep the newest 10 same-day rows');

$pdo->exec("UPDATE settings SET numeric_value = CASE setting_key
    WHEN 'base_salary' THEN 0
    WHEN 'full_attendance_bonus' THEN 2000
    WHEN 'attendance_allowance_unit' THEN 350
    WHEN 'overtime_134_hourly_rate' THEN 0
    WHEN 'overtime_167_hourly_rate' THEN 0
    WHEN 'overtime_2h_meal_fee' THEN 0
    WHEN 'overtime_3h_night_snack_fee' THEN 0
    WHEN 'labor_insurance_deduction' THEN 0
    WHEN 'health_insurance_deduction' THEN 0
    WHEN 'default_work_days' THEN 1
    WHEN 'annual_special_leave_days' THEN 10
    ELSE numeric_value
END");
$pdo->exec("INSERT INTO monthly_work_settings (work_month, expected_work_days) VALUES ('2026/01', 1)");
$pdo->exec("INSERT INTO monthly_work_settings (work_month, expected_work_days) VALUES ('2026/02', 1)");
$pdo->exec("INSERT INTO monthly_work_settings (work_month, expected_work_days) VALUES ('2026/03', 1)");
$pdo->exec("INSERT INTO monthly_work_settings (work_month, expected_work_days) VALUES ('2026/04', 1)");
$pdo->exec("INSERT INTO leave_logs (leave_date, leave_type, leave_days, leave_hours, total_leave_days, is_deleted)
    VALUES ('2026-01-02', '特休', 1, 0, 1, 0)");
$pdo->exec("INSERT INTO leave_logs (leave_date, leave_type, leave_days, leave_hours, total_leave_days, is_deleted)
    VALUES ('2026-02-02', '特休', 0.5, 0, 0.5, 0)");
$pdo->exec("INSERT INTO leave_logs (leave_date, leave_type, leave_days, leave_hours, total_leave_days, is_deleted)
    VALUES ('2026-03-02', '特休', 0, 2, 0.25, 0)");
$pdo->exec("INSERT INTO leave_logs (leave_date, leave_type, leave_days, leave_hours, total_leave_days, is_deleted)
    VALUES ('2026-04-02', '事假', 1, 0, 1, 0)");

$annualLeaveDay = $service->summary('2026/01');
dashboard_summary_near((float) $annualLeaveDay['salary_net_total'], 2000, 'Annual leave day should keep full attendance and only reduce attendance allowance');
$annualLeaveHalfDay = $service->summary('2026/02');
dashboard_summary_near((float) $annualLeaveHalfDay['salary_net_total'], 2175, 'Annual leave half day should keep full attendance and reduce half attendance allowance');
$annualLeaveTwoHours = $service->summary('2026/03');
dashboard_summary_near((float) $annualLeaveTwoHours['salary_net_total'], 2262.5, 'Annual leave two hours should keep full attendance and reduce attendance allowance by total_leave_days');
$personalLeave = $service->summary('2026/04');
dashboard_summary_near((float) $personalLeave['salary_net_total'], 0, 'Non-annual leave should keep the existing full-attendance deduction behavior');

echo "DashboardSummaryServiceTest passed\n";
