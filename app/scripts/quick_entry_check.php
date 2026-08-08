<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/env.php';
require_once dirname(__DIR__) . '/src/AccountingMonthService.php';

const QUICK_ENTRY_BUSINESS_TABLES = [
    'expenses',
    'incomes',
    'overtime_logs',
    'leave_logs',
];

final class QuickEntryCheck
{
    private int $passed = 0;
    private int $failed = 0;
    private string $cookieFile;
    private string $baseUrl;

    public function __construct(private readonly PDO $pdo)
    {
        $cookieFile = tempnam(sys_get_temp_dir(), 'quick-entry-cookie-');
        if ($cookieFile === false) {
            throw new RuntimeException('無法建立測試 cookie 檔案。');
        }
        $this->cookieFile = $cookieFile;
        $this->baseUrl = rtrim((string) app_env('QUICK_ENTRY_BASE_URL', 'http://127.0.0.1'), '/');
    }

    public function __destruct()
    {
        if (is_file($this->cookieFile)) {
            unlink($this->cookieFile);
        }
    }

    public function run(): int
    {
        echo "Quick Entry PWA Check\n";
        echo "=====================\n";
        $this->assertTestDbGate();
        echo "QUICK_ENTRY_BASE_URL={$this->baseUrl}\n";

        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/public/manifest.json'),
            true
        );
        $this->assert(
            is_array($manifest)
                && ($manifest['start_url'] ?? '') === '/quick_entry.php'
                && ($manifest['display'] ?? '') === 'standalone'
                && !str_contains((string) ($manifest['description'] ?? ''), '不會自動寫入資料'),
            'manifest 主畫面入口與直接寫入描述正確'
        );

        $serviceWorker = (string) file_get_contents(dirname(__DIR__) . '/public/service-worker.js');
        $this->assert(
            !str_contains($serviceWorker, "'/quick_entry.php'")
                && str_contains($serviceWorker, "request.method !== 'GET'"),
            'Service Worker 不快取 quick entry HTML 或 POST'
        );

        $quickPage = $this->request('/quick_entry.php');
        $this->assert(
            str_contains($quickPage, '快速記帳')
                && str_contains($quickPage, '直接記帳')
                && str_contains($quickPage, 'data-disable-submit="1"')
                && !str_contains($quickPage, 'name="return_to" value="/quick_entry.php"')
                && !str_contains($quickPage, '確認儲存'),
            '未登入可開 quick entry 並直接送出'
        );
        $this->assert(
            str_contains($quickPage, 'rel="manifest"')
                && str_contains($quickPage, 'rel="apple-touch-icon"')
                && str_contains($quickPage, "register('/service-worker.js')"),
            'quick entry 載入 PWA 與 Apple Touch Icon 設定'
        );

        $css = (string) file_get_contents(dirname(__DIR__) . '/public/style.css');
        $this->assert(
            str_contains($css, '.preview-field input[type="date"]')
                && str_contains($css, 'inline-size: 100%')
                && str_contains($css, 'max-width: 100%')
                && str_contains($css, 'min-width: 0'),
            '手機日期欄位限制在預覽卡片內'
        );

        $today = date('Y-m-d');
        $expensesMaxId = $this->maxId('expenses');
        $incomesMaxId = $this->maxId('incomes');
        $overtimeBefore = $this->rowsForDate('overtime_logs', 'work_date', $today);
        $leaveBefore = $this->rowsForDate('leave_logs', 'leave_date', $today);
        $logsBefore = $this->logCount();
        $linksMaxId = $this->maxLinkId();
        $testLogIds = [];
        $cashMethodId = $this->activeIdByName('payment_methods', '現金');
        $syntheticMethodId = $this->activeIdByName('payment_methods', '展示方式 B');

