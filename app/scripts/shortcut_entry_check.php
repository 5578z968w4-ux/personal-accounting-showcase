<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/env.php';
require_once dirname(__DIR__) . '/src/QuickEntryApiService.php';

final class ShortcutEntryFixtureParser
{
    /** @param array<string, array<string, mixed>> $fixtures */
    public function __construct(private readonly PDO $pdo, private readonly array $fixtures)
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
        if ($requestedType !== 'auto' || !isset($this->fixtures[$inputText])) {
            throw new RuntimeException('Fixture parser received an unexpected request.');
        }

        $fixture = $this->fixtures[$inputText];
        $aiResponse = json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        try {
            $validated = (new AiResponseValidator())->validate($aiResponse, 'auto');
        } catch (AiParseException $exception) {
            $statement = $this->pdo->prepare(
                'INSERT INTO ai_parse_logs (
                    raw_input, ai_response, provider, model_name, parsed_type, parsed_json,
                    parse_status, error_code, error_message, duration_ms, source, user_name
                 ) VALUES (
                    :raw_input, NULL, :provider, :model_name, :parsed_type, :parsed_json,
                    :parse_status, :error_code, :error_message, :duration_ms, :source, :user_name
                 )'
            );
            $statement->execute([
                'raw_input' => $inputText,
                'provider' => 'shortcut_entry_check',
                'model_name' => 'fixture',
                'parsed_type' => (string) ($fixture['type'] ?? ''),
                'parsed_json' => $aiResponse,
                'parse_status' => $exception->parseStatus(),
                'error_code' => $exception->errorCode(),
                'error_message' => $exception->getMessage(),
                'duration_ms' => 0,
                'source' => $source,
                'user_name' => $userName,
            ]);
            throw $exception;
        }
        $businessResult = (new AiBusinessValidator($this->pdo))->validate(
            $validated['type'],
            $validated['fields'],
            $source
        );
        $parsedJson = json_encode(
            ['type' => $validated['type'], 'fields' => $businessResult['fields'], 'warnings' => $businessResult['warnings']],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO ai_parse_logs (
                raw_input, ai_response, provider, model_name, parsed_type, parsed_json,
                parse_status, error_code, error_message, duration_ms, source, user_name
             ) VALUES (
                :raw_input, NULL, :provider, :model_name, :parsed_type, :parsed_json,
                :parse_status, NULL, NULL, :duration_ms, :source, :user_name
             )'
        );
        $statement->execute([
            'raw_input' => $inputText,
            'provider' => 'shortcut_entry_check',
            'model_name' => 'fixture',
            'parsed_type' => $validated['type'],
            'parsed_json' => $parsedJson,
            'parse_status' => 'success',
            'duration_ms' => 0,
            'source' => $source,
            'user_name' => $userName,
        ]);

        return [
            'status' => 'success',
            'type' => $validated['type'],
            'fields' => $businessResult['fields'],
            'raw_input' => $inputText,
            'ai_parse_log_id' => (int) $this->pdo->lastInsertId(),
            'parsed_json' => $parsedJson,
        ];
    }
}

