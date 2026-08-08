<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/QuickEntryApiService.php';
require_once dirname(__DIR__) . '/public/quick_entry_api.php';

function quick_api_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class QuickEntryApiFixtureParser
{
    /** @param array<string, array<string, mixed>> $responses */
    public function __construct(private readonly PDO $pdo, private readonly array $responses)
    {
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function preview(
        string $inputText,
        string $requestedType,
        array $settings,
        string $source = 'web',
        ?string $userName = null,
        string $entryOwner = 'profile_a'
    ): array {
        quick_api_assert($requestedType === 'auto', 'API should request auto parse.');
        quick_api_assert($source === QuickEntryApiService::SOURCE, 'API parse source mismatch.');
        quick_api_assert(in_array($entryOwner, ['profile_a', 'profile_b'], true), 'API entry owner mismatch.');
        quick_api_assert(isset($this->responses[$inputText]), 'Unexpected fixture input.');

        $aiResponse = json_encode($this->responses[$inputText], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $validated = (new AiResponseValidator())->validate($aiResponse, 'auto');
        $businessResult = (new AiBusinessValidator($this->pdo))->validate(
            $validated['type'],
            $validated['fields'],
            $source
        );
        $fields = $businessResult['fields'];
        $fields['entry_owner'] = $entryOwner;
        $parsedJson = json_encode(
            ['type' => $validated['type'], 'fields' => $fields, 'warnings' => $businessResult['warnings']],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO ai_parse_logs (raw_input, parsed_type, parsed_json, parse_status, source, user_name, entry_owner)
             VALUES (:raw_input, :parsed_type, :parsed_json, :parse_status, :source, :user_name, :entry_owner)'
        );
        $statement->execute([
            'raw_input' => $inputText,
            'parsed_type' => $validated['type'],
            'parsed_json' => $parsedJson,
            'parse_status' => 'success',
            'source' => $source,
            'user_name' => $userName,
            'entry_owner' => $entryOwner,
        ]);

        return [
            'status' => 'success',
            'type' => $validated['type'],
            'fields' => $fields,
            'ai_parse_log_id' => (int) $this->pdo->lastInsertId(),
        ];
    }
}

final class QuickEntryApiFailingParser
{
    /** @param array<string, array<string, mixed>> $responses */
    public function __construct(private readonly PDO $pdo, private readonly array $responses)
    {
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function preview(
        string $inputText,
        string $requestedType,
        array $settings,
        string $source = 'web',
        ?string $userName = null,
        string $entryOwner = 'profile_a'
    ): array {
        quick_api_assert($requestedType === 'auto', 'Failing parser should receive auto parse.');
        quick_api_assert($source === QuickEntryApiService::SOURCE, 'Failing parser source mismatch.');
        quick_api_assert(in_array($entryOwner, ['profile_a', 'profile_b'], true), 'Failing parser entry owner mismatch.');
        quick_api_assert(isset($this->responses[$inputText]), 'Unexpected failing fixture input.');

        $aiResponse = json_encode($this->responses[$inputText], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        try {
            (new AiResponseValidator())->validate($aiResponse, 'auto');
        } catch (AiParseException $exception) {
            $statement = $this->pdo->prepare(
                'INSERT INTO ai_parse_logs (
                    raw_input, parsed_type, parsed_json, parse_status, error_code, error_message, source, user_name, entry_owner
                 ) VALUES (
                    :raw_input, :parsed_type, :parsed_json, :parse_status, :error_code, :error_message, :source, :user_name, :entry_owner
                 )'
            );
            $statement->execute([
                'raw_input' => $inputText,
                'parsed_type' => (string) ($this->responses[$inputText]['type'] ?? ''),
                'parsed_json' => $aiResponse,
                'parse_status' => $exception->parseStatus(),
                'error_code' => $exception->errorCode(),
                'error_message' => $exception->getMessage(),
                'source' => $source,
                'user_name' => $userName,
                'entry_owner' => $entryOwner,
            ]);
            throw $exception;
        }

        throw new RuntimeException('Failing parser fixture unexpectedly passed validation.');
    }
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
    parse_status TEXT, error_code TEXT, error_message TEXT, source TEXT, user_name TEXT,
    entry_owner TEXT DEFAULT \'profile_a\'
)');
$pdo->exec('CREATE TABLE ai_ledger_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT, ai_parse_log_id INTEGER, ledger_table TEXT, ledger_id INTEGER,
    action TEXT, source TEXT, raw_input_snapshot TEXT, parsed_type_snapshot TEXT, parsed_json_snapshot TEXT,
    user_name TEXT
)');
$pdo->exec("INSERT INTO payment_methods VALUES (1, '現金', 1, 31, 1)");
$pdo->exec("INSERT INTO payment_methods VALUES (2, '展示方式 C', 7, 6, 1)");

$service = new QuickEntryApiService($pdo, new QuickEntryApiFixtureParser($pdo, [
    '測試支出 1 現金' => [
        'type' => 'expense',
        'record_date' => '2026-06-22',
        'item' => '測試支出數字',
        'amount' => 1,
        'payment_method' => '現金',
        'category' => '餐飲',
    ],
    '測試支出 字串1 現金' => [
        'type' => 'expense',
        'record_date' => '2026-06-22',
        'item' => '測試支出字串',
        'amount' => '1',
        'payment_method' => '現金',
        'category' => '餐飲',
    ],
    '測試支出 1元 現金' => [
        'type' => 'expense',
        'record_date' => '2026-06-22',
        'item' => '測試支出元',
        'amount' => '1元',
        'payment_method' => '現金',
        'category' => '餐飲',
    ],
    '早餐1現金' => [
        'type' => 'expense',
        'record_date' => '2026-06-24',
        'item' => '早餐',
        'amount' => 1,
        'payment_method' => '現金',
        'category' => '餐飲',
    ],
    '早餐80現金' => [
        'type' => 'expense',
        'record_date' => '2026-06-24',
        'item' => '早餐',
        'amount' => 80,
        'payment_method' => '現金',
        'category' => '餐飲',
    ],
    '早餐80' => [
        'type' => 'expense',
        'record_date' => '2026-06-24',
        'item' => '早餐',
        'amount' => 80,
        'payment_method' => null,
        'category' => '餐飲',
    ],
    '展示對象 B早餐80' => [
        'type' => 'expense',
        'record_date' => '2026-06-24',
        'item' => '早餐',
        'amount' => 80,
        'payment_method' => null,
        'category' => '餐飲',
    ],
    '6月29早餐80未指定付款' => [
        'type' => 'expense',
        'record_date' => '2026-06-29',
        'item' => '早餐',
        'amount' => 80,
        'payment_method' => null,
        'category' => '餐飲',
    ],
    '6月29早餐80現金' => [
        'type' => 'expense',
        'record_date' => '2026-06-29',
        'item' => '早餐',
        'amount' => 80,
        'payment_method' => '現金',
        'category' => '餐飲',
    ],
    '午餐100現金' => [
        'type' => 'expense',
        'record_date' => '2026-06-22',
        'item' => '午餐',
        'amount' => 100,
        'payment_method' => '現金',
        'category' => '餐飲',
    ],
    '飲料35現金' => [
        'type' => 'expense',
        'record_date' => '2026-07-01',
        'item' => '飲料',
        'amount' => 35,
        'payment_method' => '現金',
        'category' => '餐飲',
    ],
    '信用卡消費 500 展示方式 C' => [
        'type' => 'expense',
        'record_date' => '2026-06-27',
        'item' => '信用卡消費',
        'amount' => 500,
        'payment_method' => '展示方式 C',
        'category' => '生活',
    ],
    '今天加班 3 小時' => [
        'type' => 'overtime',
        'work_date' => '2026-06-24',
        'overtime_hours' => 3,
    ],
    '加班3小時' => [
        'type' => 'overtime',
        'work_date' => '2026-06-24',
        'overtime_hours' => 3,
    ],
    '加班 3 小時' => [
        'type' => 'overtime',
        'work_date' => '2026-06-24',
        'overtime_hours' => 3,
    ],
    '加班2小時' => [
        'type' => 'overtime',
        'work_date' => '2026-06-24',
        'overtime_hours' => 2,
    ],
    '加班 2 小時' => [
        'type' => 'overtime',
        'work_date' => '2026-06-24',
        'overtime_hours' => 2,
    ],
]));

$expenseExpectations = [
    '測試支出 1 現金' => ['title' => '測試支出數字', 'amount' => 1.0, 'accounting_month' => '2026/06'],
    '測試支出 字串1 現金' => ['title' => '測試支出字串', 'amount' => 1.0, 'accounting_month' => '2026/06'],
    '測試支出 1元 現金' => ['title' => '測試支出元', 'amount' => 1.0, 'accounting_month' => '2026/06'],
    '早餐1現金' => ['title' => '早餐', 'amount' => 1.0, 'accounting_month' => '2026/06'],
    '早餐80現金' => ['title' => '早餐', 'amount' => 80.0, 'accounting_month' => '2026/06'],
    '早餐80' => ['title' => '早餐', 'amount' => 80.0, 'accounting_month' => '2026/06'],
    '展示對象 B早餐80' => ['title' => '早餐', 'amount' => 80.0, 'accounting_month' => '2026/06'],
    '6月29早餐80未指定付款' => ['title' => '早餐', 'amount' => 80.0, 'accounting_month' => '2026/06'],
    '6月29早餐80現金' => ['title' => '早餐', 'amount' => 80.0, 'accounting_month' => '2026/06'],
    '午餐100現金' => ['title' => '午餐', 'amount' => 100.0, 'accounting_month' => '2026/06'],
    '飲料35現金' => ['title' => '飲料', 'amount' => 35.0, 'accounting_month' => '2026/07'],
    '信用卡消費 500 展示方式 C' => ['title' => '信用卡消費', 'amount' => 500.0, 'accounting_month' => '2026/07'],
];
foreach ($expenseExpectations as $inputText => $expected) {
    $payload = [
        'text' => $inputText,
        'client_request_id' => 'shortcut-test-' . substr(hash('sha256', $inputText), 0, 8),
    ];
    if ($inputText === '展示對象 B早餐80') {
        $payload['entry_owner'] = '展示對象 B';
    }
    $response = $service->handle($payload, [], 'tester');

    quick_api_assert($response['ok'] === true, 'Response ok mismatch.');
    quick_api_assert($response['message'] === '寫入成功。', 'Response message mismatch.');
    quick_api_assert(isset($response['client_request_id']), 'Client request id mismatch.');
    quick_api_assert($response['summary']['type'] === 'expense', 'Summary type mismatch.');
    quick_api_assert($response['summary']['title'] === $expected['title'], 'Summary title mismatch.');
    quick_api_assert((float) $response['summary']['amount'] === $expected['amount'], 'Summary amount mismatch.');
    $expectedPaymentMethod = $inputText === '信用卡消費 500 展示方式 C' ? '展示方式 C' : '現金';
    quick_api_assert($response['summary']['payment_method'] === $expectedPaymentMethod, 'Summary payment method mismatch.');
    quick_api_assert($response['summary']['accounting_month'] === $expected['accounting_month'], 'Summary accounting month mismatch.');
    $expectedOwnerLabel = $inputText === '展示對象 B早餐80' ? '展示對象 B' : '展示對象 A';
    quick_api_assert($response['summary']['entry_owner'] === $expectedOwnerLabel, 'Summary entry owner mismatch.');
    quick_api_assert(!isset($response['summary']['ledger_id']), 'Summary should not expose ledger id.');
    quick_api_assert(!isset($response['summary']['ai_ledger_link_id']), 'Summary should not expose trace link id.');
    quick_api_assert($response['error'] === null, 'Response error should be null.');
}

$expenses = $pdo->query('SELECT * FROM expenses ORDER BY id')->fetchAll();
quick_api_assert(count($expenses) === count($expenseExpectations), 'Expense row count mismatch.');
foreach ($expenses as $expense) {
    quick_api_assert($expense['source'] === QuickEntryApiService::SOURCE, 'Expense source mismatch.');
    quick_api_assert(
        (float) $expense['amount'] === $expenseExpectations[$expense['raw_input']]['amount'],
        'Expense amount mismatch.'
    );
    quick_api_assert(
        $expense['accounting_month'] === $expenseExpectations[$expense['raw_input']]['accounting_month'],
        'Expense accounting month mismatch.'
    );
    $expectedPaymentMethod = $expense['raw_input'] === '信用卡消費 500 展示方式 C' ? '展示方式 C' : '現金';
    quick_api_assert($expense['payment_method'] === $expectedPaymentMethod, 'Expense payment method mismatch.');
    $expectedOwner = $expense['raw_input'] === '展示對象 B早餐80' ? 'profile_b' : 'profile_a';
    quick_api_assert($expense['entry_owner'] === $expectedOwner, 'Expense entry owner mismatch.');
    quick_api_assert(isset($expense['item']) && !isset($expense['title']), 'Expense should use item column, not title.');
}

$invalidOwnerRejected = false;
try {
    $service->handle(['text' => '早餐80', 'entry_owner' => '其他人'], [], 'tester');
} catch (QuickEntryApiRequestException $exception) {
    $invalidOwnerRejected = $exception->errorCode() === 'invalid_entry_owner';
}
quick_api_assert($invalidOwnerRejected, 'Invalid entry owner must be rejected.');

$profile_bIncomeService = new QuickEntryApiService($pdo, new QuickEntryApiFixtureParser($pdo, [
    '收入1000' => [
        'type' => 'income',
        'record_date' => '2026-06-24',
        'source_name' => '收入',
        'amount' => 1000,
        'account_name' => '',
        'category' => '薪資',
    ],
    '加班2小時' => [
        'type' => 'overtime',
        'work_date' => '2026-06-24',
        'overtime_hours' => 2,
    ],
    '請假半天' => [
        'type' => 'leave',
        'leave_date' => '2026-06-24',
        'leave_type' => '特休',
        'leave_days' => 0.5,
        'leave_hours' => 0,
        'note' => '',
    ],
]));
foreach (['收入1000' => 'incomes', '加班2小時' => 'overtime_logs', '請假半天' => 'leave_logs'] as $text => $table) {
    $beforeCount = (int) $pdo->query(sprintf('SELECT COUNT(*) FROM %s', $table))->fetchColumn();
    $rejected = false;
    try {
        $profile_bIncomeService->handle(['text' => $text, 'entry_owner' => '展示對象 B'], [], 'tester');
    } catch (QuickEntryValidationException $exception) {
        $rejected = isset($exception->fieldErrors()['entry_owner']);
    }
    quick_api_assert($rejected, $text . ' profile_b non-expense must be rejected.');
    quick_api_assert(
        (int) $pdo->query(sprintf('SELECT COUNT(*) FROM %s', $table))->fetchColumn() === $beforeCount,
        $text . ' profile_b non-expense changed rows.'
    );
}

$firstOvertimeResponse = $service->handle([
    'text' => '今天加班 3 小時',
    'client_request_id' => 'leave_work_overtime_20260624180000',
], [], 'tester');
$secondOvertimeResponse = $service->handle([
    'text' => '今天加班 3 小時',
    'client_request_id' => 'leave_work_overtime_20260624180100',
], [], 'tester');
quick_api_assert($firstOvertimeResponse['ok'] === true, 'Overtime create response ok mismatch.');
quick_api_assert($secondOvertimeResponse['ok'] === true, 'Overtime update response ok mismatch.');
quick_api_assert($firstOvertimeResponse['summary']['type'] === 'overtime', 'Overtime create summary type mismatch.');
quick_api_assert($secondOvertimeResponse['summary']['type'] === 'overtime', 'Overtime update summary type mismatch.');
quick_api_assert($secondOvertimeResponse['summary']['action'] === 'updated', 'Overtime same-day update action mismatch.');
quick_api_assert((float) $secondOvertimeResponse['summary']['overtime_hours'] === 3.0, 'Overtime summary hours mismatch.');
quick_api_assert((float) $secondOvertimeResponse['summary']['amount'] === 3.0, 'Overtime summary amount mismatch.');
quick_api_assert($secondOvertimeResponse['summary']['unit'] === '小時', 'Overtime summary unit mismatch.');
quick_api_assert(!isset($secondOvertimeResponse['summary']['ledger_id']), 'Overtime summary should not expose ledger id.');
quick_api_assert(!isset($secondOvertimeResponse['summary']['ai_ledger_link_id']), 'Overtime summary should not expose trace link id.');

$legacyOvertimeCases = [
    '加班3小時' => 3.0,
    '加班 3 小時' => 3.0,
    '加班2小時' => 2.0,
    '加班 2 小時' => 2.0,
];
foreach ($legacyOvertimeCases as $legacyText => $expectedHours) {
    $legacyOvertimeResponse = $service->handle([
        'text' => $legacyText,
        'client_request_id' => 'legacy_gas_overtime_' . substr(hash('sha256', $legacyText), 0, 8),
    ], [], 'tester');
    quick_api_assert($legacyOvertimeResponse['ok'] === true, "{$legacyText} response ok mismatch.");
    quick_api_assert($legacyOvertimeResponse['summary']['type'] === 'overtime', "{$legacyText} summary type mismatch.");
    quick_api_assert($legacyOvertimeResponse['summary']['action'] === 'updated', "{$legacyText} same-day update action mismatch.");
    quick_api_assert((float) $legacyOvertimeResponse['summary']['overtime_hours'] === $expectedHours, "{$legacyText} summary hours mismatch.");
    quick_api_assert((float) $legacyOvertimeResponse['summary']['amount'] === $expectedHours, "{$legacyText} summary amount mismatch.");
    quick_api_assert($legacyOvertimeResponse['summary']['unit'] === '小時', "{$legacyText} summary unit mismatch.");
    quick_api_assert(!isset($legacyOvertimeResponse['summary']['ledger_id']), "{$legacyText} summary should not expose ledger id.");
    quick_api_assert(!isset($legacyOvertimeResponse['summary']['ai_ledger_link_id']), "{$legacyText} summary should not expose trace link id.");
    $overtimeJson = json_encode(
        $legacyOvertimeResponse,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    quick_api_assert(!str_contains($overtimeJson, 'ledger_id'), "{$legacyText} response JSON should not expose ledger id.");
    quick_api_assert(!str_contains($overtimeJson, 'ai_ledger_link_id'), "{$legacyText} response JSON should not expose trace link id.");
}
quick_api_assert((int) $pdo->query('SELECT COUNT(*) FROM overtime_logs')->fetchColumn() === 1, 'Overtime same-day row duplicated.');
$overtime = $pdo->query('SELECT * FROM overtime_logs LIMIT 1')->fetch();
quick_api_assert($overtime['source'] === QuickEntryApiService::SOURCE, 'Overtime source mismatch.');
quick_api_assert((float) $overtime['overtime_hours'] === 2.0, 'Legacy overtime final row hours mismatch.');
quick_api_assert($overtime['raw_input'] === '加班 2 小時', 'Legacy overtime final raw input mismatch.');

$logs = $pdo->query('SELECT * FROM ai_parse_logs ORDER BY id')->fetchAll();
quick_api_assert(count($logs) === 21, 'AI parse log count mismatch.');
foreach ($logs as $log) {
    quick_api_assert($log['source'] === QuickEntryApiService::SOURCE, 'AI parse log source mismatch.');
    quick_api_assert((string) $log['parsed_json'] !== '', 'AI parse log parsed JSON missing.');
    if (isset($expenseExpectations[$log['raw_input']])) {
        $parsedJson = json_decode((string) $log['parsed_json'], true, 512, JSON_THROW_ON_ERROR);
        $fields = is_array($parsedJson['fields'] ?? null) ? $parsedJson['fields'] : [];
        quick_api_assert(
            ($fields['accounting_month'] ?? '') === $expenseExpectations[$log['raw_input']]['accounting_month'],
            'AI parse log accounting month mismatch.'
        );
        $expectedOwner = $log['raw_input'] === '展示對象 B早餐80' ? 'profile_b' : 'profile_a';
        quick_api_assert(($fields['entry_owner'] ?? '') === $expectedOwner, 'AI parse log entry owner mismatch.');
    }
}

$links = $pdo->query('SELECT * FROM ai_ledger_links ORDER BY id')->fetchAll();
quick_api_assert(count($links) === 18, 'AI ledger link count mismatch.');
foreach (array_slice($links, 0, count($expenseExpectations)) as $index => $link) {
    quick_api_assert($link['source'] === QuickEntryApiService::SOURCE, 'AI ledger link source mismatch.');
    quick_api_assert($link['ledger_table'] === 'expenses', 'AI ledger link table mismatch.');
    quick_api_assert((int) $link['ledger_id'] === (int) $expenses[$index]['id'], 'AI ledger link id mismatch.');
}
$overtimeLinks = array_values(array_filter(
    $links,
    static fn (array $link): bool => $link['ledger_table'] === 'overtime_logs'
));
quick_api_assert(count($overtimeLinks) === 6, 'Overtime trace link count mismatch.');
quick_api_assert($overtimeLinks[0]['action'] === 'created', 'Overtime create trace action mismatch.');
quick_api_assert($overtimeLinks[1]['action'] === 'updated', 'Overtime update trace action mismatch.');
foreach (array_slice($overtimeLinks, 2) as $legacyIndex => $legacyLink) {
    quick_api_assert($legacyLink['action'] === 'updated', 'Legacy overtime update trace action mismatch ' . (string) $legacyIndex);
}
quick_api_assert((int) $overtimeLinks[0]['ledger_id'] === (int) $overtime['id'], 'Overtime create trace ledger id mismatch.');
foreach ($overtimeLinks as $link) {
    quick_api_assert((int) $link['ledger_id'] === (int) $overtime['id'], 'Overtime trace ledger id mismatch.');
}

$missingTextRejected = false;
try {
    $service->handle(['client_request_id' => 'shortcut-test-2'], [], 'tester');
} catch (QuickEntryApiRequestException $exception) {
    $missingTextRejected = $exception->errorCode() === 'missing_text' && $exception->statusCode() === 400;
}
quick_api_assert($missingTextRejected, 'Missing text should be rejected.');

$beforeMissingAmount = [
    'expenses' => (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn(),
    'incomes' => (int) $pdo->query('SELECT COUNT(*) FROM incomes')->fetchColumn(),
    'overtime_logs' => (int) $pdo->query('SELECT COUNT(*) FROM overtime_logs')->fetchColumn(),
    'leave_logs' => (int) $pdo->query('SELECT COUNT(*) FROM leave_logs')->fetchColumn(),
    'ai_ledger_links' => (int) $pdo->query('SELECT COUNT(*) FROM ai_ledger_links')->fetchColumn(),
];
$missingAmountService = new QuickEntryApiService($pdo, new QuickEntryApiFailingParser($pdo, [
    '測試支出 1 現金 amount-null' => [
        'type' => 'expense',
        'record_date' => '2026-06-24',
        'item' => '測試支出 1',
        'amount' => null,
        'payment_method' => '現金',
        'category' => '其他',
    ],
]));
$missingAmountPayload = null;
try {
    $missingAmountService->handle(['text' => '測試支出 1 現金 amount-null'], [], 'tester');
} catch (AiParseException $exception) {
    $missingAmountPayload = quick_entry_api_error_payload($exception->getMessage(), $exception->errorCode());
}
quick_api_assert(is_array($missingAmountPayload), 'Missing amount must be converted to a JSON error payload.');
quick_api_assert(($missingAmountPayload['ok'] ?? true) === false, 'Missing amount payload ok mismatch.');
quick_api_assert(
    array_key_exists('summary', $missingAmountPayload) && $missingAmountPayload['summary'] === null,
    'Missing amount summary must be null.'
);
quick_api_assert(($missingAmountPayload['error']['code'] ?? '') === 'invalid_number', 'Missing amount code mismatch.');
quick_api_assert(
    str_contains((string) ($missingAmountPayload['error']['message'] ?? ''), 'AI 未解析到金額')
        && str_contains((string) ($missingAmountPayload['error']['message'] ?? ''), '早餐 1元 現金'),
    'Missing amount message must include an actionable hint.'
);
$missingAmountJson = json_encode(
    $missingAmountPayload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
quick_api_assert(!str_contains($missingAmountJson, '<'), 'Missing amount JSON payload must not contain HTML.');
quick_api_assert(!isset($missingAmountPayload['ledger_id'], $missingAmountPayload['trace_link_id']), 'Missing amount error must not expose internal ids.');
foreach ($beforeMissingAmount as $table => $count) {
    quick_api_assert(
        (int) $pdo->query(sprintf('SELECT COUNT(*) FROM %s', $table))->fetchColumn() === $count,
        sprintf('Missing amount changed %s rows.', $table)
    );
}
$missingAmountLog = $pdo->query(
    "SELECT parsed_type, parsed_json, parse_status, error_code, error_message, source
     FROM ai_parse_logs WHERE raw_input = '測試支出 1 現金 amount-null' ORDER BY id DESC LIMIT 1"
)->fetch();
quick_api_assert($missingAmountLog['parse_status'] === 'validation_failed', 'Missing amount log status mismatch.');
quick_api_assert($missingAmountLog['error_code'] === 'invalid_number', 'Missing amount log code mismatch.');
quick_api_assert($missingAmountLog['parsed_type'] === 'expense', 'Missing amount parsed type snapshot mismatch.');
quick_api_assert(str_contains((string) $missingAmountLog['parsed_json'], '"amount":null'), 'Missing amount parsed JSON snapshot mismatch.');
quick_api_assert(str_contains((string) $missingAmountLog['error_message'], 'AI 未解析到金額'), 'Missing amount log message mismatch.');
quick_api_assert($missingAmountLog['source'] === QuickEntryApiService::SOURCE, 'Missing amount log source mismatch.');

$errorPayload = quick_entry_api_error_payload('AI 未解析到金額，請改成「早餐 1元 現金」這類格式。', 'invalid_number');
$errorJson = json_encode($errorPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
quick_api_assert(($errorPayload['ok'] ?? true) === false, 'Validation failed payload ok mismatch.');
quick_api_assert(array_key_exists('summary', $errorPayload) && $errorPayload['summary'] === null, 'Validation failed summary must be null.');
quick_api_assert(($errorPayload['error']['code'] ?? '') === 'invalid_number', 'Validation failed code mismatch.');
quick_api_assert(str_contains($errorPayload['error']['message'] ?? '', '早餐 1元 現金'), 'Validation failed message must be actionable.');
quick_api_assert(!str_contains($errorJson, '<'), 'Validation failed JSON payload must not contain HTML.');
quick_api_assert(!isset($errorPayload['ledger_id'], $errorPayload['trace_link_id']), 'Error response must not expose internal ids.');

echo "QuickEntryApiServiceTest passed\n";
