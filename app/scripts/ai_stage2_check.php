<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/env.php';
require_once dirname(__DIR__) . '/src/AiParseService.php';

const BUSINESS_TABLES = [
    'expenses',
    'incomes',
    'overtime_logs',
    'leave_logs',
];

final class InvalidJsonAcceptanceClient implements AiClientInterface
{
    public function generate(string $prompt, array $settings, array $responseSchema): array
    {
        return [
            'text' => 'not-json',
            'raw_response' => '{"test":"invalid-json"}',
            'duration_ms' => 1,
        ];
    }
}

final class Stage2Check
{
    private int $passed = 0;
    private int $failed = 0;

    /** @var array<string, int> */
    private array $businessCounts;

    /** @var array<string, mixed> */
    private array $settings;

    public function __construct(private readonly PDO $pdo)
    {
        $settings = $pdo->query('SELECT * FROM ai_settings WHERE id = 1')->fetch();
        if (!is_array($settings)) {
            throw new RuntimeException('ai_settings id=1 does not exist');
        }

        $this->settings = $settings;
        $this->settings['is_enabled'] = 1;
        $this->settings['provider'] = 'gemini';
        $this->settings['save_raw_response'] = 0;
        $this->settings['allow_expense'] = 1;
        $this->settings['allow_income'] = 1;
        $this->settings['allow_overtime'] = 1;
        $this->settings['allow_leave'] = 1;
        $this->businessCounts = $this->tableCounts();
    }

    public function run(): int
    {
        echo "AI Stage 2 Acceptance Check\n";
        echo "===========================\n";

        $initialLogCount = $this->logCount();

        $this->checkMissingKey();
        $this->checkConfiguredKey();
        $this->checkInvalidJson();
        $this->checkBusinessTables('所有解析完成後業務表筆數不變');

        $finalLogCount = $this->logCount();
        $expectedNewLogs = 6;
        $this->assert(
            $finalLogCount - $initialLogCount === $expectedNewLogs,
            'ai_parse_logs 新增 6 筆驗收紀錄',
            sprintf('expected=%d actual=%d', $expectedNewLogs, $finalLogCount - $initialLogCount)
        );

        $this->checkSecretNotLogged();

        echo "===========================\n";
        echo sprintf("PASS: %d\nFAIL: %d\n", $this->passed, $this->failed);
        echo $this->failed === 0 ? "RESULT: PASS\n" : "RESULT: FAIL\n";

        return $this->failed === 0 ? 0 : 1;
    }

    private function checkMissingKey(): void
    {
        $beforeLogs = $this->logCount();
        $service = $this->service(new AiClientFactory([
            'gemini' => new GeminiAiClient(''),
        ]));
        $errorCode = '';

        try {
            $service->preview('早餐 80 現金', 'expense', $this->settings, 'stage2_check', 'acceptance_check');
        } catch (AiParseException $exception) {
            $errorCode = $exception->errorCode();
        }

        $this->assert(
            $errorCode === 'missing_api_key',
            'GEMINI_API_KEY 未設定時拒絕解析請求',
            $errorCode === '' ? 'request was not rejected' : 'error_code=' . $errorCode
        );
        $this->assert(
            $this->logCount() === $beforeLogs + 1,
            '缺金鑰測試只新增一筆 ai_parse_logs',
            'unexpected log count'
        );
        $this->checkBusinessTables('缺金鑰測試未修改業務表');
    }

    private function checkConfiguredKey(): void
    {
        $apiKey = $this->configuredApiKey();
        $modelName = trim((string) ($this->settings['model_name'] ?? ''));

        $this->assert($apiKey !== '', 'GEMINI_API_KEY 已設定', 'missing or placeholder key');
        $this->assert($modelName !== '', 'ai_settings 已設定模型名稱', 'model_name is empty');
        if ($apiKey === '' || $modelName === '') {
            foreach (['支出', '收入', '加班', '請假'] as $label) {
                $this->fail($label . ' Gemini 真實解析', 'configuration unavailable');
            }
            return;
        }

        $cases = [
            ['支出', '早餐 80 現金', 'expense'],
            ['收入', '薪資 41189', 'income'],
            ['加班', '今天加班3小時', 'overtime'],
            ['請假', '明天特休1天', 'leave'],
        ];

        $service = $this->service(new AiClientFactory());
        foreach ($cases as [$label, $input, $type]) {
            $beforeLogs = $this->logCount();
            try {
                $preview = $service->preview(
                    $input,
                    $type,
                    $this->settings,
                    'stage2_check',
                    'acceptance_check'
                );
                $this->assert(
                    ($preview['status'] ?? '') === 'success' && ($preview['type'] ?? '') === $type,
                    $label . ' Gemini 真實解析成功',
                    'unexpected preview result'
                );
            } catch (AiParseException $exception) {
                $this->fail(
                    $label . ' Gemini 真實解析成功',
                    $exception->errorCode() . ': ' . $exception->getMessage()
                );
            }

            $this->assert(
                $this->logCount() === $beforeLogs + 1,
                $label . ' 解析只新增一筆 ai_parse_logs',
                'unexpected log count'
            );
            $this->checkBusinessTables($label . ' 解析未修改業務表');
        }
    }