final class ShortcutEntryCheck
{
    private int $passed = 0;
    private int $failed = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(): int
    {
        echo "iOS Shortcut Quick Entry API Check\n";
        echo "==================================\n";
        $this->assertTestDbGate();

        $today = date('Y-m-d');
        $workDate = $this->unusedDate('overtime_logs', 'work_date', 14);
        $leaveDate = $this->unusedDate('leave_logs', 'leave_date', 15);
        $paymentMethod = $this->activePaymentMethod();
        $cashMethod = $this->activePaymentMethodByName('現金');
        $specifiedMethod = $this->activeNonCashPaymentMethod();
        $leaveType = $this->activeLeaveType();
        $expenseText = '測試支出 1 ' . $paymentMethod['name'];
        $missingAmountText = '測試支出 1 ' . $paymentMethod['name'] . ' amount-null';
        $incomeText = '捷徑收入500';
        $overtimeText = '捷徑加班2小時';
        $leaveText = '捷徑' . $leaveType . '半天';
        $ownerDefaultText = '捷徑早餐80未帶對象';
        $ownerProfileAText = '捷徑早餐80展示對象 A';
        $ownerProfileBText = '捷徑早餐80展示對象 B';
        $cashDefaultJune29Text = '捷徑0629早餐80未指定付款';
        $cashExplicitJune29Text = '捷徑0629早餐80現金';
        $profile_bSpecifiedPaymentText = '捷徑晚餐200' . $specifiedMethod['name'];
        $invalidOwnerText = '捷徑早餐80非法對象';
        $texts = [
            $expenseText,
            $missingAmountText,
            $incomeText,
            $overtimeText,
            $leaveText,
            $ownerDefaultText,
            $ownerProfileAText,
            $ownerProfileBText,
            $cashDefaultJune29Text,
            $cashExplicitJune29Text,
            $profile_bSpecifiedPaymentText,
            $invalidOwnerText,
        ];

        $expenseMaxId = $this->maxId('expenses');
        $incomeMaxId = $this->maxId('incomes');
        $overtimeMaxId = $this->maxId('overtime_logs');
        $leaveMaxId = $this->maxId('leave_logs');
        $logMaxId = $this->maxId('ai_parse_logs');
        $linkMaxId = $this->maxId('ai_ledger_links');

        $service = new QuickEntryApiService(
            $this->pdo,
            new ShortcutEntryFixtureParser($this->pdo, [
                $expenseText => [
                    'type' => 'expense',
                    'record_date' => $today,
                    'item' => '測試支出',
                    'amount' => '1元',
                    'payment_method' => $paymentMethod['name'],
                    'category' => '餐飲',
                ],
                $missingAmountText => [
                    'type' => 'expense',
                    'record_date' => $today,
                    'item' => '測試支出 1',
                    'amount' => null,
                    'payment_method' => $paymentMethod['name'],
                    'category' => '其他',
                ],
                $incomeText => [
                    'type' => 'income',
                    'record_date' => $today,
                    'source_name' => '捷徑收入',
                    'amount' => '500',
                    'account_name' => '',
                    'category' => '薪資',
                ],
                $overtimeText => [
                    'type' => 'overtime',
                    'work_date' => $workDate,
                    'overtime_hours' => '2.0',
                ],
                $leaveText => [
                    'type' => 'leave',
                    'leave_date' => $leaveDate,
                    'leave_type' => $leaveType,
                    'leave_days' => '0.5',
                    'leave_hours' => 0,
                    'note' => '',
                ],
                $ownerDefaultText => [
                    'type' => 'expense',
                    'record_date' => $today,
                    'item' => '早餐',
                    'amount' => '80',
                    'category' => '餐飲',
                ],
                $ownerProfileAText => [
                    'type' => 'expense',
                    'record_date' => $today,
                    'item' => '早餐',
                    'amount' => '80',
                    'category' => '餐飲',
                ],
                $ownerProfileBText => [
                    'type' => 'expense',
                    'record_date' => $today,
                    'item' => '早餐',
                    'amount' => '80',
                    'category' => '餐飲',
                ],
                $cashDefaultJune29Text => [
                    'type' => 'expense',
                    'record_date' => '2026-06-29',
                    'item' => '早餐',
                    'amount' => '80',
                    'category' => '餐飲',
                ],
                $cashExplicitJune29Text => [
                    'type' => 'expense',
                    'record_date' => '2026-06-29',
                    'item' => '早餐',
                    'amount' => '80',
                    'payment_method' => '現金',
                    'category' => '餐飲',
                ],
                $profile_bSpecifiedPaymentText => [
                    'type' => 'expense',
                    'record_date' => $today,
                    'item' => '晚餐',
                    'amount' => '200',
                    'payment_method' => $specifiedMethod['name'],
                    'category' => '餐飲',
                ],
            ])
        );

        try {
            $responses = [
                'expense' => $service->handle(['text' => $expenseText, 'client_request_id' => 'check-expense'], [], 'shortcut-check'),
                'income' => $service->handle(['text' => $incomeText, 'client_request_id' => 'check-income'], [], 'shortcut-check'),
                'overtime' => $service->handle(['text' => $overtimeText, 'client_request_id' => 'check-overtime'], [], 'shortcut-check'),
                'leave' => $service->handle(['text' => $leaveText, 'client_request_id' => 'check-leave'], [], 'shortcut-check'),
            ];

            foreach ($responses as $type => $response) {
                $this->assert(($response['ok'] ?? false) === true, "{$type} API response ok");
                $this->assert(($response['error'] ?? null) === null, "{$type} API response error is null");
                $this->assert(($response['summary']['type'] ?? '') === $type, "{$type} response summary type");
                $this->assert(!isset($response['summary']['ledger_id']), "{$type} response hides ledger id");
                $this->assert(isset($response['client_request_id']), "{$type} echoes client_request_id");
            }

            $expense = $this->rowByRawInput('expenses', $expenseText, $expenseMaxId);
            $this->assert(
                is_array($expense)
                    && (string) $expense['source'] === QuickEntryApiService::SOURCE
                    && (float) $expense['amount'] === 1.0
                    && (string) $expense['accounting_month'] !== '',
                'API 支出寫入成功且 source / item / summary 欄位正確'
            );

            $missingAmountRejected = false;
            $missingAmountPayload = null;
            try {
                $service->handle(
                    ['text' => $missingAmountText, 'client_request_id' => 'check-missing-amount'],
                    [],
                    'shortcut-check'
                );
            } catch (AiParseException $exception) {
                $missingAmountRejected = $exception->errorCode() === 'invalid_number'
                    && str_contains($exception->getMessage(), 'AI 未解析到金額')
                    && str_contains($exception->getMessage(), '早餐 1元 現金');
                $missingAmountPayload = [
                    'ok' => false,
                    'message' => $exception->getMessage(),
                    'summary' => null,
                    'error' => [
                        'code' => $exception->errorCode(),
                        'message' => $exception->getMessage(),
                    ],
                ];
            }
            $this->assert($missingAmountRejected, 'amount=null 回傳可操作的金額缺漏錯誤');
            $this->assert(
                is_array($missingAmountPayload)
                    && ($missingAmountPayload['ok'] ?? true) === false
                    && ($missingAmountPayload['summary'] ?? null) === null
                    && ($missingAmountPayload['error']['code'] ?? '') === 'invalid_number'
                    && !str_contains(json_encode($missingAmountPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), '<'),
                'amount=null 可序列化為 JSON API error 且不含 HTML'
            );
            $this->assert($this->rowByRawInput('expenses', $missingAmountText, $expenseMaxId) === false, 'amount=null 不寫入支出');
            $missingAmountLog = $this->latestLogByRawInput($missingAmountText, $logMaxId);
            $this->assert(
                is_array($missingAmountLog)
                    && (string) $missingAmountLog['parse_status'] === 'validation_failed'
                    && (string) $missingAmountLog['error_code'] === 'invalid_number'
                    && (string) $missingAmountLog['parsed_type'] === 'expense'
                    && str_contains((string) $missingAmountLog['parsed_json'], '"amount":null'),
                'amount=null validation_failed 保留 parsed_json / parsed_type snapshot'
            );

            $income = $this->rowByRawInput('incomes', $incomeText, $incomeMaxId);
            $this->assert(
                is_array($income)
                    && (string) $income['source'] === QuickEntryApiService::SOURCE
                    && (float) $income['amount'] === 500.0
                    && (string) $income['accounting_month'] === date('Y/m'),
                'API 收入寫入成功且月份正確'
            );
            $overtime = $this->rowByRawInput('overtime_logs', $overtimeText, $overtimeMaxId);
            $this->assert(
                is_array($overtime)
                    && (string) $overtime['source'] === QuickEntryApiService::SOURCE
                    && (float) $overtime['overtime_hours'] === 2.0,
                'API 加班寫入成功'
            );
            $leave = $this->rowByRawInput('leave_logs', $leaveText, $leaveMaxId);
            $this->assert(
                is_array($leave)
                    && (string) $leave['source'] === QuickEntryApiService::SOURCE
                    && (float) $leave['leave_days'] === 0.5,
                'API 請假寫入成功'
            );

            $ownerResponses = [
                'default' => $service->handle(['text' => $ownerDefaultText], [], 'shortcut-check'),
                'profile_a' => $service->handle(['text' => $ownerProfileAText, 'entry_owner' => '展示對象 A'], [], 'shortcut-check'),
                'profile_b' => $service->handle(['text' => $ownerProfileBText, 'entry_owner' => '展示對象 B'], [], 'shortcut-check'),
                'cash_default_0629' => $service->handle(['text' => $cashDefaultJune29Text], [], 'shortcut-check'),
                'cash_explicit_0629' => $service->handle(['text' => $cashExplicitJune29Text], [], 'shortcut-check'),
                'profile_b_specified_payment' => $service->handle(
                    ['text' => $profile_bSpecifiedPaymentText, 'entry_owner' => '展示對象 B'],
                    [],
                    'shortcut-check'
                ),
            ];
            foreach ($ownerResponses as $caseName => $response) {
                $this->assert(($response['ok'] ?? false) === true, "{$caseName} owner API response ok");
                $this->assert(($response['summary']['type'] ?? '') === 'expense', "{$caseName} owner response summary type");
            }

            $defaultOwnerExpense = $this->rowByRawInput('expenses', $ownerDefaultText, $expenseMaxId);
            $profileAOwnerExpense = $this->rowByRawInput('expenses', $ownerProfileAText, $expenseMaxId);
            $profile_bOwnerExpense = $this->rowByRawInput('expenses', $ownerProfileBText, $expenseMaxId);
            $cashDefaultJune29Expense = $this->rowByRawInput('expenses', $cashDefaultJune29Text, $expenseMaxId);
            $cashExplicitJune29Expense = $this->rowByRawInput('expenses', $cashExplicitJune29Text, $expenseMaxId);
            $profile_bSpecifiedPaymentExpense = $this->rowByRawInput('expenses', $profile_bSpecifiedPaymentText, $expenseMaxId);
            $this->assert(
                is_array($defaultOwnerExpense)
                    && (string) $defaultOwnerExpense['entry_owner'] === 'profile_a'
                    && (string) $defaultOwnerExpense['payment_method'] === $cashMethod['name'],
                'entry_owner 未帶時預設 profile_a 並補現金'
            );
            $this->assert(
                is_array($profileAOwnerExpense)
                    && (string) $profileAOwnerExpense['entry_owner'] === 'profile_a'
                    && (string) ($ownerResponses['profile_a']['summary']['entry_owner'] ?? '') === '展示對象 A',
                'entry_owner=展示對象 A 轉 profile_a 並顯示展示對象 A'
            );
            $this->assert(
                is_array($profile_bOwnerExpense)
                    && (string) $profile_bOwnerExpense['entry_owner'] === 'profile_b'
                    && (string) $profile_bOwnerExpense['payment_method'] === $cashMethod['name']
                    && (string) ($ownerResponses['profile_b']['summary']['entry_owner'] ?? '') === '展示對象 B',
                'entry_owner=展示對象 B 轉 profile_b、補現金並顯示展示對象 B'
            );
            $this->assert(
                is_array($cashDefaultJune29Expense)
                    && (string) $cashDefaultJune29Expense['payment_method'] === $cashMethod['name']
                    && (string) $cashDefaultJune29Expense['accounting_month'] === '2026/06',
                '2026-06-29 未指定付款方式補現金並歸 2026/06'
            );
            $this->assert(
                is_array($cashExplicitJune29Expense)
                    && (string) $cashExplicitJune29Expense['payment_method'] === $cashMethod['name']
                    && (string) $cashExplicitJune29Expense['accounting_month'] === '2026/06',
                '2026-06-29 明確指定現金歸 2026/06'
            );
            $this->assert(
                is_array($profile_bSpecifiedPaymentExpense)
                    && (string) $profile_bSpecifiedPaymentExpense['entry_owner'] === 'profile_b'
                    && (string) $profile_bSpecifiedPaymentExpense['payment_method'] === $specifiedMethod['name'],
                '展示對象 B支出已指定付款方式時不覆蓋成現金'
            );

            $invalidOwnerRejected = false;
            try {
                $service->handle(['text' => $invalidOwnerText, 'entry_owner' => '其他人'], [], 'shortcut-check');
            } catch (QuickEntryApiRequestException $exception) {
                $invalidOwnerRejected = $exception->errorCode() === 'invalid_entry_owner';
            }
            $this->assert($invalidOwnerRejected, '非法 entry_owner 會拒絕寫入');
            $this->assert($this->rowByRawInput('expenses', $invalidOwnerText, $expenseMaxId) === false, '非法 entry_owner 不產生支出');

            $incomeMaxAfterOwner = $this->maxId('incomes');
            $overtimeMaxAfterOwner = $this->maxId('overtime_logs');
            $leaveMaxAfterOwner = $this->maxId('leave_logs');
            $profile_bIncomeRejected = $this->profile_bNonExpenseRejected($service, $incomeText, 'income');
            $profile_bOvertimeRejected = $this->profile_bNonExpenseRejected($service, $overtimeText, 'overtime');
            $profile_bLeaveRejected = $this->profile_bNonExpenseRejected($service, $leaveText, 'leave');
            $this->assert($profile_bIncomeRejected, 'profile_b 會拒絕 income');
            $this->assert($profile_bOvertimeRejected, 'profile_b 會拒絕 overtime');
            $this->assert($profile_bLeaveRejected, 'profile_b 會拒絕 leave');
            $this->assert($this->rowByRawInput('incomes', $incomeText, $incomeMaxAfterOwner) === false, 'profile_b income 不寫入 incomes');
            $this->assert($this->rowByRawInput('overtime_logs', $overtimeText, $overtimeMaxAfterOwner) === false, 'profile_b overtime 不寫入 overtime_logs');
            $this->assert($this->rowByRawInput('leave_logs', $leaveText, $leaveMaxAfterOwner) === false, 'profile_b leave 不寫入 leave_logs');

            $logs = $this->rowsSince('ai_parse_logs', $logMaxId);
            $links = $this->rowsSince('ai_ledger_links', $linkMaxId);
            $this->assert(count($logs) === 14, 'ai_parse_logs 有 14 筆捷徑測試紀錄');
            $this->assert(count($links) === 10, 'ai_ledger_links 有十筆捷徑 trace link');
            foreach ($logs as $log) {
                $this->assert((string) $log['source'] === QuickEntryApiService::SOURCE, 'ai_parse_logs source 是 ios_shortcut');
            }
            foreach ($links as $link) {
                $this->assert((string) $link['source'] === QuickEntryApiService::SOURCE, 'ai_ledger_links source 是 ios_shortcut');
                $this->assert(in_array((string) $link['ledger_table'], [
                    'expenses',
                    'incomes',
                    'overtime_logs',
                    'leave_logs',
                ], true), 'trace link ledger table allowlisted');
            }

            $badRequestRejected = false;
            try {
                $service->handle(['client_request_id' => 'missing-text'], [], 'shortcut-check');
            } catch (QuickEntryApiRequestException $exception) {
                $badRequestRejected = $exception->errorCode() === 'missing_text';
            }
            $this->assert($badRequestRejected, '缺少 text 會回傳 JSON API request error');
        } finally {
            $this->cleanup($expenseMaxId, $incomeMaxId, $overtimeMaxId, $leaveMaxId, $logMaxId, $linkMaxId, $texts);
        }

        $this->assert(count($this->rowsSince('ai_parse_logs', $logMaxId)) === 0, '測試 ai_parse_logs 已清理');
        $this->assert(count($this->rowsSince('ai_ledger_links', $linkMaxId)) === 0, '測試 ai_ledger_links 已清理');

        $this->runLegacyGasShorthandMatrix();

        echo "==================================\n";
        echo sprintf("PASS: %d\nFAIL: %d\n", $this->passed, $this->failed);
        echo $this->failed === 0 ? "RESULT: PASS\n" : "RESULT: FAIL\n";

        return $this->failed === 0 ? 0 : 1;
    }

