<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/AiParseService.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class FakeAiClient implements AiClientInterface
{
    public function __construct(private readonly string $text)
    {
    }

    public function generate(string $prompt, array $settings, array $responseSchema): array
    {
        assert_true(str_contains($prompt, '使用者輸入：'), 'Prompt does not contain input section');
        assert_true(($responseSchema['type'] ?? '') === 'object', 'Response schema missing');
        assert_true(
            in_array('record_date', $responseSchema['required'] ?? [], true),
            'Response schema does not require complete structure'
        );
        assert_true(
            ($responseSchema['properties']['category']['enum'] ?? []) === [
                '餐飲', '交通', '購物', '3C', '娛樂', '生活', '醫療', '薪資', '加班', '其他',
            ],
            'Response schema category enum mismatch'
        );
        assert_true(str_contains($prompt, 'PS5、Switch、手機'), 'Prompt category rules missing');
        assert_true(str_contains($prompt, '早餐80現金'), 'Prompt legacy shorthand example missing');
        assert_true(str_contains($prompt, '不得把品項名稱中的數字（例如 7-11）當成金額'), 'Prompt item digit safety rule missing');

        return [
            'text' => $this->text,
            'raw_response' => '{"provider":"raw-response"}',
            'duration_ms' => 12,
        ];
    }
}

function create_test_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec('CREATE TABLE payment_methods (
        id INTEGER PRIMARY KEY, name TEXT, settlement_start_day INTEGER,
        settlement_end_day INTEGER, is_active INTEGER, sort_order INTEGER
    )');
    $pdo->exec('CREATE TABLE accounts (
        id INTEGER PRIMARY KEY, name TEXT, is_active INTEGER, sort_order INTEGER
    )');
    $pdo->exec('CREATE TABLE leave_types (
        id INTEGER PRIMARY KEY, name TEXT, is_active INTEGER, sort_order INTEGER
    )');
    $pdo->exec('CREATE TABLE expenses (id INTEGER PRIMARY KEY)');
    $pdo->exec('CREATE TABLE incomes (id INTEGER PRIMARY KEY)');
    $pdo->exec('CREATE TABLE overtime_logs (
        id INTEGER PRIMARY KEY, work_date TEXT, is_deleted INTEGER
    )');
    $pdo->exec('CREATE TABLE leave_logs (
        id INTEGER PRIMARY KEY, leave_date TEXT, is_deleted INTEGER
    )');
    $pdo->exec('CREATE TABLE ai_parse_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        raw_input TEXT, ai_response TEXT, provider TEXT, model_name TEXT,
        parsed_type TEXT, parsed_json TEXT, parse_status TEXT, error_code TEXT,
        error_message TEXT, duration_ms INTEGER, source TEXT, user_name TEXT,
        entry_owner TEXT DEFAULT \'profile_a\'
    )');

    $pdo->exec("INSERT INTO payment_methods VALUES (1, '現金', 1, 31, 1, 10)");
    $pdo->exec("INSERT INTO payment_methods VALUES (2, '展示方式 C', 7, 6, 1, 20)");
    $pdo->exec("INSERT INTO accounts VALUES (1, '現金', 1, 10)");
    $pdo->exec("INSERT INTO leave_types VALUES (1, '特休', 1, 10)");

    return $pdo;
}

function create_service(PDO $pdo, string $responseText): AiParseService
{
    return new AiParseService(
        $pdo,
        new AiClientFactory(['gemini' => new FakeAiClient($responseText)]),
        new AiPromptBuilder(),
        new AiResponseValidator(),
        new AiBusinessValidator($pdo),
        new AiInputDateResolver()
    );
}

$settings = [
    'is_enabled' => 1,
    'provider' => 'gemini',
    'model_name' => 'test-model',
    'temperature' => '0.10',
    'max_tokens' => 1000,
    'save_raw_response' => 0,
    'allow_expense' => 1,
    'allow_income' => 1,
    'allow_overtime' => 1,
    'allow_leave' => 1,
];