        try {
            $testLogIds[] = $this->insertTestParseLog('早餐80現金', 'expense', [
                'record_date' => $today,
                'item' => '早餐',
                'amount' => 80,
                'payment_method' => '現金',
                'category' => '餐飲',
            ]);
            $breakfast = $this->request('/quick_entry.php', [
                'action' => 'correct',
                'entry_type' => 'expense',
                'input_text' => '早餐80現金',
                'ai_parse_log_id' => (string) $testLogIds[count($testLogIds) - 1],
                'record_date' => $today,
                'item' => '早餐',
                'amount' => '80',
                'payment_method_id' => (string) $cashMethodId,
                'category' => '餐飲',
            ]);
            $this->assert(
                str_contains($breakfast, '寫入成功')
                    && str_contains($breakfast, '已完成')
                    && str_contains($breakfast, '已完成，可返回主畫面')
                    && $this->containsAll($breakfast, [
                        '類型：支出',
                        '日期：' . $today,
                        '金額：80 元',
                        '分類：餐飲',
                        '付款方式：現金',
                        '原始輸入：早餐80現金',
                    ])
                    && str_contains($breakfast, 'window.close()')
                    && !str_contains($breakfast, '確認儲存'),
                '早餐80現金直接新增支出並顯示完成頁摘要'
            );

            $testLogIds[] = $this->insertTestParseLog('加油1000展示方式 B', 'expense', [
                'record_date' => $today,
                'item' => '加油',
                'amount' => 1000,
                'payment_method' => '展示方式 B',
                'category' => '交通',
            ]);
            $fuel = $this->request('/quick_entry.php', [
                'action' => 'correct',
                'entry_type' => 'expense',
                'input_text' => '加油1000展示方式 B',
                'ai_parse_log_id' => (string) $testLogIds[count($testLogIds) - 1],
                'record_date' => $today,
                'item' => '加油',
                'amount' => '1000',
                'payment_method_id' => (string) $syntheticMethodId,
                'category' => '交通',
            ]);
            $this->assert(
                str_contains($fuel, '寫入成功')
                    && str_contains($fuel, '已完成，可返回主畫面')
                    && str_contains($fuel, 'window.close()'),
                '加油1000展示方式 B直接新增支出並顯示完成頁'
            );

            $testLogIds[] = $this->insertTestParseLog('薪資41189', 'income', [
                'record_date' => $today,
                'source_name' => '薪資',
                'amount' => 41189,
                'account_name' => '',
                'category' => '薪資',
            ]);
            $salary = $this->request('/quick_entry.php', [
                'action' => 'correct',
                'entry_type' => 'income',
                'input_text' => '薪資41189',
                'ai_parse_log_id' => (string) $testLogIds[count($testLogIds) - 1],
                'record_date' => $today,
                'source_name' => '薪資',
                'amount' => '41189',
                'account_id' => '',
                'category' => '薪資',
            ]);
            $this->assert(
                str_contains($salary, '寫入成功')
                    && str_contains($salary, '已完成，可返回主畫面')
                    && $this->containsAll($salary, [
                        '類型：收入',
                        '日期：' . $today,
                        '金額：41,189 元',
                        '分類：薪資',
                        '帳戶：未指定帳戶',
                        '原始輸入：薪資41189',
                    ])
                    && str_contains($salary, 'window.close()'),
                '薪資41189直接新增收入並顯示完成頁摘要'
            );

            $testLogIds[] = $this->insertTestParseLog('薪水50000', 'income', [
                'record_date' => $today,
                'source_name' => '薪水',
                'amount' => 50000,
                'account_name' => '',
                'category' => '薪資',
            ]);
            $spokenSalary = $this->request('/quick_entry.php', [
                'action' => 'correct',
                'entry_type' => 'income',
                'input_text' => '薪水50000',
                'ai_parse_log_id' => (string) $testLogIds[count($testLogIds) - 1],
                'record_date' => $today,
                'source_name' => '薪水',
                'amount' => '50000',
                'account_id' => '',
                'category' => '薪資',
            ]);
            $this->assert(
                str_contains($spokenSalary, '寫入成功')
                    && str_contains($spokenSalary, '已完成，可返回主畫面')
                    && str_contains($spokenSalary, 'window.close()'),
                '薪水50000直接新增收入並顯示完成頁'
            );

            $testLogIds[] = $this->insertTestParseLog('今天加班3小時', 'overtime', [
                'work_date' => $today,
                'overtime_hours' => 3,
            ]);
            $overtime = $this->request('/quick_entry.php', [
                'action' => 'correct',
                'entry_type' => 'overtime',
                'input_text' => '今天加班3小時',
                'ai_parse_log_id' => (string) $testLogIds[count($testLogIds) - 1],
                'work_date' => $today,
                'overtime_hours' => '3',
            ]);
            $this->assert(
                str_contains($overtime, '寫入成功')
                    && str_contains($overtime, '已完成，可返回主畫面')
                    && $this->containsAll($overtime, [
                        '類型：加班',
                        '日期：' . $today,
                        '時數：3 小時',
                        '原始輸入：今天加班3小時',
                    ])
                    && str_contains($overtime, 'window.close()'),
                '今天加班3小時寫入或更新加班並顯示完成頁摘要'
            );

            $testLogIds[] = $this->insertTestParseLog('今天特休1天', 'leave', [
                'leave_date' => $today,
                'leave_type' => '特休',
                'leave_days' => 1,
                'leave_hours' => 0,
                'note' => '',
            ]);
            $leave = $this->request('/quick_entry.php', [
                'action' => 'correct',
                'entry_type' => 'leave',
                'input_text' => '今天特休1天',
                'ai_parse_log_id' => (string) $testLogIds[count($testLogIds) - 1],
                'leave_date' => $today,
                'leave_type' => '特休',
                'leave_days' => '1',
                'leave_hours' => '0',
                'note' => '',
            ]);
            $this->assert(
                str_contains($leave, '寫入成功')
                    && str_contains($leave, '已完成，可返回主畫面')
                    && $this->containsAll($leave, [
                        '類型：請假',
                        '日期：' . $today,
                        '天數：1 天',
                        '原始輸入：今天特休1天',
                    ])
                    && str_contains($leave, 'window.close()'),
                '今天特休1天寫入或更新請假並顯示完成頁摘要'
            );

            $testLogIds[] = $this->insertTestParseLog('請特休一天', 'leave', [
                'leave_date' => $today,
                'leave_type' => '特休',
                'leave_days' => 1,
                'leave_hours' => 0,
                'note' => '',
            ]);
            $spokenLeave = $this->request('/quick_entry.php', [
                'action' => 'correct',
                'entry_type' => 'leave',
                'input_text' => '請特休一天',
                'ai_parse_log_id' => (string) $testLogIds[count($testLogIds) - 1],
                'leave_date' => $today,
                'leave_type' => '特休',
                'leave_days' => '1',
                'leave_hours' => '0',
                'note' => '',
            ]);
            $this->assert(
                str_contains($spokenLeave, '寫入成功')
                    && str_contains($spokenLeave, '已完成，可返回主畫面')
                    && str_contains($spokenLeave, 'window.close()'),
                '請特休一天寫入或更新請假並顯示完成頁'
            );

            $expenseRows = $this->pdo->query(
                "SELECT id, item, amount, payment_method, category, accounting_month
                 FROM expenses
                 WHERE id > $expensesMaxId AND source = 'quick_pwa'
                 ORDER BY id"
            )->fetchAll();
            $this->assert(
                count($expenseRows) === 2
                    && $expenseRows[0]['item'] === '早餐'
                    && (float) $expenseRows[0]['amount'] === 80.0
                    && $expenseRows[0]['category'] !== ''
                    && $expenseRows[1]['item'] === '加油'
                    && (float) $expenseRows[1]['amount'] === 1000.0
                    && $expenseRows[1]['payment_method'] === '展示方式 B'
                    && $expenseRows[1]['accounting_month'] !== '',
                '支出欄位、分類與帳單月份正確'
            );

            $incomeRows = $this->pdo->query(
                "SELECT id, source_name, amount, category, accounting_month
                 FROM incomes
                 WHERE id > $incomesMaxId AND source = 'quick_pwa'
                 ORDER BY id"
            )->fetchAll();
            $this->assert(
                count($incomeRows) === 2
                    && $incomeRows[0]['source_name'] === '薪資'
                    && (float) $incomeRows[0]['amount'] === 41189.0
                    && $incomeRows[0]['category'] !== ''
                    && $incomeRows[0]['accounting_month'] === date('Y/m')
                    && trim((string) $incomeRows[1]['source_name']) !== ''
                    && (float) $incomeRows[1]['amount'] === 50000.0
                    && $incomeRows[1]['category'] !== ''
                    && $incomeRows[1]['accounting_month'] === date('Y/m'),
                '收入欄位與歸屬月份正確'
            );

            $leaveAfter = $this->rowsForDate('leave_logs', 'leave_date', $today);
            $activeTodayLeave = array_values(array_filter($leaveAfter, static fn (array $row): bool => (int) $row['is_deleted'] === 0));
            $this->assert(
                count($activeTodayLeave) === 1
                    && $activeTodayLeave[0]['leave_type'] === '特休'
                    && (float) $activeTodayLeave[0]['leave_days'] === 1.0
                    && (float) $activeTodayLeave[0]['leave_hours'] === 0.0,
                '請特休一天寫入或更新為一日特休'
            );
            $overtimeAfter = $this->rowsForDate('overtime_logs', 'work_date', $today);
            $activeTodayOvertime = array_values(array_filter($overtimeAfter, static fn (array $row): bool => (int) $row['is_deleted'] === 0));
            $this->assert(
                count($activeTodayOvertime) === 1
                    && (string) $activeTodayOvertime[0]['source'] === 'quick_pwa',
                '加班寫入或更新時明確寫入 quick_pwa source'
            );
            $this->assert(
                count($activeTodayLeave) === 1
                    && (string) $activeTodayLeave[0]['source'] === 'quick_pwa'
                    && (string) $activeTodayLeave[0]['raw_input'] !== '',
                '請假寫入或更新時明確寫入 quick_pwa source 與 raw_input'
            );

            $incompleteCounts = $this->tableCounts();
            $incomplete = $this->request('/quick_entry.php', [
                'action' => 'correct',
                'entry_type' => 'expense',
                'input_text' => '100',
                'record_date' => $today,
                'item' => '',
                'amount' => '100',
                'payment_method_id' => '',
                'category' => '',
            ]);
            $this->assert(
                str_contains($incomplete, '需要修正')
                    && str_contains($incomplete, '項目不可空白')
                    && str_contains($incomplete, '請選擇有效的付款方式')
                    && $this->tableCounts() === $incompleteCounts,
                '欄位不足顯示修正表單且不寫入'
            );
            $this->assert(
                $this->logCount() === $logsBefore + 7,
                '七筆測試 successful ai_parse_logs 已建立'
            );
            $newLinks = $this->linksSince($linksMaxId);
            $this->assert(
                count($newLinks) === 7,
                '七次 Quick Entry 成功寫入各新增一筆 ai_ledger_links'
            );
            $this->assertTraceLinks($newLinks, [
                'expenses' => [$expenseRows[0]['id'], $expenseRows[1]['id']],
                'incomes' => [$incomeRows[0]['id'], $incomeRows[1]['id']],
                'overtime_logs' => [$activeTodayOvertime[0]['id']],
                'leave_logs' => [$activeTodayLeave[0]['id'], $activeTodayLeave[0]['id']],
            ]);
            $this->loginForAdminPages();
            $this->assertTraceDisplay(
                $testLogIds,
                $expenseRows,
                $incomeRows,
                $activeTodayOvertime[0],
                $activeTodayLeave[0]
            );
        } finally {
            $this->pdo->exec(
                "DELETE FROM expenses
                 WHERE id > $expensesMaxId AND source = 'quick_pwa'
                   AND raw_input IN ('早餐80現金', '加油1000展示方式 B')"
            );
            $this->pdo->exec(
                "DELETE FROM incomes
                 WHERE id > $incomesMaxId AND source = 'quick_pwa'
                   AND raw_input IN ('薪資41189', '薪水50000')"
            );
            $this->deleteLinksSince($linksMaxId);
            $this->deleteTestParseLogs($testLogIds);
            $this->restoreDatedRows('overtime_logs', 'work_date', $today, $overtimeBefore);
            $this->restoreDatedRows('leave_logs', 'leave_date', $today, $leaveBefore);
        }