    private function runLegacyGasShorthandMatrix(): void
    {
        echo "\nLegacy GAS shorthand matrix\n";
        echo "---------------------------\n";

        $paymentMethod = $this->activePaymentMethodByName('現金');
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d');
        $accountingMonth = $this->accountingMonthForPaymentMethod($today, $paymentMethod['name']);
        $cases = [
            [
                'text' => '早餐1現金',
                'type' => 'expense',
                'item' => '早餐',
                'amount' => 1.0,
                'payment_method' => $paymentMethod['name'],
                'record_date' => $today,
                'accounting_month' => $accountingMonth,
            ],
            [
                'text' => '早餐80現金',
                'type' => 'expense',
                'item' => '早餐',
                'amount' => 80.0,
                'payment_method' => $paymentMethod['name'],
                'record_date' => $today,
                'accounting_month' => $accountingMonth,
            ],
            [
                'text' => '午餐100現金',
                'type' => 'expense',
                'item' => '午餐',
                'amount' => 100.0,
                'payment_method' => $paymentMethod['name'],
                'record_date' => $today,
                'accounting_month' => $accountingMonth,
            ],
            [
                'text' => '飲料35現金',
                'type' => 'expense',
                'item' => '飲料',
                'amount' => 35.0,
                'payment_method' => $paymentMethod['name'],
                'record_date' => $today,
                'accounting_month' => $accountingMonth,
            ],
            [
                'text' => '加班3小時',
                'type' => 'overtime',
                'overtime_hours' => 3.0,
            ],
            [
                'text' => '加班 3 小時',
                'type' => 'overtime',
                'overtime_hours' => 3.0,
            ],
        ];
        $rawInputs = array_map(static fn (array $case): string => $case['text'], $cases);

        $expenseMaxId = $this->maxId('expenses');
        $incomeMaxId = $this->maxId('incomes');
        $overtimeMaxId = $this->maxId('overtime_logs');
        $leaveMaxId = $this->maxId('leave_logs');
        $logMaxId = $this->maxId('ai_parse_logs');
        $linkMaxId = $this->maxId('ai_ledger_links');
        $existingOvertime = $this->overtimeRowsForDate($today);

        try {
            $service = new QuickEntryApiService(
                $this->pdo,
                new ShortcutEntryFixtureParser($this->pdo, $this->fixturesForLegacyCases($cases))
            );
            foreach ($cases as $index => $case) {
                $text = $case['text'];
                $response = $service->handle([
                    'text' => $text,
                    'client_request_id' => 'legacy_gas_short_' . date('YmdHis') . '_' . (string) $index,
                ], [], 'shortcut-entry-check');

                $this->assert(($response['ok'] ?? false) === true, "{$text} API response ok=true");
                $this->assert(($response['summary']['type'] ?? '') === $case['type'], "{$text} response summary type");
                $this->assert(($response['error'] ?? null) === null, "{$text} response error is null");
                $responseJson = json_encode(
                    $response,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
                $this->assert(!str_contains($responseJson, 'ledger_id'), "{$text} response does not expose ledger id");
                $this->assert(!str_contains($responseJson, 'ai_ledger_link_id'), "{$text} response does not expose trace link id");
                $this->assert(!str_contains($responseJson, 'trace_link_id'), "{$text} response does not expose trace link id alias");

                if ($case['type'] === 'expense') {
                    $this->assert(($response['summary']['title'] ?? '') === $case['item'], "{$text} response item");
                    $this->assert((float) ($response['summary']['amount'] ?? 0) === $case['amount'], "{$text} response amount");
                    $this->assert(($response['summary']['payment_method'] ?? '') === $case['payment_method'], "{$text} response payment method");
                    $this->assert(($response['summary']['accounting_month'] ?? '') === $case['accounting_month'], "{$text} response accounting_month");
                    $expense = $this->rowByRawInput('expenses', $text, $expenseMaxId);
                    $this->assert(
                        is_array($expense)
                            && (string) $expense['source'] === QuickEntryApiService::SOURCE
                            && (string) $expense['record_date'] === $case['record_date']
                            && (string) $expense['item'] === $case['item']
                            && (float) $expense['amount'] === $case['amount']
                            && (string) $expense['payment_method'] === $case['payment_method']
                            && (string) $expense['accounting_month'] === $case['accounting_month'],
                        "{$text} expense row matches expected date / month / item / amount / payment"
                    );
                    continue;
                }

                $this->assert((float) ($response['summary']['overtime_hours'] ?? 0) === $case['overtime_hours'], "{$text} response overtime_hours");
                $this->assert(($response['summary']['unit'] ?? '') === '小時', "{$text} response unit is hours");
                $rows = $this->overtimeRowsForDate($today);
                $this->assert(count($rows) === 1, "{$text} overtime_logs keeps one row for today");
                $overtime = $rows[0] ?? null;
                $this->assert(
                    is_array($overtime)
                        && (string) $overtime['source'] === QuickEntryApiService::SOURCE
                        && (string) $overtime['raw_input'] === $text
                        && (float) $overtime['overtime_hours'] === $case['overtime_hours'],
                    "{$text} overtime row matches expected hours / source"
                );
            }

            $logs = $this->logsByRawInputsSince($logMaxId, $rawInputs);
            $this->assert(count($logs) === count($cases), 'legacy shorthand ai_parse_logs has one success per input');
            foreach ($logs as $log) {
                $text = (string) $log['raw_input'];
                $case = $this->caseByText($cases, $text);
                $this->assert((string) $log['parse_status'] === 'success', "{$text} ai_parse_logs success");
                $this->assert((string) $log['parsed_type'] === $case['type'], "{$text} ai_parse_logs parsed_type");
                $this->assert((string) $log['source'] === QuickEntryApiService::SOURCE, "{$text} ai_parse_logs source=ios_shortcut");
                $this->assert((string) $log['provider'] === 'shortcut_entry_check', "{$text} ai_parse_logs provider=fixture");
                $parsedJson = json_decode((string) $log['parsed_json'], true, 512, JSON_THROW_ON_ERROR);
                $fields = is_array($parsedJson['fields'] ?? null) ? $parsedJson['fields'] : [];
                if ($case['type'] === 'expense') {
                    $this->assert(($fields['item'] ?? '') === $case['item'], "{$text} parsed_json item");
                    $this->assert((float) ($fields['amount'] ?? 0) === $case['amount'], "{$text} parsed_json amount");
                    $this->assert(($fields['payment_method'] ?? '') === $case['payment_method'], "{$text} parsed_json payment_method");
                    $this->assert(($fields['accounting_month'] ?? '') === $case['accounting_month'], "{$text} parsed_json accounting_month");
                } else {
                    $this->assert((float) ($fields['overtime_hours'] ?? 0) === $case['overtime_hours'], "{$text} parsed_json overtime_hours");
                }
            }

            $links = $this->linksByRawInputsSince($linkMaxId, $rawInputs);
            $this->assert(count($links) === count($cases), 'legacy shorthand ai_ledger_links has one link per input');
            foreach ($links as $link) {
                $text = (string) $link['raw_input_snapshot'];
                $case = $this->caseByText($cases, $text);
                $expectedTable = $case['type'] === 'expense' ? 'expenses' : 'overtime_logs';
                $this->assert((string) $link['ledger_table'] === $expectedTable, "{$text} ai_ledger_links ledger_table");
                $this->assert((string) $link['source'] === QuickEntryApiService::SOURCE, "{$text} ai_ledger_links source=ios_shortcut");
                $this->assert((string) $link['parsed_type_snapshot'] === $case['type'], "{$text} ai_ledger_links parsed_type_snapshot");
            }
        } finally {
            $this->cleanupLegacyGasShorthandMatrix(
                $expenseMaxId,
                $incomeMaxId,
                $overtimeMaxId,
                $leaveMaxId,
                $logMaxId,
                $linkMaxId,
                $today,
                $existingOvertime,
                $rawInputs
            );
        }

        $this->assert(
            count($this->logsByRawInputsSince($logMaxId, $rawInputs)) === 0,
            'legacy shorthand ai_parse_logs cleaned up'
        );
        $this->assert(
            count($this->linksByRawInputsSince($linkMaxId, $rawInputs)) === 0,
            'legacy shorthand ai_ledger_links cleaned up'
        );
        $this->assert($this->overtimeStateMatches($today, $existingOvertime), 'legacy shorthand overtime state restored or cleaned up');
    }

    private function assertTestDbGate(): void
    {
        $appEnv = app_env('APP_ENV', '');
        $configuredDatabase = app_env('DB_DATABASE', '');
        $actualDatabase = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();

        echo "APP_ENV={$appEnv}\n";
        echo "DB_DATABASE={$configuredDatabase}\n";
        echo "SELECT_DATABASE={$actualDatabase}\n";

        if (!in_array($appEnv, ['testing', 'development'], true)) {
            throw new RuntimeException('Shortcut Entry check requires APP_ENV testing/development.');
        }
        if ($configuredDatabase !== 'personal_accounting_test') {
            throw new RuntimeException('Shortcut Entry check requires DB_DATABASE=personal_accounting_test.');
        }
        if ($actualDatabase !== 'personal_accounting_test') {
            throw new RuntimeException('Shortcut Entry check requires SELECT DATABASE()=personal_accounting_test.');
        }
    }

    /** @return array{id: int, name: string} */
    private function activePaymentMethod(): array
    {
        $row = $this->pdo->query(
            'SELECT id, name FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id LIMIT 1'
        )->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('找不到啟用中的付款方式。');
        }

        return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    /** @return array{id: int, name: string} */
    private function activePaymentMethodByName(string $name): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name FROM payment_methods WHERE is_active = 1 AND name = :name ORDER BY sort_order, id LIMIT 1'
        );
        $statement->execute(['name' => $name]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('找不到啟用中的付款方式：' . $name);
        }

