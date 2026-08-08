<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/AiLedgerTraceDisplayService.php';

function ai_trace_display_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE ai_parse_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    raw_input TEXT,
    ai_response TEXT,
    provider TEXT,
    model_name TEXT,
    parsed_type TEXT,
    parsed_json TEXT,
    parse_status TEXT,
    error_code TEXT,
    error_message TEXT,
    duration_ms INTEGER,
    source TEXT,
    user_name TEXT,
    created_at TEXT
)');
$pdo->exec('CREATE TABLE ai_ledger_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ai_parse_log_id INTEGER,
    ledger_table TEXT,
    ledger_id INTEGER,
    action TEXT,
    source TEXT,
    raw_input_snapshot TEXT,
    parsed_type_snapshot TEXT,
    parsed_json_snapshot TEXT,
    user_name TEXT,
    created_at TEXT
)');

$logInsert = $pdo->prepare(
    'INSERT INTO ai_parse_logs (
        id, raw_input, ai_response, provider, model_name, parsed_type, parsed_json,
        parse_status, error_code, error_message, duration_ms, source, user_name, created_at
     ) VALUES (
        :id, :raw_input, :ai_response, :provider, :model_name, :parsed_type, :parsed_json,
        :parse_status, :error_code, :error_message, :duration_ms, :source, :user_name, :created_at
     )'
);
foreach ([
    [10, '早餐80現金', 'expense'],
    [11, '薪資50000', 'income'],
    [12, '加班2小時', 'overtime'],
    [13, '特休1天', 'leave'],
] as $row) {
    $logInsert->execute([
        'id' => $row[0],
        'raw_input' => $row[1],
        'ai_response' => null,
        'provider' => 'gemini',
        'model_name' => 'test-model',
        'parsed_type' => $row[2],
        'parsed_json' => json_encode(['type' => $row[2]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'parse_status' => 'success',
        'error_code' => null,
        'error_message' => null,
        'duration_ms' => 123,
        'source' => 'quick_pwa',
        'user_name' => 'tester',
        'created_at' => '2026-06-21 08:00:00',
    ]);
}

$insert = $pdo->prepare(
    'INSERT INTO ai_ledger_links (
        ai_parse_log_id, ledger_table, ledger_id, action, source,
        raw_input_snapshot, parsed_type_snapshot, parsed_json_snapshot, user_name, created_at
     ) VALUES (
        :ai_parse_log_id, :ledger_table, :ledger_id, :action, :source,
        :raw_input_snapshot, :parsed_type_snapshot, :parsed_json_snapshot, :user_name, :created_at
     )'
);
foreach ([
    [10, 'expenses', 100, 'created', 'quick_pwa', '早餐80現金', 'expense', '2026-06-21 09:00:00'],
    [10, 'expenses', 100, 'updated', 'quick_pwa', '早餐改90現金', 'expense', '2026-06-21 09:05:00'],
    [11, 'incomes', 200, 'created', 'quick_pwa', '薪資50000', 'income', '2026-06-21 10:00:00'],
    [12, 'overtime_logs', 300, 'created', 'quick_pwa', '加班2小時', 'overtime', '2026-06-21 11:00:00'],
    [13, 'leave_logs', 400, 'created', 'quick_pwa', '特休1天', 'leave', '2026-06-21 12:00:00'],
] as $row) {
    $insert->execute([
        'ai_parse_log_id' => $row[0],
        'ledger_table' => $row[1],
        'ledger_id' => $row[2],
        'action' => $row[3],
        'source' => $row[4],
        'raw_input_snapshot' => $row[5],
        'parsed_type_snapshot' => $row[6],
        'parsed_json_snapshot' => json_encode(['type' => $row[6]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'user_name' => 'tester',
        'created_at' => $row[7],
    ]);
}

$service = new AiLedgerTraceDisplayService($pdo);

$byLog = $service->latestLinksByParseLogIds([10, 11, 999, 10, 0]);
ai_trace_display_assert(count($byLog) === 2, 'Parse log links must ignore duplicates and missing ids');
ai_trace_display_assert($byLog[10]['action'] === 'updated', 'Parse log latest link must use newest id');
ai_trace_display_assert((int) $byLog[10]['link_count'] === 2, 'Parse log link count mismatch');
ai_trace_display_assert($byLog[10]['raw_input_snapshot'] === '早餐改90現金', 'Parse log latest snapshot mismatch');
ai_trace_display_assert($byLog[10]['created_at'] === '2026-06-21 17:05:00', 'Parse log latest link time must display Asia/Taipei');
ai_trace_display_assert($byLog[11]['ledger_table'] === 'incomes', 'Income parse log table mismatch');

$parseLog = $service->parseLogById(10);
ai_trace_display_assert(is_array($parseLog), 'Parse log detail must be found');
ai_trace_display_assert($parseLog['raw_input'] === '早餐80現金', 'Parse log detail raw input mismatch');
ai_trace_display_assert($parseLog['created_at'] === '2026-06-21 16:00:00', 'Parse log detail time must display Asia/Taipei');
ai_trace_display_assert($service->parseLogById(999) === null, 'Missing parse log detail must return null');

$logLinks = $service->linksByParseLogId(10);
ai_trace_display_assert(count($logLinks) === 2, 'Parse log detail must return full link history');
ai_trace_display_assert($logLinks[0]['action'] === 'updated', 'Parse log detail history must be newest first');

$byExpense = $service->latestLinksByLedgerRows('expenses', [100, 999, 100]);
ai_trace_display_assert(count($byExpense) === 1, 'Ledger links must ignore duplicates and missing ids');
ai_trace_display_assert((int) $byExpense[100]['ai_parse_log_id'] === 10, 'Ledger latest log id mismatch');
ai_trace_display_assert($byExpense[100]['action'] === 'updated', 'Ledger latest action mismatch');
ai_trace_display_assert((int) $byExpense[100]['link_count'] === 2, 'Ledger link count mismatch');

$ledgerLinks = $service->linksByLedgerRow('expenses', 100);
ai_trace_display_assert(count($ledgerLinks) === 2, 'Ledger detail must return full link history');
ai_trace_display_assert($ledgerLinks[0]['log_raw_input'] === '早餐80現金', 'Ledger detail must join parse log fields');
ai_trace_display_assert($ledgerLinks[0]['parse_status'] === 'success', 'Ledger detail parse status mismatch');
ai_trace_display_assert($ledgerLinks[0]['log_created_at'] === '2026-06-21 16:00:00', 'Ledger detail log time must display Asia/Taipei');

$byOvertime = $service->latestLinksByLedgerRows('overtime_logs', [300]);
ai_trace_display_assert($byOvertime[300]['raw_input_snapshot'] === '加班2小時', 'Overtime trace snapshot mismatch');

$byLeave = $service->latestLinksByLedgerRows('leave_logs', [400]);
ai_trace_display_assert($byLeave[400]['parsed_type_snapshot'] === 'leave', 'Leave parsed type mismatch');

ai_trace_display_assert($service->latestLinksByParseLogIds([]) === [], 'Empty parse log list must return empty');
ai_trace_display_assert($service->latestLinksByLedgerRows('incomes', []) === [], 'Empty ledger id list must return empty');
ai_trace_display_assert(AiLedgerTraceDisplayService::ledgerLabel('expenses') === '支出', 'Ledger label mismatch');
ai_trace_display_assert(AiLedgerTraceDisplayService::actionLabel('created') === '新增', 'Action label mismatch');
ai_trace_display_assert(AiLedgerTraceDisplayService::sourceLabel('quick_pwa') === 'Quick Entry / PWA', 'Source label mismatch');
ai_trace_display_assert(AiLedgerTraceDisplayService::sourceLabel('ios_shortcut') === 'iOS Shortcut', 'iOS Shortcut source label mismatch');
ai_trace_display_assert(AiLedgerTraceDisplayService::sourceLabel('shortcut_api') === 'Shortcut API', 'Shortcut API source label mismatch');
ai_trace_display_assert(AiLedgerTraceDisplayService::sourceLabel('admin_ai_input') === '後台 AI 快速輸入', 'Admin AI source label mismatch');
ai_trace_display_assert(AiLedgerTraceDisplayService::sourceLabel('quick_entry_check') === 'Quick Entry 驗收腳本', 'Quick Entry check source label mismatch');
ai_trace_display_assert(AiLedgerTraceDisplayService::textOrDash(null) === '-', 'Null text must render as dash');
ai_trace_display_assert(AiLedgerTraceDisplayService::textOrDash(' 早餐 ') === '早餐', 'Text label must trim whitespace');
ai_trace_display_assert(
    AiLedgerTraceDisplayService::dateTimeLabel('2026-06-24 15:52:00') === '2026-06-24 23:52:00',
    'UTC timestamp must display as Asia/Taipei'
);
ai_trace_display_assert(AiLedgerTraceDisplayService::dateTimeLabel(null) === '-', 'Empty timestamp must render as dash');
ai_trace_display_assert(AiLedgerTraceDisplayService::dateTimeLabel('not-a-time') === 'not-a-time', 'Invalid timestamp must be preserved');

$invalidTableRejected = false;
try {
    $service->latestLinksByLedgerRows('unknown_table', [1]);
} catch (InvalidArgumentException) {
    $invalidTableRejected = true;
}
ai_trace_display_assert($invalidTableRejected, 'Unsupported ledger table must be rejected');

echo "AiLedgerTraceDisplayServiceTest passed\n";