$pdo = create_test_pdo();
$service = create_service($pdo, json_encode([
    'type' => 'expense',
    'record_date' => '2026-06-09',
    'item' => '早餐',
    'amount' => 80,
    'payment_method' => '現金',
    'category' => '餐飲',
], JSON_UNESCAPED_UNICODE));
$preview = $service->preview('早餐 80 現金', 'expense', $settings, 'test', 'tester');

assert_true($preview['status'] === 'success', 'Preview status mismatch');
assert_true($preview['type'] === 'expense', 'Preview type mismatch');
assert_true((int) ($preview['ai_parse_log_id'] ?? 0) === 1, 'Preview log id mismatch');
assert_true((string) ($preview['parsed_json'] ?? '') !== '', 'Preview parsed JSON missing');
assert_true($preview['fields']['payment_method_id'] === 1, 'Payment method was not resolved');
assert_true($preview['fields']['accounting_month'] === '2026/06', 'Accounting month mismatch');
assert_true($preview['fields']['entry_owner'] === 'profile_a', 'Default entry owner mismatch');

$ownerService = create_service($pdo, json_encode([
    'type' => 'expense',
    'record_date' => '2026-06-09',
    'item' => '早餐',
    'amount' => 80,
    'payment_method' => null,
    'category' => '餐飲',
], JSON_UNESCAPED_UNICODE));
$ownerPreview = $ownerService->preview('早餐 80', 'expense', $settings, 'ios_shortcut', 'tester', 'profile_b');
assert_true($ownerPreview['fields']['payment_method_id'] === 1, 'Missing payment method should default to cash');
assert_true($ownerPreview['fields']['payment_method'] === '現金', 'Default cash payment method mismatch');
assert_true($ownerPreview['fields']['entry_owner'] === 'profile_b', 'ProfileB entry owner preview mismatch');
$ownerLog = $pdo->query('SELECT entry_owner, parsed_json FROM ai_parse_logs ORDER BY id DESC LIMIT 1')->fetch();
assert_true($ownerLog['entry_owner'] === 'profile_b', 'AI parse log entry owner mismatch');
assert_true(str_contains((string) $ownerLog['parsed_json'], '"entry_owner":"profile_b"'), 'AI parse log parsed JSON owner mismatch');

$wrongAiMonthService = create_service($pdo, json_encode([
    'type' => 'expense',
    'record_date' => '2026-06-29',
    'item' => '早餐',
    'amount' => 80,
    'payment_method' => '現金',
    'accounting_month' => '2026/07',
    'category' => '餐飲',
], JSON_UNESCAPED_UNICODE));
$wrongAiMonthPreview = $wrongAiMonthService->preview('早餐 80 現金', 'expense', $settings, 'test', 'tester');
assert_true(
    $wrongAiMonthPreview['fields']['accounting_month'] === '2026/06',
    'AI accounting_month must be ignored and recalculated from record_date + payment method'
);
assert_true(
    str_contains((string) $wrongAiMonthPreview['parsed_json'], '"accounting_month":"2026\/06"'),
    'Parsed JSON snapshot must store the backend recalculated accounting month'
);

$missingCashPdo = create_test_pdo();
$missingCashPdo->exec("UPDATE payment_methods SET is_active = 0 WHERE name = '現金'");
$missingCashService = create_service($missingCashPdo, json_encode([
    'type' => 'expense',
    'record_date' => '2026-06-09',
    'item' => '早餐',
    'amount' => 80,
    'payment_method' => null,
    'category' => '餐飲',
], JSON_UNESCAPED_UNICODE));
$missingCashRejected = false;
try {
    $missingCashService->preview('早餐 80', 'expense', $settings, 'ios_shortcut', 'tester');
} catch (AiParseException $exception) {
    $missingCashRejected = $exception->errorCode() === 'missing_cash_payment_method';
}
assert_true($missingCashRejected, 'Missing active cash payment method must stop parsing');

$cardService = create_service($pdo, json_encode([
    'type' => 'expense',
    'record_date' => '2026-06-27',
    'item' => '信用卡消費',
    'amount' => 500,
    'payment_method' => '展示方式 C',
    'category' => '生活',
], JSON_UNESCAPED_UNICODE));
$cardPreview = $cardService->preview('信用卡消費 500 展示方式 C', 'expense', $settings, 'test', 'tester');
assert_true($cardPreview['fields']['payment_method_id'] === 2, 'Credit-card payment method was not resolved');
assert_true($cardPreview['fields']['accounting_month'] === '2026/07', 'Credit-card accounting month mismatch');