        return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    /** @return array{id: int, name: string} */
    private function activeNonCashPaymentMethod(): array
    {
        $row = $this->pdo->query(
            "SELECT id, name FROM payment_methods
             WHERE is_active = 1 AND name <> '現金'
             ORDER BY sort_order, id LIMIT 1"
        )->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('找不到啟用中的非現金付款方式。');
        }

        return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    private function accountingMonthForPaymentMethod(string $recordDate, string $paymentMethodName): string
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, settlement_start_day, settlement_end_day
             FROM payment_methods WHERE is_active = 1 AND name = :name LIMIT 1'
        );
        $statement->execute(['name' => $paymentMethodName]);
        $method = $statement->fetch();
        if (!is_array($method)) {
            throw new RuntimeException('找不到啟用中的付款方式：' . $paymentMethodName);
        }

        return AccountingMonthService::forPaymentMethod($recordDate, $method);
    }

    private function profile_bNonExpenseRejected(QuickEntryApiService $service, string $text, string $expectedType): bool
    {
        try {
            $service->handle(['text' => $text, 'entry_owner' => '展示對象 B'], [], 'shortcut-check');
        } catch (QuickEntryValidationException $exception) {
            return $exception->entryType() === $expectedType
                && isset($exception->fieldErrors()['entry_owner']);
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $cases
     * @return array<string, array<string, mixed>>
     */
    private function fixturesForLegacyCases(array $cases): array
    {
        $fixtures = [];
        foreach ($cases as $case) {
            if (($case['type'] ?? '') === 'expense') {
                $fixtures[(string) $case['text']] = [
                    'type' => 'expense',
                    'record_date' => $case['record_date'],
                    'item' => $case['item'],
                    'amount' => (string) $case['amount'],
                    'payment_method' => $case['payment_method'],
                    'category' => '餐飲',
                ];
                continue;
            }

            $fixtures[(string) $case['text']] = [
                'type' => 'overtime',
                'work_date' => date('Y-m-d'),
                'overtime_hours' => (string) $case['overtime_hours'],
            ];
        }

        return $fixtures;
    }

    /** @return array<string, mixed> */
    private function aiSettings(): array
    {
        $settings = $this->pdo->query('SELECT * FROM ai_settings WHERE id = 1')->fetch();
        if (!is_array($settings)) {
            throw new RuntimeException('找不到 ai_settings。');
        }

        return $settings;
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function runtimeAiSettings(array $settings): array
    {
        $provider = trim((string) app_env('SHORTCUT_ENTRY_CHECK_PROVIDER', ''));
        if ($provider !== '') {
            $settings['provider'] = $provider;
        }

        $modelName = trim((string) app_env('SHORTCUT_ENTRY_CHECK_MODEL_NAME', ''));
        if ($modelName !== '') {
            $settings['model_name'] = $modelName;
        }

        if ((string) app_env('SHORTCUT_ENTRY_CHECK_ENABLE_AI', '') === '1') {
            $settings['is_enabled'] = 1;
        }

        return $settings;
    }

    /** @return list<array<string, mixed>> */
    private function overtimeRowsForDate(string $workDate): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM overtime_logs WHERE work_date = :work_date ORDER BY id');
        $statement->execute(['work_date' => $workDate]);

        return $statement->fetchAll();
    }

    private function activeLeaveType(): string
    {
        $name = $this->pdo->query(
            'SELECT name FROM leave_types WHERE is_active = 1 ORDER BY sort_order, id LIMIT 1'
        )->fetchColumn();
        if (!is_string($name) || $name === '') {
            throw new RuntimeException('找不到啟用中的假別。');
        }

        return $name;
    }

    private function unusedDate(string $table, string $column, int $startOffset): string
    {
        for ($offset = $startOffset; $offset < $startOffset + 120; $offset++) {
            $date = date('Y-m-d', strtotime('+' . $offset . ' days'));
            $statement = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = :date', $table, $column));
            $statement->execute(['date' => $date]);
            if ((int) $statement->fetchColumn() === 0) {
                return $date;
            }
        }

        throw new RuntimeException('找不到可用的測試日期。');
    }

    private function maxId(string $table): int
    {
        if (!in_array($table, [
            'expenses',
            'incomes',
            'overtime_logs',
            'leave_logs',
            'ai_parse_logs',
            'ai_ledger_links',
        ], true)) {
            throw new InvalidArgumentException('Unsupported table');
        }

        return (int) $this->pdo->query(sprintf('SELECT COALESCE(MAX(id), 0) FROM `%s`', $table))->fetchColumn();
    }

    /** @return array<string, mixed>|false */
    private function rowByRawInput(string $table, string $rawInput, int $maxId): array|false
    {
        if (!in_array($table, ['expenses', 'incomes', 'overtime_logs', 'leave_logs'], true)) {
            throw new InvalidArgumentException('Unsupported table');
        }
        $statement = $this->pdo->prepare(
            sprintf('SELECT * FROM `%s` WHERE id > :id AND raw_input = :raw_input LIMIT 1', $table)
        );
        $statement->execute(['id' => $maxId, 'raw_input' => $rawInput]);

        return $statement->fetch();
    }

    /** @return list<array<string, mixed>> */
    private function rowsSince(string $table, int $maxId): array
    {
        if (!in_array($table, ['ai_parse_logs', 'ai_ledger_links'], true)) {
            throw new InvalidArgumentException('Unsupported table');
        }
        $statement = $this->pdo->prepare(sprintf('SELECT * FROM `%s` WHERE id > :id ORDER BY id', $table));
        $statement->execute(['id' => $maxId]);

        return $statement->fetchAll();
    }

    /** @param list<string> $rawInputs @return list<array<string, mixed>> */
    private function logsByRawInputsSince(int $maxId, array $rawInputs): array
    {
        if ($rawInputs === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($rawInputs), '?'));
        $statement = $this->pdo->prepare(
            'SELECT * FROM ai_parse_logs
             WHERE id > ? AND source = ? AND raw_input IN (' . $placeholders . ')
             ORDER BY id'
        );
        $statement->execute(array_merge([$maxId, QuickEntryApiService::SOURCE], $rawInputs));

        return $statement->fetchAll();
    }

    /** @param list<string> $rawInputs @return list<array<string, mixed>> */
    private function linksByRawInputsSince(int $maxId, array $rawInputs): array
    {
        if ($rawInputs === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($rawInputs), '?'));
        $statement = $this->pdo->prepare(
            'SELECT * FROM ai_ledger_links
             WHERE id > ? AND source = ? AND raw_input_snapshot IN (' . $placeholders . ')
             ORDER BY id'
        );
        $statement->execute(array_merge([$maxId, QuickEntryApiService::SOURCE], $rawInputs));

        return $statement->fetchAll();
    }

    /**
     * @param list<array<string, mixed>> $cases
     * @return array<string, mixed>
     */
    private function caseByText(array $cases, string $text): array
    {
        foreach ($cases as $case) {
            if (($case['text'] ?? null) === $text) {
                return $case;
            }
        }

        throw new RuntimeException('Unexpected legacy GAS shorthand text: ' . $text);
    }

    /** @return array<string, mixed>|false */
    private function latestLogByRawInput(string $rawInput, int $maxId): array|false
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM ai_parse_logs WHERE id > :id AND raw_input = :raw_input ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['id' => $maxId, 'raw_input' => $rawInput]);

        return $statement->fetch();
    }

    /** @param list<string> $rawInputs */
    private function cleanup(
        int $expenseMaxId,
        int $incomeMaxId,
        int $overtimeMaxId,
        int $leaveMaxId,
        int $logMaxId,
        int $linkMaxId,
        array $rawInputs
    ): void {
        $this->deleteRowsSince('ai_ledger_links', $linkMaxId);
        $this->deleteBusinessRows('expenses', $expenseMaxId, $rawInputs);
        $this->deleteBusinessRows('incomes', $incomeMaxId, $rawInputs);
        $this->deleteBusinessRows('overtime_logs', $overtimeMaxId, $rawInputs);
        $this->deleteBusinessRows('leave_logs', $leaveMaxId, $rawInputs);
        $this->deleteRowsSince('ai_parse_logs', $logMaxId);
    }

    /**
     * @param list<array<string, mixed>> $existingOvertime
     * @param list<string> $rawInputs
     */
    private function cleanupLegacyGasShorthandMatrix(
        int $expenseMaxId,
        int $incomeMaxId,
        int $overtimeMaxId,
        int $leaveMaxId,
        int $logMaxId,
        int $linkMaxId,
        string $workDate,
        array $existingOvertime,
        array $rawInputs
    ): void {
        $placeholders = implode(', ', array_fill(0, count($rawInputs), '?'));
        $statement = $this->pdo->prepare(
            'DELETE FROM ai_ledger_links
             WHERE id > ? AND source = ? AND raw_input_snapshot IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$linkMaxId, QuickEntryApiService::SOURCE], $rawInputs));

        $this->deleteBusinessRows('expenses', $expenseMaxId, $rawInputs);
        $this->deleteBusinessRows('incomes', $incomeMaxId, $rawInputs);
        $this->deleteBusinessRows('leave_logs', $leaveMaxId, $rawInputs);

        if ($existingOvertime === []) {
            $statement = $this->pdo->prepare(
                'DELETE FROM overtime_logs
                 WHERE id > ? AND work_date = ? AND source = ? AND raw_input IN (' . $placeholders . ')'
            );
            $statement->execute(array_merge([$overtimeMaxId, $workDate, QuickEntryApiService::SOURCE], $rawInputs));
        } else {
            $this->restoreOvertimeRow($existingOvertime[0]);
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM ai_parse_logs
             WHERE id > ? AND source = ? AND raw_input IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$logMaxId, QuickEntryApiService::SOURCE], $rawInputs));
    }

    /** @param array<string, mixed> $row */
    private function restoreOvertimeRow(array $row): void
    {
        $columns = [
            'work_date',
            'overtime_hours',
            'hours_134',
            'hours_167',
            'meal_fee',
            'night_snack_fee',
            'note',
            'raw_input',
            'user_name',
            'source',
            'is_deleted',
            'deleted_at',
            'created_at',
            'updated_at',
        ];
        $assignments = [];
        $params = ['id' => $row['id']];
        foreach ($columns as $column) {
            if (array_key_exists($column, $row)) {
                $assignments[] = sprintf('`%s` = :%s', $column, $column);
                $params[$column] = $row[$column];
            }
        }
        if ($assignments === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            sprintf('UPDATE overtime_logs SET %s WHERE id = :id', implode(', ', $assignments))
        );
        $statement->execute($params);
    }

    /** @param list<array<string, mixed>> $expectedRows */
    private function overtimeStateMatches(string $workDate, array $expectedRows): bool
    {
        $currentRows = $this->overtimeRowsForDate($workDate);
        if (count($currentRows) !== count($expectedRows)) {
            return false;
        }
        if ($expectedRows === []) {
            return true;
        }

        $expected = $expectedRows[0];
        $current = $currentRows[0];
        foreach (['id', 'work_date', 'overtime_hours', 'raw_input', 'source', 'is_deleted', 'deleted_at'] as $key) {
            if ((string) ($current[$key] ?? '') !== (string) ($expected[$key] ?? '')) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $rawInputs */
    private function deleteBusinessRows(string $table, int $maxId, array $rawInputs): void
    {
        if (!in_array($table, ['expenses', 'incomes', 'overtime_logs', 'leave_logs'], true)) {
            throw new InvalidArgumentException('Unsupported table');
        }
        $placeholders = implode(', ', array_fill(0, count($rawInputs), '?'));
        $statement = $this->pdo->prepare(
            sprintf(
                "DELETE FROM `%s` WHERE id > ? AND source = ? AND raw_input IN (%s)",
                $table,
                $placeholders
            )
        );
        $statement->execute(array_merge([$maxId, QuickEntryApiService::SOURCE], $rawInputs));
    }

    private function deleteRowsSince(string $table, int $maxId): void
    {
        if (!in_array($table, ['ai_parse_logs', 'ai_ledger_links'], true)) {
            throw new InvalidArgumentException('Unsupported table');
        }
        $statement = $this->pdo->prepare(sprintf('DELETE FROM `%s` WHERE id > :id', $table));
        $statement->execute(['id' => $maxId]);
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;
            echo "[PASS] {$message}\n";
            return;
        }

        $this->failed++;
        echo "[FAIL] {$message}\n";
    }
}

exit((new ShortcutEntryCheck(app_db()))->run());