    private function checkInvalidJson(): void
    {
        $beforeLogs = $this->logCount();
        $service = $this->service(new AiClientFactory([
            'gemini' => new InvalidJsonAcceptanceClient(),
        ]));
        $errorCode = '';
        $errorMessage = '';

        try {
            $service->preview('早餐 80 現金', 'expense', $this->settings, 'stage2_check', 'acceptance_check');
        } catch (AiParseException $exception) {
            $errorCode = $exception->errorCode();
            $errorMessage = $exception->getMessage();
        }

        $safeMessage = $errorMessage === 'AI 回傳的 JSON 格式不正確。'
            && !str_contains($errorMessage, 'not-json')
            && !str_contains(strtolower($errorMessage), 'stack trace');

        $this->assert(
            $errorCode === 'invalid_json' && $safeMessage,
            '錯誤 JSON 顯示安全錯誤',
            'error_code=' . $errorCode
        );
        $this->assert(
            $this->logCount() === $beforeLogs + 1,
            '錯誤 JSON 只新增一筆 ai_parse_logs',
            'unexpected log count'
        );

        $row = $this->pdo->query(
            "SELECT parse_status, error_code, error_message, ai_response
             FROM ai_parse_logs
             WHERE source = 'stage2_check'
             ORDER BY id DESC LIMIT 1"
        )->fetch();
        $this->assert(
            is_array($row)
                && $row['parse_status'] === 'invalid_json'
                && $row['error_code'] === 'invalid_json'
                && $row['ai_response'] === null,
            '錯誤 JSON 紀錄不保存完整原始回應',
            'unexpected ai log content'
        );
        $this->checkBusinessTables('錯誤 JSON 測試未修改業務表');
    }

    private function checkSecretNotLogged(): void
    {
        $apiKey = $this->configuredApiKey();
        if ($apiKey === '') {
            $this->fail('app logs / ai logs 不含 GEMINI_API_KEY', 'configured key unavailable');
            return;
        }

        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM ai_parse_logs
             WHERE LOCATE(:api_key, CONCAT_WS(
                 '', raw_input, ai_response, provider, model_name, parsed_type,
                 parsed_json, parse_status, error_code, error_message, source, user_name
             )) > 0"
        );
        $statement->execute(['api_key' => $apiKey]);
        $databaseLeaks = (int) $statement->fetchColumn();

        $fileLeaks = [];
        $logFiles = array_merge(
            glob('/var/log/php/*') ?: [],
            glob('/var/log/apache2/*') ?: []
        );
        foreach ($logFiles as $logFile) {
            if (!is_file($logFile) || !is_readable($logFile)) {
                continue;
            }
            $contents = file_get_contents($logFile);
            if (is_string($contents) && str_contains($contents, $apiKey)) {
                $fileLeaks[] = basename($logFile);
            }
        }

        $this->assert(
            $databaseLeaks === 0 && $fileLeaks === [],
            'app logs / ai logs 不含 GEMINI_API_KEY',
            sprintf('database_leaks=%d file_leaks=%s', $databaseLeaks, implode(',', $fileLeaks))
        );
    }

    private function service(AiClientFactory $factory): AiParseService
    {
        return new AiParseService(
            $this->pdo,
            $factory,
            new AiPromptBuilder(),
            new AiResponseValidator(),
            new AiBusinessValidator($this->pdo),
            new AiInputDateResolver()
        );
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (BUSINESS_TABLES as $table) {
            $counts[$table] = (int) $this->pdo->query(
                sprintf('SELECT COUNT(*) FROM `%s`', $table)
            )->fetchColumn();
        }
        return $counts;
    }

    private function checkBusinessTables(string $label): void
    {
        $current = $this->tableCounts();
        $this->assert(
            $current === $this->businessCounts,
            $label,
            'before=' . json_encode($this->businessCounts) . ' after=' . json_encode($current)
        );
    }

    private function logCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ai_parse_logs')->fetchColumn();
    }

    private function configuredApiKey(): string
    {
        $apiKey = trim((string) app_env('GEMINI_API_KEY', ''));
        return str_starts_with($apiKey, 'change_this_') ? '' : $apiKey;
    }

    private function assert(bool $condition, string $label, string $detail = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo '[PASS] ' . $label . "\n";
            return;
        }

        $this->fail($label, $detail);
    }

    private function fail(string $label, string $detail): void
    {
        $this->failed++;
        echo '[FAIL] ' . $label;
        if ($detail !== '') {
            echo ' - ' . $detail;
        }
        echo "\n";
    }
}

try {
    $checker = new Stage2Check(app_db());
    exit($checker->run());
} catch (Throwable $exception) {
    echo '[FAIL] 驗收腳本無法執行 - ' . $exception->getMessage() . "\n";
    echo "RESULT: FAIL\n";
    exit(1);
}
