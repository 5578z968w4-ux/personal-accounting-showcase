<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/QuickEntryWriteService.php';

function quick_write_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function quick_trace_context(int $aiParseLogId, string $type, string $rawInput, string $source = 'quick_pwa'): array
{
    return [
        'ai_parse_log_id' => $aiParseLogId,
        'source' => $source,
        'raw_input_snapshot' => $rawInput,
        'parsed_type_snapshot' => $type,
        'parsed_json_snapshot' => json_encode(['type' => $type], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ];
}

function quick_insert_parse_log(PDO $pdo, string $rawInput, string $type, string $source): int
{
    $statement = $pdo->prepare(
        'INSERT INTO ai_parse_logs (raw_input, parsed_type, parsed_json, parse_status, source, user_name)
         VALUES (:raw_input, :parsed_type, :parsed_json, :parse_status, :source, :user_name)'
    );
    $statement->execute([
        'raw_input' => $rawInput,
        'parsed_type' => $type,
        'parsed_json' => json_encode(['type' => $type], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'parse_status' => 'success',
        'source' => $source,
        'user_name' => 'tester',
    ]);

    return (int) $pdo->lastInsertId();
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE payment_methods (
    id INTEGER PRIMARY KEY, name TEXT, settlement_start_day INTEGER,
    settlement_end_day INTEGER, is_active INTEGER
)');
$pdo->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY, name TEXT, is_active INTEGER)');
$pdo->exec('CREATE TABLE leave_types (id INTEGER PRIMARY KEY, name TEXT, is_active INTEGER)');
$pdo->exec('CREATE TABLE expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT, record_date TEXT, item TEXT, amount REAL,
    payment_method_id INTEGER, payment_method TEXT, accounting_month TEXT, category TEXT,
    raw_input TEXT, source TEXT, user_name TEXT, entry_owner TEXT DEFAULT \'profile_a\'
)');
$pdo->exec('CREATE TABLE incomes (
    id INTEGER PRIMARY KEY AUTOINCREMENT, record_date TEXT, source_name TEXT, amount REAL,
    account_id INTEGER, account_name TEXT, accounting_month TEXT, category TEXT,
    raw_input TEXT, source TEXT, user_name TEXT
)');
$pdo->exec('CREATE TABLE overtime_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, work_date TEXT UNIQUE, overtime_hours REAL,
    raw_input TEXT, note TEXT, user_name TEXT, source TEXT, is_deleted INTEGER, deleted_at TEXT
)');
$pdo->exec('CREATE TABLE leave_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, leave_date TEXT, leave_type TEXT, leave_days REAL,
    leave_hours REAL, note TEXT, raw_input TEXT, user_name TEXT, source TEXT, is_deleted INTEGER DEFAULT 0,
    deleted_at TEXT
)');
$pdo->exec('CREATE TABLE ai_parse_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, raw_input TEXT, parsed_type TEXT, parsed_json TEXT,
    parse_status TEXT, source TEXT, user_name TEXT, entry_owner TEXT DEFAULT \'profile_a\'
)');
$pdo->exec('CREATE TABLE ai_ledger_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT, ai_parse_log_id INTEGER, ledger_table TEXT, ledger_id INTEGER,
    action TEXT, source TEXT, raw_input_snapshot TEXT, parsed_type_snapshot TEXT, parsed_json_snapshot TEXT,
    user_name TEXT
)');
$pdo->exec("INSERT INTO payment_methods VALUES (1, '現金', 1, 31, 1)");
$pdo->exec("INSERT INTO payment_methods VALUES (2, '展示方式 C', 7, 6, 1)");
$pdo->exec("INSERT INTO accounts VALUES (1, '薪轉', 1)");
$pdo->exec("INSERT INTO leave_types VALUES (1, '特休', 1)");
foreach ([
    ['早餐80現金', 'expense'],
    ['薪資41189', 'income'],
    ['今天加班2小時', 'overtime'],
    ['今天加班3小時', 'overtime'],
    ['今天特休半天', 'leave'],
    ['今天特休1天', 'leave'],
    ['連結失敗測試10現金', 'expense'],
    ['錯誤支出收入log', 'income'],
    ['錯誤收入支出log', 'expense'],
    ['錯誤加班請假log', 'leave'],
] as $log) {
    $statement = $pdo->prepare(
        'INSERT INTO ai_parse_logs (raw_input, parsed_type, parsed_json, parse_status, source, user_name)
         VALUES (:raw_input, :parsed_type, :parsed_json, :parse_status, :source, :user_name)'
    );
    $statement->execute([
        'raw_input' => $log[0],
        'parsed_type' => $log[1],
        'parsed_json' => json_encode(['type' => $log[1]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'parse_status' => 'success',
        'source' => 'quick_pwa',
        'user_name' => 'tester',
    ]);
}
$statement = $pdo->prepare(
    'INSERT INTO ai_parse_logs (raw_input, parsed_type, parsed_json, parse_status, source, user_name)
     VALUES (:raw_input, :parsed_type, :parsed_json, :parse_status, :source, :user_name)'
);
$statement->execute([
    'raw_input' => '錯誤JSON類型',
    'parsed_type' => 'expense',
    'parsed_json' => json_encode(['type' => 'income'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    'parse_status' => 'success',
    'source' => 'quick_pwa',
    'user_name' => 'tester',
]);

$service = new QuickEntryWriteService($pdo);
$expense = $service->save('expense', [
    'record_date' => '2026-06-16',
    'item' => '早餐',
    'amount' => 80,
    'payment_method_id' => 1,
    'payment_method' => '現金',
    'category' => '',
], '早餐80現金', 'tester', quick_trace_context(1, 'expense', '早餐80現金'));
quick_write_assert($expense['type'] === 'expense', 'Expense summary type mismatch');
quick_write_assert($expense['ledger_table'] === 'expenses', 'Expense ledger table mismatch');
quick_write_assert((int) $expense['ledger_id'] === 1, 'Expense ledger id mismatch');
quick_write_assert((int) $expense['ai_ledger_link_id'] === 1, 'Expense link id mismatch');
quick_write_assert($expense['unit'] === '元', 'Expense summary unit mismatch');
quick_write_assert($expense['category'] === '其他', 'Expense summary category mismatch');
quick_write_assert($expense['payment_method'] === '現金', 'Expense summary payment method mismatch');
quick_write_assert($expense['accounting_month'] === '2026/06', 'Expense summary accounting month mismatch');
quick_write_assert($expense['raw_input'] === '早餐80現金', 'Expense summary raw input mismatch');
$expenseRow = $pdo->query('SELECT * FROM expenses')->fetch();
quick_write_assert($expenseRow['category'] === '其他', 'Blank category must use 其他');
quick_write_assert($expenseRow['source'] === 'quick_pwa', 'Expense source mismatch');
quick_write_assert($expenseRow['entry_owner'] === 'profile_a', 'Default expense entry owner mismatch');
quick_write_assert($expenseRow['accounting_month'] === '2026/06', 'Expense accounting month mismatch');
$expenseLink = $pdo->query('SELECT * FROM ai_ledger_links WHERE id = 1')->fetch();
quick_write_assert((int) $expenseLink['ai_parse_log_id'] === 1, 'Expense link log id mismatch');
quick_write_assert($expenseLink['ledger_table'] === 'expenses', 'Expense link table mismatch');
quick_write_assert((int) $expenseLink['ledger_id'] === (int) $expense['ledger_id'], 'Expense link ledger id mismatch');
quick_write_assert($expenseLink['action'] === 'created', 'Expense link action mismatch');
quick_write_assert($expenseLink['source'] === 'quick_pwa', 'Expense link source mismatch');
quick_write_assert($expenseLink['raw_input_snapshot'] === '早餐80現金', 'Expense link raw snapshot mismatch');
quick_write_assert($expenseLink['parsed_type_snapshot'] === 'expense', 'Expense link parsed type mismatch');
quick_write_assert($expenseLink['parsed_json_snapshot'] !== '', 'Expense link parsed JSON missing');

$stringAmountExpense = $service->save('expense', [
    'record_date' => '2026-06-16',
    'item' => '字串金額',
    'amount' => '1',
    'payment_method_id' => 1,
    'payment_method' => '現金',
    'category' => '其他',
], '字串金額1現金', 'tester');
quick_write_assert((float) $stringAmountExpense['amount'] === 1.0, 'String numeric amount must be accepted');
quick_write_assert(
    (float) $pdo->query("SELECT amount FROM expenses WHERE item = '字串金額'")->fetchColumn() === 1.0,
    'String numeric amount row mismatch'
);

$julyExpense = $service->save('expense', [
    'record_date' => '2026-07-01',
    'item' => '跨月早餐',
    'amount' => 1,
    'payment_method_id' => 1,
    'payment_method' => '現金',
    'category' => '餐飲',
], '跨月早餐1現金', 'tester');
quick_write_assert($julyExpense['accounting_month'] === '2026/07', 'July expense accounting month mismatch');
quick_write_assert(
    $pdo->query("SELECT accounting_month FROM expenses WHERE raw_input = '跨月早餐1現金'")->fetchColumn() === '2026/07',
    'July expense accounting month row mismatch'
);

$cashJuneExpense = $service->save('expense', [
    'record_date' => '2026-06-27',
    'item' => '晚餐',
    'amount' => 200,
    'payment_method_id' => 1,
    'payment_method' => '現金',
    'category' => '餐飲',
], '晚餐200現金', 'tester');
quick_write_assert($cashJuneExpense['accounting_month'] === '2026/06', 'Cash 6/27 expense accounting month mismatch');
quick_write_assert(
    $pdo->query("SELECT accounting_month FROM expenses WHERE raw_input = '晚餐200現金'")->fetchColumn() === '2026/06',
    'Cash 6/27 expense accounting month row mismatch'
);

$cashJune29Expense = $service->save('expense', [
    'record_date' => '2026-06-29',
    'item' => '早餐',
    'amount' => 80,
    'payment_method_id' => 1,
    'payment_method' => '現金',
    'accounting_month' => '2026/07',
    'category' => '餐飲',
], '早餐80現金錯誤月份', 'tester');
quick_write_assert(
    $cashJune29Expense['accounting_month'] === '2026/06',
    'Write service must recalculate cash 6/29 accounting month instead of trusting payload'
);
quick_write_assert(
    $pdo->query("SELECT accounting_month FROM expenses WHERE raw_input = '早餐80現金錯誤月份'")->fetchColumn() === '2026/06',
    'Cash 6/29 recalculated accounting month row mismatch'
);

$cardJulyExpense = $service->save('expense', [
    'record_date' => '2026-06-27',
    'item' => '信用卡消費',
    'amount' => 500,
    'payment_method_id' => 2,
    'payment_method' => '展示方式 C',
    'category' => '生活',
], '信用卡消費500展示方式 C', 'tester');
quick_write_assert($cardJulyExpense['accounting_month'] === '2026/07', 'Credit-card 6/27 expense accounting month mismatch');
quick_write_assert(
    $pdo->query("SELECT accounting_month FROM expenses WHERE raw_input = '信用卡消費500展示方式 C'")->fetchColumn() === '2026/07',
    'Credit-card 6/27 expense accounting month row mismatch'
);

$profile_bExpense = $service->save('expense', [
    'record_date' => '2026-06-27',
    'item' => '展示對象 B早餐',
    'amount' => 80,
    'payment_method_id' => 1,
    'payment_method' => '現金',
    'category' => '餐飲',
    'entry_owner' => 'profile_b',
], '展示對象 B早餐80', 'tester');
quick_write_assert($profile_bExpense['entry_owner'] === '展示對象 B', 'ProfileB expense summary owner label mismatch');
quick_write_assert(
    $pdo->query("SELECT entry_owner FROM expenses WHERE raw_input = '展示對象 B早餐80'")->fetchColumn() === 'profile_b',
    'ProfileB expense row owner mismatch'
);

$income = $service->save('income', [
    'record_date' => '2026-06-16',
    'source_name' => '薪資',
    'amount' => 41189,
    'account_id' => null,
    'account_name' => '',
    'category' => '薪資',
], '薪資41189', 'tester', quick_trace_context(2, 'income', '薪資41189'));
quick_write_assert($income['type'] === 'income', 'Income summary type mismatch');
quick_write_assert($income['ledger_table'] === 'incomes', 'Income ledger table mismatch');
quick_write_assert((int) $income['ledger_id'] === 1, 'Income ledger id mismatch');
quick_write_assert($income['unit'] === '元', 'Income summary unit mismatch');
quick_write_assert($income['category'] === '薪資', 'Income summary category mismatch');
quick_write_assert($income['account_name'] === '未指定帳戶', 'Income summary account mismatch');
quick_write_assert($income['accounting_month'] === '2026/06', 'Income summary accounting month mismatch');
quick_write_assert($income['raw_input'] === '薪資41189', 'Income summary raw input mismatch');
$incomeRow = $pdo->query('SELECT * FROM incomes')->fetch();
quick_write_assert($incomeRow['accounting_month'] === '2026/06', 'Income accounting month mismatch');
quick_write_assert(
    (int) $pdo->query("SELECT COUNT(*) FROM ai_ledger_links WHERE ledger_table = 'incomes'")->fetchColumn() === 1,
    'Income link missing'
);

$service->save('overtime', [
    'work_date' => '2026-06-16',
    'overtime_hours' => 2,
], '今天加班2小時', 'tester', quick_trace_context(3, 'overtime', '今天加班2小時'));
$overtime = $service->save('overtime', [
    'work_date' => '2026-06-16',
    'overtime_hours' => 3,
], '今天加班3小時', 'tester', quick_trace_context(4, 'overtime', '今天加班3小時'));
quick_write_assert($overtime['action'] === 'updated', 'Overtime must update the same date');
quick_write_assert($overtime['ledger_table'] === 'overtime_logs', 'Overtime ledger table mismatch');
quick_write_assert((int) $overtime['ledger_id'] === 1, 'Overtime ledger id mismatch');
quick_write_assert($overtime['unit'] === '小時', 'Overtime summary unit mismatch');
quick_write_assert($overtime['raw_input'] === '今天加班3小時', 'Overtime summary raw input mismatch');
quick_write_assert((int) $pdo->query('SELECT COUNT(*) FROM overtime_logs')->fetchColumn() === 1, 'Overtime duplicated');
quick_write_assert((float) $pdo->query('SELECT overtime_hours FROM overtime_logs')->fetchColumn() === 3.0, 'Overtime hours mismatch');
quick_write_assert($pdo->query('SELECT source FROM overtime_logs')->fetchColumn() === 'quick_pwa', 'Overtime source mismatch');
quick_write_assert(
    (int) $pdo->query("SELECT COUNT(*) FROM ai_ledger_links WHERE ledger_table = 'overtime_logs'")->fetchColumn() === 2,
    'Overtime created/updated links missing'
);
quick_write_assert(
    $pdo->query("SELECT action FROM ai_ledger_links WHERE ledger_table = 'overtime_logs' ORDER BY id DESC LIMIT 1")->fetchColumn() === 'updated',
    'Overtime updated link action mismatch'
);

$iosOvertimeLogId = quick_insert_parse_log($pdo, '今天加班 3 小時', 'overtime', 'ios_shortcut');
$iosOvertime = $service->save('overtime', [
    'work_date' => '2026-06-19',
    'overtime_hours' => 3,
], '今天加班 3 小時', 'tester', quick_trace_context($iosOvertimeLogId, 'overtime', '今天加班 3 小時', 'ios_shortcut'), 'ios_shortcut');
quick_write_assert($iosOvertime['type'] === 'overtime', 'iOS Shortcut overtime summary type mismatch');
quick_write_assert((float) $iosOvertime['overtime_hours'] === 3.0, 'iOS Shortcut overtime summary hours mismatch');
quick_write_assert($iosOvertime['unit'] === '小時', 'iOS Shortcut overtime summary unit mismatch');
quick_write_assert(
    $pdo->query("SELECT source FROM overtime_logs WHERE raw_input = '今天加班 3 小時'")->fetchColumn() === 'ios_shortcut',
    'iOS Shortcut overtime source mismatch'
);
quick_write_assert(
    $pdo->query("SELECT source FROM ai_ledger_links WHERE ai_parse_log_id = {$iosOvertimeLogId}")->fetchColumn() === 'ios_shortcut',
    'iOS Shortcut overtime trace source mismatch'
);

$service->save('leave', [
    'leave_date' => '2026-06-16',
    'leave_type' => '特休',
    'leave_days' => 0.5,
    'leave_hours' => 0,
    'note' => '',
], '今天特休半天', 'tester', quick_trace_context(5, 'leave', '今天特休半天'));
$leave = $service->save('leave', [
    'leave_date' => '2026-06-16',
    'leave_type' => '特休',
    'leave_days' => 1,
    'leave_hours' => 0,
    'note' => '',
], '今天特休1天', 'tester', quick_trace_context(6, 'leave', '今天特休1天'));
quick_write_assert($leave['action'] === 'updated', 'Leave must update the same date');
quick_write_assert($leave['ledger_table'] === 'leave_logs', 'Leave ledger table mismatch');
quick_write_assert((int) $leave['ledger_id'] === 1, 'Leave ledger id mismatch');
quick_write_assert($leave['unit'] === '天', 'Leave summary unit mismatch');
quick_write_assert($leave['raw_input'] === '今天特休1天', 'Leave summary raw input mismatch');
quick_write_assert((int) $pdo->query('SELECT COUNT(*) FROM leave_logs')->fetchColumn() === 1, 'Leave duplicated');
quick_write_assert((float) $pdo->query('SELECT leave_days FROM leave_logs')->fetchColumn() === 1.0, 'Leave days mismatch');
quick_write_assert($pdo->query('SELECT source FROM leave_logs')->fetchColumn() === 'quick_pwa', 'Leave source mismatch');
quick_write_assert($pdo->query('SELECT raw_input FROM leave_logs')->fetchColumn() === '今天特休1天', 'Leave raw input mismatch');
quick_write_assert(
    (int) $pdo->query("SELECT COUNT(*) FROM ai_ledger_links WHERE ledger_table = 'leave_logs'")->fetchColumn() === 2,
    'Leave created/updated links missing'
);
quick_write_assert(
    $pdo->query("SELECT action FROM ai_ledger_links WHERE ledger_table = 'leave_logs' ORDER BY id DESC LIMIT 1")->fetchColumn() === 'updated',
    'Leave updated link action mismatch'
);

$before = (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
$rejected = false;
try {
    $service->save('expense', [
        'record_date' => '2026-06-16',
        'item' => '',
        'amount' => 100,
        'payment_method_id' => null,
    ], '100', 'tester');
} catch (QuickEntryValidationException $exception) {
    $rejected = isset($exception->fieldErrors()['item'], $exception->fieldErrors()['payment_method_id']);
}
quick_write_assert($rejected, 'Incomplete expense must be rejected');
quick_write_assert((int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === $before, 'Rejected expense was written');

$unsupportedBefore = [
    'expenses' => (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn(),
    'incomes' => (int) $pdo->query('SELECT COUNT(*) FROM incomes')->fetchColumn(),
    'overtime_logs' => (int) $pdo->query('SELECT COUNT(*) FROM overtime_logs')->fetchColumn(),
    'leave_logs' => (int) $pdo->query('SELECT COUNT(*) FROM leave_logs')->fetchColumn(),
    'ai_ledger_links' => (int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn(),
];

$profile_bIncomeBefore = (int) $pdo->query('SELECT COUNT(*) FROM incomes')->fetchColumn();
$profile_bIncomeRejected = false;
try {
    $service->save('income', [
        'record_date' => '2026-06-16',
        'source_name' => '收入',
        'amount' => 1000,
        'account_id' => null,
        'account_name' => '',
        'category' => '薪資',
        'entry_owner' => 'profile_b',
    ], '收入1000', 'tester');
} catch (QuickEntryValidationException $exception) {
    $profile_bIncomeRejected = isset($exception->fieldErrors()['entry_owner']);
}
quick_write_assert($profile_bIncomeRejected, 'ProfileB income must be rejected');
quick_write_assert((int) $pdo->query('SELECT COUNT(*) FROM incomes')->fetchColumn() === $profile_bIncomeBefore, 'ProfileB income left an income row');
$unsupportedRejected = false;
try {
    $service->save('transfer', [
        'record_date' => '2026-06-16',
        'amount' => 100,
    ], '轉帳100', 'tester');
} catch (QuickEntryValidationException $exception) {
    $unsupportedRejected = isset($exception->fieldErrors()['type']);
}
quick_write_assert($unsupportedRejected, 'Unsupported quick entry type must be rejected before writing');
foreach ($unsupportedBefore as $table => $count) {
    quick_write_assert(
        (int) $pdo->query(sprintf('SELECT COUNT(*) FROM %s', $table))->fetchColumn() === $count,
        sprintf('Unsupported type changed %s rows', $table)
    );
}

$invalidPaymentBefore = (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
$invalidPaymentLinksBefore = (int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn();
$invalidPaymentRejected = false;
try {
    $service->save('expense', [
        'record_date' => '2026-06-16',
        'item' => '無效付款',
        'amount' => 100,
        'payment_method_id' => 999,
        'payment_method' => '不存在',
        'category' => '其他',
    ], '無效付款100', 'tester', quick_trace_context(1, 'expense', '無效付款100'));
} catch (QuickEntryValidationException $exception) {
    $invalidPaymentRejected = isset($exception->fieldErrors()['payment_method_id']);
}
quick_write_assert($invalidPaymentRejected, 'Invalid payment method must be rejected');
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === $invalidPaymentBefore,
    'Invalid payment method left an expense row'
);
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn() === $invalidPaymentLinksBefore,
    'Invalid payment method left a trace link'
);

$invalidAccountBefore = (int) $pdo->query('SELECT COUNT(*) FROM incomes')->fetchColumn();
$invalidAccountLinksBefore = (int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn();
$invalidAccountRejected = false;
try {
    $service->save('income', [
        'record_date' => '2026-06-16',
        'source_name' => '無效帳戶',
        'amount' => 100,
        'account_id' => 999,
        'account_name' => '不存在',
        'category' => '其他',
    ], '無效帳戶100', 'tester', quick_trace_context(2, 'income', '無效帳戶100'));
} catch (QuickEntryValidationException $exception) {
    $invalidAccountRejected = isset($exception->fieldErrors()['account_id']);
}
quick_write_assert($invalidAccountRejected, 'Invalid income account must be rejected');
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM incomes')->fetchColumn() === $invalidAccountBefore,
    'Invalid income account left an income row'
);
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn() === $invalidAccountLinksBefore,
    'Invalid income account left a trace link'
);

$zeroLeaveBefore = (int) $pdo->query('SELECT COUNT(*) FROM leave_logs')->fetchColumn();
$zeroLeaveLinksBefore = (int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn();
$zeroLeaveRejected = false;
try {
    $service->save('leave', [
        'leave_date' => '2026-06-17',
        'leave_type' => '特休',
        'leave_days' => 0,
        'leave_hours' => 0,
        'note' => '',
    ], '特休0天0小時', 'tester', quick_trace_context(5, 'leave', '特休0天0小時'));
} catch (QuickEntryValidationException $exception) {
    $zeroLeaveRejected = isset($exception->fieldErrors()['leave_days']);
}
quick_write_assert($zeroLeaveRejected, 'Zero-length leave must be rejected');
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM leave_logs')->fetchColumn() === $zeroLeaveBefore,
    'Zero-length leave left a leave row'
);
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn() === $zeroLeaveLinksBefore,
    'Zero-length leave left a trace link'
);

$expenseBeforeMismatch = (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
$linksBeforeMismatch = (int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn();
$expenseMismatchRejected = false;
try {
    $service->save('expense', [
        'record_date' => '2026-06-17',
        'item' => '錯誤支出',
        'amount' => 20,
        'payment_method_id' => 1,
        'payment_method' => '現金',
        'category' => '其他',
    ], '錯誤支出收入log', 'tester', quick_trace_context(8, 'income', '錯誤支出收入log'));
} catch (InvalidArgumentException $exception) {
    $expenseMismatchRejected = str_contains($exception->getMessage(), 'parsed type');
}
quick_write_assert($expenseMismatchRejected, 'Expense writer must reject an income parse log id');
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === $expenseBeforeMismatch,
    'Expense type mismatch left a ledger row'
);
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn() === $linksBeforeMismatch,
    'Expense type mismatch left a trace link'
);

$incomeBeforeMismatch = (int) $pdo->query('SELECT COUNT(*) FROM incomes')->fetchColumn();
$incomeMismatchRejected = false;
try {
    $service->save('income', [
        'record_date' => '2026-06-17',
        'source_name' => '錯誤收入',
        'amount' => 30,
        'account_id' => null,
        'account_name' => '',
        'category' => '其他',
    ], '錯誤收入支出log', 'tester', quick_trace_context(9, 'expense', '錯誤收入支出log'));
} catch (InvalidArgumentException $exception) {
    $incomeMismatchRejected = str_contains($exception->getMessage(), 'parsed type');
}
quick_write_assert($incomeMismatchRejected, 'Income writer must reject an expense parse log id');
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM incomes')->fetchColumn() === $incomeBeforeMismatch,
    'Income type mismatch left a ledger row'
);
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn() === $linksBeforeMismatch,
    'Income type mismatch left a trace link'
);

$overtimeBeforeMismatch = (int) $pdo->query('SELECT COUNT(*) FROM overtime_logs')->fetchColumn();
$overtimeMismatchRejected = false;
try {
    $service->save('overtime', [
        'work_date' => '2026-06-17',
        'overtime_hours' => 1,
    ], '錯誤加班請假log', 'tester', quick_trace_context(10, 'leave', '錯誤加班請假log'));
} catch (InvalidArgumentException $exception) {
    $overtimeMismatchRejected = str_contains($exception->getMessage(), 'parsed type');
}
quick_write_assert($overtimeMismatchRejected, 'Overtime writer must reject a leave parse log id');
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM overtime_logs')->fetchColumn() === $overtimeBeforeMismatch,
    'Overtime type mismatch left a ledger row'
);
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn() === $linksBeforeMismatch,
    'Overtime type mismatch left a trace link'
);

$expenseBeforeJsonMismatch = (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
$jsonMismatchRejected = false;
try {
    $service->save('expense', [
        'record_date' => '2026-06-18',
        'item' => '錯誤JSON',
        'amount' => 40,
        'payment_method_id' => 1,
        'payment_method' => '現金',
        'category' => '其他',
    ], '錯誤JSON類型', 'tester', quick_trace_context(11, 'expense', '錯誤JSON類型'));
} catch (InvalidArgumentException $exception) {
    $jsonMismatchRejected = str_contains($exception->getMessage(), 'parsed JSON type mismatch');
}
quick_write_assert($jsonMismatchRejected, 'Parsed JSON type mismatch must be rejected');
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === $expenseBeforeJsonMismatch,
    'Parsed JSON mismatch left a ledger row'
);
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn() === $linksBeforeMismatch,
    'Parsed JSON mismatch left a trace link'
);

$pdo->exec("CREATE TRIGGER reject_income_insert BEFORE INSERT ON incomes
    BEGIN
        SELECT RAISE(FAIL, 'forced failure');
    END");
$incomeBefore = (int) $pdo->query('SELECT COUNT(*) FROM incomes')->fetchColumn();
$rolledBack = false;
try {
    $service->save('income', [
        'record_date' => '2026-06-16',
        'source_name' => '交易測試',
        'amount' => 1,
        'account_id' => null,
        'account_name' => '',
        'category' => '其他',
    ], '交易 rollback 測試', 'tester');
} catch (PDOException) {
    $rolledBack = !$pdo->inTransaction();
}
quick_write_assert($rolledBack, 'Failed write must roll back the transaction');
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM incomes')->fetchColumn() === $incomeBefore,
    'Failed transaction left an income row'
);

$pdo->exec("CREATE TRIGGER reject_ai_ledger_link_insert BEFORE INSERT ON ai_ledger_links
    BEGIN
        SELECT RAISE(FAIL, 'forced link failure');
    END");
$expenseBeforeLinkFailure = (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
$linkFailed = false;
try {
    $service->save('expense', [
        'record_date' => '2026-06-17',
        'item' => '連結失敗測試',
        'amount' => 10,
        'payment_method_id' => 1,
        'payment_method' => '現金',
        'category' => '其他',
    ], '連結失敗測試10現金', 'tester', quick_trace_context(7, 'expense', '連結失敗測試10現金'));
} catch (PDOException) {
    $linkFailed = !$pdo->inTransaction();
}
quick_write_assert($linkFailed, 'Failed trace link must roll back the transaction');
quick_write_assert(
    (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === $expenseBeforeLinkFailure,
    'Failed trace link left an expense row'
);

echo "QuickEntryWriteServiceTest passed\n";