$adminPreviewBefore = (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
$adminPreview = $service->preview('後台早餐 80 現金', 'expense', $settings, 'admin_ai_input', 'tester');
assert_true($adminPreview['fields']['payment_method_id'] === 1, 'Admin preview payment method mismatch');
assert_true(
    in_array('這是預覽，還沒有新增正式記錄。確認內容無誤後，按「確認記帳」即可儲存。', $adminPreview['warnings'], true),
    'Admin preview write warning mismatch'
);
assert_true(
    (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === $adminPreviewBefore,
    'Admin preview must not write expenses'
);
$adminPreviewLog = $pdo->query('SELECT source FROM ai_parse_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
assert_true($adminPreviewLog === 'admin_ai_input', 'Admin preview log source mismatch');

$datedPreview = $service->preview('6/8 早餐 80 現金', 'expense', $settings, 'test', 'tester');
assert_true($datedPreview['fields']['record_date'] === date('Y') . '-06-08', 'Input date did not override AI date');

$log = $pdo->query('SELECT * FROM ai_parse_logs ORDER BY id DESC LIMIT 1')->fetch();
assert_true($log['parse_status'] === 'success', 'Success log missing');
assert_true($log['ai_response'] === null, 'Raw response must not be saved when disabled');
assert_true($log['parsed_type'] === 'expense', 'Parsed type log mismatch');

foreach (['expenses', 'incomes', 'overtime_logs', 'leave_logs'] as $table) {
    assert_true((int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() === 0, "$table was modified");
}

$rawSettings = $settings;
$rawSettings['save_raw_response'] = 1;
$service->preview('早餐 80 現金', 'expense', $rawSettings, 'test', 'tester');
$rawLog = $pdo->query('SELECT ai_response FROM ai_parse_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
assert_true($rawLog === '{"provider":"raw-response"}', 'Raw response was not saved when enabled');

$invalidService = create_service($pdo, 'not-json');
$invalidRejected = false;
try {
    $invalidService->preview('早餐 80 現金', 'expense', $settings, 'test', 'tester');
} catch (AiParseException $exception) {
    $invalidRejected = $exception->errorCode() === 'invalid_json';
}
assert_true($invalidRejected, 'Invalid JSON must be rejected');
$invalidLog = $pdo->query('SELECT parse_status, error_code FROM ai_parse_logs ORDER BY id DESC LIMIT 1')->fetch();
assert_true($invalidLog['parse_status'] === 'invalid_json', 'Invalid JSON status missing');
assert_true($invalidLog['error_code'] === 'invalid_json', 'Invalid JSON error code missing');

$invalidNumberService = create_service($pdo, json_encode([
    'type' => 'expense',
    'record_date' => '2026-06-09',
    'item' => '測試支出',
    'amount' => '一元',
    'payment_method' => '現金',
    'category' => '其他',
], JSON_UNESCAPED_UNICODE));
$invalidNumberRejected = false;
try {
    $invalidNumberService->preview('測試支出 一元 現金', 'expense', $settings, 'ios_shortcut', 'tester');
} catch (AiParseException $exception) {
    $invalidNumberRejected = $exception->errorCode() === 'invalid_number';
}
assert_true($invalidNumberRejected, 'Invalid number text must be rejected');
$invalidNumberLog = $pdo->query(
    'SELECT ai_response, parsed_type, parsed_json, parse_status, error_code, source
     FROM ai_parse_logs ORDER BY id DESC LIMIT 1'
)->fetch();
assert_true($invalidNumberLog['parse_status'] === 'validation_failed', 'Invalid number status missing');
assert_true($invalidNumberLog['error_code'] === 'invalid_number', 'Invalid number error code missing');
assert_true($invalidNumberLog['ai_response'] === null, 'Raw response must remain disabled');
assert_true($invalidNumberLog['parsed_type'] === 'expense', 'Invalid number parsed type snapshot missing');
assert_true(str_contains((string) $invalidNumberLog['parsed_json'], '"amount":"一元"'), 'Invalid number parsed JSON snapshot missing');
assert_true($invalidNumberLog['source'] === 'ios_shortcut', 'Invalid number source mismatch');

$missingAmountBeforeExpenses = (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
$missingAmountService = create_service($pdo, json_encode([
    'type' => 'expense',
    'record_date' => '2026-06-24',
    'item' => '測試支出 1',
    'amount' => null,
    'payment_method' => '現金',
    'category' => '其他',
], JSON_UNESCAPED_UNICODE));
$missingAmountRejected = false;
try {
    $missingAmountService->preview('測試支出 1 現金', 'expense', $settings, 'ios_shortcut', 'tester');
} catch (AiParseException $exception) {
    $missingAmountRejected = $exception->errorCode() === 'invalid_number'
        && str_contains($exception->getMessage(), 'AI 未解析到金額')
        && str_contains($exception->getMessage(), '早餐 1元 現金');
}
assert_true($missingAmountRejected, 'Missing amount must return an actionable hint');
$missingAmountLog = $pdo->query(
    'SELECT ai_response, parsed_type, parsed_json, parse_status, error_code, error_message, source
     FROM ai_parse_logs ORDER BY id DESC LIMIT 1'
)->fetch();
assert_true($missingAmountLog['parse_status'] === 'validation_failed', 'Missing amount status missing');
assert_true($missingAmountLog['error_code'] === 'invalid_number', 'Missing amount error code missing');
assert_true(str_contains((string) $missingAmountLog['error_message'], 'AI 未解析到金額'), 'Missing amount message missing');
assert_true($missingAmountLog['ai_response'] === null, 'Missing amount raw response must remain disabled');
assert_true($missingAmountLog['parsed_type'] === 'expense', 'Missing amount parsed type snapshot missing');
assert_true(str_contains((string) $missingAmountLog['parsed_json'], '"amount":null'), 'Missing amount parsed JSON snapshot missing');
assert_true($missingAmountLog['source'] === 'ios_shortcut', 'Missing amount source mismatch');
assert_true(
    (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === $missingAmountBeforeExpenses,
    'Missing amount must not write expenses'
);

$warningPdo = create_test_pdo();
$warningPdo->exec("INSERT INTO overtime_logs VALUES (1, '2026-06-09', 0)");
$warningPdo->exec("INSERT INTO leave_logs VALUES (1, '2026-06-09', 0)");

$overtimeWarningService = create_service($warningPdo, json_encode([
    'type' => 'overtime',
    'work_date' => '2026-06-09',
    'overtime_hours' => 3,
], JSON_UNESCAPED_UNICODE));
$overtimeQuick = $overtimeWarningService->preview('2026/6/9 加班3小時', 'overtime', $settings, 'quick_pwa', 'tester');
assert_true(
    in_array('該日期已有加班紀錄；寫入時會更新既有資料。', $overtimeQuick['warnings'], true),
    'Quick Entry overtime warning mismatch'
);

$overtimePreview = $overtimeWarningService->preview('2026/6/9 加班3小時', 'overtime', $settings, 'web', 'tester');
assert_true(
    in_array('該日期已有加班紀錄；本階段只提示，不會更新資料。', $overtimePreview['warnings'], true),
    'Web overtime warning mismatch'
);

$leaveWarningService = create_service($warningPdo, json_encode([
    'type' => 'leave',
    'leave_date' => '2026-06-09',
    'leave_type' => '特休',
    'leave_days' => 1,
    'leave_hours' => 0,
    'note' => '',
], JSON_UNESCAPED_UNICODE));
$leaveQuick = $leaveWarningService->preview('2026/6/9 請特休一天', 'leave', $settings, 'quick_pwa', 'tester');
assert_true(
    in_array('該日期已有請假紀錄；寫入時會更新既有資料。', $leaveQuick['warnings'], true),
    'Quick Entry leave warning mismatch'
);

$leavePreview = $leaveWarningService->preview('2026/6/9 請特休一天', 'leave', $settings, 'web', 'tester');
assert_true(
    in_array('該日期已有請假紀錄；本階段只提示，不會更新資料。', $leavePreview['warnings'], true),
    'Web leave warning mismatch'
);

echo "AiParseServiceTest passed\n";
