<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/AdminAiInputWriteService.php';

function admin_ai_write_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function admin_ai_create_pdo(): PDO
{
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
        user_name TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec("INSERT INTO payment_methods VALUES (1, '現金', 1, 31, 1)");
    $pdo->exec("INSERT INTO payment_methods VALUES (2, '展示方式 C', 7, 6, 1)");
    $pdo->exec("INSERT INTO accounts VALUES (1, '薪轉', 1)");
    $pdo->exec("INSERT INTO leave_types VALUES (1, '特休', 1)");

    return $pdo;
}

function admin_ai_insert_parse_log(PDO $pdo, string $rawInput, string $type): int
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
        'source' => AdminAiInputWriteService::SOURCE,
        'user_name' => 'admin',
    ]);

    return (int) $pdo->lastInsertId();
}

$pdo = admin_ai_create_pdo();
$service = new AdminAiInputWriteService($pdo);

admin_ai_write_assert((int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === 0, 'Preview baseline should have no expenses');
admin_ai_write_assert((int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn() === 0, 'Preview baseline should have no links');

$cashLogId = admin_ai_insert_parse_log($pdo, '早餐80現金', 'expense');
admin_ai_write_assert((int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === 0, 'Preview-only parse log should not write expenses');
admin_ai_write_assert((int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn() === 0, 'Preview-only parse log should not create links');

$cashSummary = $service->saveParsed([
    'ai_parse_log_id' => $cashLogId,
    'type' => 'expense',
    'fields' => [
        'record_date' => '2026-06-27',
        'item' => '早餐',
        'amount' => 80,
        'payment_method_id' => 1,
        'payment_method' => '現金',
        'category' => '餐飲',
    ],
], '早餐80現金', 'admin');
admin_ai_write_assert($cashSummary['type'] === 'expense', 'Cash expense summary type mismatch');
admin_ai_write_assert($cashSummary['accounting_month'] === '2026/06', 'Cash accounting month mismatch');
admin_ai_write_assert((int) $cashSummary['ai_ledger_link_id'] === 1, 'Cash trace link missing');

$cashRow = $pdo->query("SELECT * FROM expenses WHERE raw_input = '早餐80現金'")->fetch();
admin_ai_write_assert($cashRow['source'] === AdminAiInputWriteService::SOURCE, 'Admin expense source mismatch');
admin_ai_write_assert($cashRow['accounting_month'] === '2026/06', 'Cash row accounting month mismatch');

$cardLogId = admin_ai_insert_parse_log($pdo, '信用卡消費500展示方式 C', 'expense');
$cardSummary = $service->confirm($cardLogId, 'expense', [
    'record_date' => '2026-06-27',
    'item' => '信用卡消費',
    'amount' => 500,
    'payment_method_id' => 2,
    'payment_method' => '展示方式 C',
    'category' => '生活',
], '信用卡消費500展示方式 C', 'admin');
admin_ai_write_assert($cardSummary['accounting_month'] === '2026/07', 'Credit-card accounting month mismatch');

$incomeLogId = admin_ai_insert_parse_log($pdo, '薪資50000', 'income');
$incomeSummary = $service->confirm($incomeLogId, 'income', [
    'record_date' => '2026-06-27',
    'source_name' => '薪資',
    'amount' => 50000,
    'account_id' => 1,
    'account_name' => '薪轉',
    'category' => '薪資',
], '薪資50000', 'admin');
admin_ai_write_assert($incomeSummary['type'] === 'income', 'Income summary type mismatch');
admin_ai_write_assert($incomeSummary['account_name'] === '薪轉', 'Income account mismatch');

$overtimeLogId = admin_ai_insert_parse_log($pdo, '今天加班3小時', 'overtime');
$overtimeSummary = $service->confirm($overtimeLogId, 'overtime', [
    'work_date' => '2026-06-27',
    'overtime_hours' => 3,
], '今天加班3小時', 'admin');
admin_ai_write_assert($overtimeSummary['type'] === 'overtime', 'Overtime summary type mismatch');
admin_ai_write_assert((float) $overtimeSummary['overtime_hours'] === 3.0, 'Overtime hours mismatch');

$leaveLogId = admin_ai_insert_parse_log($pdo, '今天特休半天', 'leave');
$leaveSummary = $service->confirm($leaveLogId, 'leave', [
    'leave_date' => '2026-06-27',
    'leave_type' => '特休',
    'leave_days' => 0.5,
    'leave_hours' => 0,
    'note' => '',
], '今天特休半天', 'admin');
admin_ai_write_assert($leaveSummary['type'] === 'leave', 'Leave summary type mismatch');
admin_ai_write_assert((float) $leaveSummary['amount'] === 0.5, 'Leave amount mismatch');

$countsAfterWrites = [
    'expenses' => (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn(),
    'incomes' => (int) $pdo->query('SELECT COUNT(*) FROM incomes')->fetchColumn(),
    'overtime_logs' => (int) $pdo->query('SELECT COUNT(*) FROM overtime_logs')->fetchColumn(),
    'leave_logs' => (int) $pdo->query('SELECT COUNT(*) FROM leave_logs')->fetchColumn(),
    'ai_ledger_links' => (int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn(),
];

$duplicateRejected = false;
try {
    $service->saveParsed([
        'ai_parse_log_id' => $cashLogId,
        'type' => 'expense',
        'fields' => [
            'record_date' => '2026-06-27',
            'item' => '早餐',
            'amount' => 80,
            'payment_method_id' => 1,
            'payment_method' => '現金',
            'category' => '餐飲',
        ],
    ], '早餐80現金', 'admin');
} catch (AdminAiInputAlreadyWrittenException $exception) {
    $duplicateRejected = ((int) ($exception->link()['ai_parse_log_id'] ?? 0) === $cashLogId);
}
admin_ai_write_assert($duplicateRejected, 'Duplicate confirmation must be rejected');
foreach ($countsAfterWrites as $table => $count) {
    admin_ai_write_assert(
        (int) $pdo->query(sprintf('SELECT COUNT(*) FROM %s', $table))->fetchColumn() === $count,
        sprintf('Duplicate confirmation changed %s rows', $table)
    );
}

$invalidLogId = admin_ai_insert_parse_log($pdo, '無金額現金', 'expense');
$invalidRejected = false;
try {
    $service->saveParsed([
        'ai_parse_log_id' => $invalidLogId,
        'type' => 'expense',
        'fields' => [
            'record_date' => '2026-06-27',
            'item' => '無金額',
            'amount' => '',
            'payment_method_id' => 1,
            'payment_method' => '現金',
            'category' => '其他',
        ],
    ], '無金額現金', 'admin');
} catch (QuickEntryValidationException $exception) {
    $invalidRejected = isset($exception->fieldErrors()['amount']);
}
admin_ai_write_assert($invalidRejected, 'Incomplete admin expense must be rejected');
foreach ($countsAfterWrites as $table => $count) {
    admin_ai_write_assert(
        (int) $pdo->query(sprintf('SELECT COUNT(*) FROM %s', $table))->fetchColumn() === $count,
        sprintf('Invalid confirmation changed %s rows', $table)
    );
}

$links = $pdo->query('SELECT source FROM ai_ledger_links ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
admin_ai_write_assert(count($links) === 5, 'Admin trace link count mismatch');
foreach ($links as $source) {
    admin_ai_write_assert($source === AdminAiInputWriteService::SOURCE, 'Admin trace link source mismatch');
}

echo "AdminAiInputWriteServiceTest passed\n";