        echo "=====================\n";
        echo sprintf("PASS: %d\nFAIL: %d\n", $this->passed, $this->failed);
        echo $this->failed === 0 ? "RESULT: PASS\n" : "RESULT: FAIL\n";

        return $this->failed === 0 ? 0 : 1;
    }

    /** @param array<string, string>|null $post */
    private function request(string $path, ?array $post = null): string
    {
        $curl = curl_init($this->baseUrl . $path);
        if ($curl === false) {
            throw new RuntimeException('無法建立 localhost HTTP 請求。');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 40,
        ];
        if ($post !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($response) || $status !== 200) {
            throw new RuntimeException('localhost HTTP 請求失敗。');
        }

        return $response;
    }

    private function assertTestDbGate(): void
    {
        $appEnv = app_env('APP_ENV', '');
        $configuredDatabase = app_env('DB_DATABASE', '');
        $dbHost = app_env('DB_HOST', '');
        $actualDatabase = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();

        echo "APP_ENV={$appEnv}\n";
        echo "DB_DATABASE={$configuredDatabase}\n";
        echo "DB_HOST={$dbHost}\n";
        echo "SELECT_DATABASE={$actualDatabase}\n";

        if (!in_array($appEnv, ['testing', 'development'], true)) {
            throw new RuntimeException('Quick Entry check requires APP_ENV testing/development.');
        }
        if ($configuredDatabase !== 'personal_accounting_test') {
            throw new RuntimeException('Quick Entry check requires DB_DATABASE=personal_accounting_test.');
        }
        if ($actualDatabase !== 'personal_accounting_test') {
            throw new RuntimeException('Quick Entry check requires SELECT DATABASE()=personal_accounting_test.');
        }
    }

    /** @param array<string, mixed> $fields */
    private function insertTestParseLog(string $rawInput, string $type, array $fields): int
    {
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
            'raw_input' => $rawInput,
            'provider' => 'quick_entry_check',
            'model_name' => 'test-fixture',
            'parsed_type' => $type,
            'parsed_json' => json_encode(['type' => $type, 'fields' => $fields, 'warnings' => []], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'parse_status' => 'success',
            'duration_ms' => 0,
            'source' => 'quick_pwa',
            'user_name' => (string) app_env('APP_LOGIN_USERNAME', ''),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<int> $ids */
    private function deleteTestParseLogs(array $ids): void
    {
        foreach ($ids as $id) {
            $statement = $this->pdo->prepare('DELETE FROM ai_parse_logs WHERE id = :id');
            $statement->execute(['id' => $id]);
        }
    }

    private function activeIdByName(string $table, string $name): int
    {
        if (!in_array($table, ['payment_methods', 'accounts'], true)) {
            throw new InvalidArgumentException('Unsupported reference table');
        }
        $statement = $this->pdo->prepare(
            sprintf('SELECT id FROM `%s` WHERE name = :name AND is_active = 1 ORDER BY sort_order, id LIMIT 1', $table)
        );
        $statement->execute(['name' => $name]);
        $id = (int) $statement->fetchColumn();
        if ($id < 1) {
            throw new RuntimeException("找不到啟用中的測試參照資料：{$name}");
        }

        return $id;
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (QUICK_ENTRY_BUSINESS_TABLES as $table) {
            $counts[$table] = (int) $this->pdo->query(
                sprintf('SELECT COUNT(*) FROM `%s`', $table)
            )->fetchColumn();
        }

        return $counts;
    }

    private function logCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ai_parse_logs')->fetchColumn();
    }

    private function maxLinkId(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM ai_ledger_links')->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function linksSince(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM ai_ledger_links
             WHERE id > :id
             ORDER BY id'
        );
        $statement->execute(['id' => $id]);
        return $statement->fetchAll();
    }

    private function deleteLinksSince(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM ai_ledger_links WHERE id > :id');
        $statement->execute(['id' => $id]);
    }

    /** @param list<array<string, mixed>> $links @param array<string, list<mixed>> $expectedIds */
    private function assertTraceLinks(array $links, array $expectedIds): void
    {
        $expectedTypeByTable = [
            'expenses' => 'expense',
            'incomes' => 'income',
            'overtime_logs' => 'overtime',
            'leave_logs' => 'leave',
        ];
        $byTable = [];
        foreach ($links as $link) {
            $table = (string) $link['ledger_table'];
            $byTable[$table][] = $link;
            $this->assert((string) $link['source'] === 'quick_pwa', "trace link source 正確：{$table}");
            $this->assert((int) $link['ai_parse_log_id'] > 0, "trace link log id 存在：{$table}");
            $this->assert((int) $link['ledger_id'] > 0, "trace link ledger id 存在：{$table}");
            $this->assert((string) $link['raw_input_snapshot'] !== '', "trace link raw input snapshot 存在：{$table}");
            $this->assert((string) $link['parsed_json_snapshot'] !== '', "trace link parsed JSON snapshot 存在：{$table}");
            $this->assert(
                (string) ($link['parsed_type_snapshot'] ?? '') === ($expectedTypeByTable[$table] ?? ''),
                "trace link parsed type 與 ledger table 一致：{$table}"
            );
            $this->assert(
                in_array((string) $link['action'], ['created', 'updated'], true),
                "trace link action 正確：{$table}"
            );
        }

        foreach ($expectedIds as $table => $ids) {
            $tableLinks = $byTable[$table] ?? [];
            $this->assert(count($tableLinks) === count($ids), "trace link 數量正確：{$table}");
            foreach ($ids as $index => $id) {
                $this->assert(
                    isset($tableLinks[$index]) && (int) $tableLinks[$index]['ledger_id'] === (int) $id,
                    "trace link ledger id 對應正確：{$table}"
                );
            }
        }
    }

    /**
     * @param list<int> $testLogIds
     * @param list<array<string, mixed>> $expenseRows
     * @param list<array<string, mixed>> $incomeRows
     * @param array<string, mixed> $overtimeRow
     * @param array<string, mixed> $leaveRow
     */
    private function assertTraceDisplay(
        array $testLogIds,
        array $expenseRows,
        array $incomeRows,
        array $overtimeRow,
        array $leaveRow
    ): void {
        $aiLogs = $this->request('/ai_parse_logs.php?source=quick_pwa');
        $this->assert(
            $this->containsAll($aiLogs, [
                '寫入連結：支出 #' . (string) $expenseRows[0]['id'],
                '寫入連結：收入 #' . (string) $incomeRows[0]['id'],
                '寫入連結：加班 #' . (string) $overtimeRow['id'],
                '寫入連結：請假 #' . (string) $leaveRow['id'],
                '連結時間：',
            ]),
            'AI logs 頁顯示 ledger trace link'
        );

        $expenses = $this->request('/expenses.php');
        $this->assert(
            $this->containsAll($expenses, [
                'AI：Log #' . (string) $testLogIds[0],
                'AI：Log #' . (string) $testLogIds[1],
                '輸入：早餐80現金',
                '輸入：加油1000展示方式 B',
            ]),
            '支出頁顯示 AI trace'
        );

        $incomes = $this->request('/incomes.php');
        $this->assert(
            $this->containsAll($incomes, [
                'AI：Log #' . (string) $testLogIds[2],
                'AI：Log #' . (string) $testLogIds[3],
                '輸入：薪資41189',
                '輸入：薪水50000',
            ]),
            '收入頁顯示 AI trace'
        );

        $overtime = $this->request('/overtime.php');
        $this->assert(
            $this->containsAll($overtime, [
                'AI：Log #' . (string) $testLogIds[4],
                '輸入：今天加班3小時',
                '連結時間：',
            ]),
            '加班頁顯示 AI trace'
        );

        $leave = $this->request('/leave.php');
        $this->assert(
            $this->containsAll($leave, [
                'AI：Log #' . (string) $testLogIds[6],
                '輸入：請特休一天',
                'AI x2',
            ]),
            '請假頁顯示最新 AI trace 與多 link 數量'
        );
    }

    private function loginForAdminPages(): void
    {
        $username = (string) app_env('APP_LOGIN_USERNAME', '');
        $password = (string) app_env('APP_LOGIN_PASSWORD', '');
        if ($username === '' || $password === '') {
            throw new RuntimeException('後台 trace 顯示驗收需要 APP_LOGIN_USERNAME / APP_LOGIN_PASSWORD。');
        }

        $dashboard = $this->request('/login.php', [
            'username' => $username,
            'password' => $password,
            'return_to' => '/dashboard.php',
        ]);
        $this->assert(
            str_contains($dashboard, 'Dashboard')
                || str_contains($dashboard, '儀表板')
                || str_contains($dashboard, '主控台')
                || str_contains($dashboard, '登出'),
            '測試帳密可登入後台 trace 顯示頁'
        );
    }

    private function maxId(string $table): int
    {
        if (!in_array($table, ['expenses', 'incomes'], true)) {
            throw new InvalidArgumentException('Unsupported table');
        }

        return (int) $this->pdo->query(sprintf('SELECT COALESCE(MAX(id), 0) FROM `%s`', $table))->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function rowsForDate(string $table, string $dateColumn, string $date): array
    {
        $allowed = [
            'overtime_logs' => 'work_date',
            'leave_logs' => 'leave_date',
        ];
        if (($allowed[$table] ?? '') !== $dateColumn) {
            throw new InvalidArgumentException('Unsupported dated table');
        }
        $statement = $this->pdo->prepare(
            sprintf('SELECT * FROM `%s` WHERE `%s` = :date ORDER BY id', $table, $dateColumn)
        );
        $statement->execute(['date' => $date]);
        return $statement->fetchAll();
    }

    /** @param list<array<string, mixed>> $before */
    private function restoreDatedRows(string $table, string $dateColumn, string $date, array $before): void
    {
        $delete = $this->pdo->prepare(sprintf('DELETE FROM `%s` WHERE `%s` = :date', $table, $dateColumn));
        $delete->execute(['date' => $date]);

        if ($table === 'overtime_logs') {
            $statement = $this->pdo->prepare(
                'INSERT INTO overtime_logs
                    (id, work_date, overtime_hours, hours_134, hours_167, meal_fee, night_snack_fee,
                     note, raw_input, user_name, source, is_deleted, deleted_at, created_at, updated_at)
                 VALUES
                    (:id, :work_date, :overtime_hours, :hours_134, :hours_167, :meal_fee, :night_snack_fee,
                     :note, :raw_input, :user_name, :source, :is_deleted, :deleted_at, :created_at, :updated_at)'
            );
            foreach ($before as $row) {
                $statement->execute([
                    'id' => $row['id'],
                    'work_date' => $row['work_date'],
                    'overtime_hours' => $row['overtime_hours'],
                    'hours_134' => $row['hours_134'],
                    'hours_167' => $row['hours_167'],
                    'meal_fee' => $row['meal_fee'],
                    'night_snack_fee' => $row['night_snack_fee'],
                    'note' => $row['note'],
                    'raw_input' => $row['raw_input'],
                    'user_name' => $row['user_name'],
                    'source' => $row['source'],
                    'is_deleted' => $row['is_deleted'],
                    'deleted_at' => $row['deleted_at'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ]);
            }
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO leave_logs
                (id, leave_date, leave_type, leave_days, leave_hours, note, raw_input, user_name, source,
                 is_deleted, deleted_at, created_at, updated_at)
             VALUES
                (:id, :leave_date, :leave_type, :leave_days, :leave_hours, :note, :raw_input, :user_name, :source,
                 :is_deleted, :deleted_at, :created_at, :updated_at)'
        );
        foreach ($before as $row) {
            $statement->execute([
                'id' => $row['id'],
                'leave_date' => $row['leave_date'],
                'leave_type' => $row['leave_type'],
                'leave_days' => $row['leave_days'],
                'leave_hours' => $row['leave_hours'],
                'note' => $row['note'],
                'raw_input' => $row['raw_input'],
                'user_name' => $row['user_name'],
                'source' => $row['source'],
                'is_deleted' => $row['is_deleted'],
                'deleted_at' => $row['deleted_at'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ]);
        }
    }

    private function assert(bool $condition, string $label): void
    {
        if ($condition) {
            $this->passed++;
            echo "[PASS] $label\n";
            return;
        }

        $this->failed++;
        echo "[FAIL] $label\n";
    }

    /** @param list<string> $needles */
    private function containsAll(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (!str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }
}

try {
    exit((new QuickEntryCheck(app_db()))->run());
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] Quick entry 驗收失敗：' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
