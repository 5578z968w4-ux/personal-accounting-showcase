<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/env.php';
require_once dirname(__DIR__) . '/src/AccountingMonthService.php';

const PREVIEW_BUSINESS_TABLES = [
    'expenses',
    'incomes',
    'overtime_logs',
    'leave_logs',
];

final class AiPreviewCheck
{
    private int $passed = 0;
    private int $failed = 0;
    private string $cookieFile;

    /** @var array<string, int> */
    private array $initialCounts;

    public function __construct(private readonly PDO $pdo)
    {
        $cookieFile = tempnam(sys_get_temp_dir(), 'ai-preview-cookie-');
        if ($cookieFile === false) {
            throw new RuntimeException('無法建立測試 cookie 檔案。');
        }

        $this->cookieFile = $cookieFile;
        $this->initialCounts = $this->tableCounts();
    }

    public function __destruct()
    {
        if (is_file($this->cookieFile)) {
            unlink($this->cookieFile);
        }
    }

    public function run(): int
    {
        echo "AI Preview Acceptance Check\n";
        echo "===========================\n";

        if (!$this->login()) {
            $this->fail('登入 AI 快速輸入頁', 'localhost login failed');
            return $this->finish();
        }
        $this->pass('登入 AI 快速輸入頁');

        $initialLogCount = $this->logCount();
        $this->checkExpense();
        $this->checkIncome();
        $this->checkOvertimeConflict();
        $this->checkLeaveConflict();
        $this->assert(
            $this->logCount() === $initialLogCount + 4,
            '四次預覽只新增四筆 ai_parse_logs',
            'unexpected ai_parse_logs count'
        );
        $this->assert(
            $this->tableCounts() === $this->initialCounts,
            '解析前後四張業務表筆數不變',
            json_encode($this->tableCounts(), JSON_UNESCAPED_UNICODE)
        );
        $this->checkMobileCss();

        return $this->finish();
    }

    private function checkExpense(): void
    {
        $html = $this->preview('6/10 早餐 80 現金', 'expense');
        $method = $this->pdo->query(
            "SELECT settlement_start_day, settlement_end_day
             FROM payment_methods WHERE name = '現金' AND is_active = 1 LIMIT 1"
        )->fetch();
        $expectedMonth = is_array($method)
            ? AccountingMonthService::calculate(
                date('Y') . '-06-10',
                (int) $method['settlement_start_day'],
                (int) $method['settlement_end_day']
            )
            : '';

        $this->assert($this->hasEditableInput($html, '日期', date('Y') . '-06-10'), '支出日期欄位可編輯');
        $this->assert($this->hasEditableInput($html, '項目', '早餐'), '支出項目欄位對應正確');
        $this->assert($this->hasEditableInput($html, '金額', '80'), '支出金額欄位對應正確');
        $this->assert(
            str_contains($html, '已比對後台付款方式') && $this->hasSelectedOption($html, '現金'),
            '支出付款方式比對清楚'
        );
        $this->assert(
            $expectedMonth !== '' && $this->hasReadonlyInput($html, '帳單月份', $expectedMonth),
            '支出帳單月份由 AccountingMonthService 顯示'
        );
        $this->checkCommonSafety($html, '支出');
    }

    private function checkIncome(): void
    {
        $html = $this->preview('6/10 薪資 41189 存入現金帳戶', 'income');

        $this->assert($this->hasEditableInput($html, '日期', date('Y') . '-06-10'), '收入日期欄位可編輯');
        $this->assert($this->hasEditableInput($html, '收入來源', '薪資'), '收入來源欄位對應正確');
        $this->assert($this->hasEditableInput($html, '金額', '41189'), '收入金額欄位對應正確');
        $this->assert(
            str_contains($html, '已比對後台帳戶') && $this->hasSelectedOption($html, '現金'),
            '收入帳戶比對清楚'
        );
        $this->checkCommonSafety($html, '收入');
    }

    private function checkOvertimeConflict(): void
    {
        $html = $this->preview('2026/6/8 加班3小時', 'overtime');

        $this->assert($this->hasEditableInput($html, '加班日期', '2026-06-08'), '加班日期欄位可編輯');
        $this->assert($this->hasEditableInput($html, '加班時數', '3'), '加班時數欄位對應正確');
        $this->assert(
            str_contains($html, '該日期已有加班紀錄；本階段只提示，不會更新資料。'),
            '同日加班只顯示警告'
        );
        $this->checkCommonSafety($html, '加班');
    }

    private function checkLeaveConflict(): void
    {
        $html = $this->preview('2026/6/8 特休2小時', 'leave');

        $this->assert($this->hasEditableInput($html, '請假日期', '2026-06-08'), '請假日期欄位可編輯');
        $this->assert($this->hasEditableInput($html, '請假時數', '2'), '請假時數欄位對應正確');
        $this->assert(
            str_contains($html, '已比對後台假別') && $this->hasSelectedOption($html, '特休'),
            '請假假別比對清楚'
        );
        $this->assert(
            str_contains($html, '該日期已有請假紀錄；本階段只提示，不會更新資料。'),
            '同日請假只顯示警告'
        );
        $this->checkCommonSafety($html, '請假');
    }

    private function checkCommonSafety(string $html, string $label): void
    {
        $apiKey = trim((string) app_env('GEMINI_API_KEY', ''));
        $this->assert(
            str_contains($html, '可編輯預覽')
                && str_contains($html, '本階段沒有送出或儲存功能'),
            $label . ' 預覽明確標示可編輯但不儲存'
        );
        $this->assert(
            !str_contains($html, '付款方式 ID')
                && !str_contains($html, '帳戶 ID')
                && ($apiKey === '' || !str_contains($html, $apiKey)),
            $label . ' 預覽不顯示內部 ID 或 API Key'
        );
    }

    private function checkMobileCss(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/public/style.css');
        $valid = is_string($css)
            && str_contains($css, '@media (max-width: 700px)')
            && preg_match(
                '/@media \(max-width: 700px\).*?\.preview-edit-grid\s*\{[^}]*grid-template-columns:\s*1fr/s',
                $css
            ) === 1
            && preg_match(
                '/@media \(max-width: 700px\).*?\.preview-field\s*\{[^}]*padding:\s*10px/s',
                $css
            ) === 1;

        $this->assert($valid, '手機版預覽改為單欄並縮短欄位間距');
    }

    private function login(): bool
    {
        $response = $this->request('/login.php', [
            'username' => (string) app_env('APP_LOGIN_USERNAME', ''),
            'password' => (string) app_env('APP_LOGIN_PASSWORD', ''),
        ]);

        return str_contains($response, '個人自動記帳系統')
            && !str_contains($response, '帳號或密碼錯誤');
    }

    private function preview(string $input, string $type): string
    {
        return $this->request('/ai_entry.php', [
            'input_text' => $input,
            'requested_type' => $type,
        ]);
    }

    /** @param array<string, string> $post */
    private function request(string $path, array $post): string
    {
        $curl = curl_init('http://127.0.0.1' . $path);
        if ($curl === false) {
            throw new RuntimeException('無法建立 localhost HTTP 請求。');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 40,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!is_string($response) || $status !== 200) {
            throw new RuntimeException('localhost HTTP 請求失敗。');
        }

        return $response;
    }

    private function hasEditableInput(string $html, string $label, string $value): bool
    {
        $pattern = sprintf(
            '/<label class="preview-field">%s\s*<input(?![^>]*readonly)[^>]*value="%s"[^>]*>/u',
            preg_quote($label, '/'),
            preg_quote(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), '/')
        );

        return preg_match($pattern, $html) === 1;
    }

    private function hasReadonlyInput(string $html, string $label, string $value): bool
    {
        $pattern = sprintf(
            '/<label class="preview-field">%s\s*<input[^>]*value="%s"[^>]*readonly>/u',
            preg_quote($label, '/'),
            preg_quote(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), '/')
        );

        return preg_match($pattern, $html) === 1;
    }

    private function hasSelectedOption(string $html, string $label): bool
    {
        return preg_match(
            '/<option[^>]*selected[^>]*>' . preg_quote($label, '/') . '<\/option>/u',
            $html
        ) === 1;
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (PREVIEW_BUSINESS_TABLES as $table) {
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

    private function assert(bool $condition, string $label, string $detail = ''): void
    {
        if ($condition) {
            $this->pass($label);
            return;
        }

        $this->fail($label, $detail);
    }

    private function pass(string $label): void
    {
        $this->passed++;
        echo "[PASS] $label\n";
    }

    private function fail(string $label, string $detail): void
    {
        $this->failed++;
        echo "[FAIL] $label";
        echo $detail === '' ? "\n" : " ($detail)\n";
    }

    private function finish(): int
    {
        echo "===========================\n";
        echo sprintf("PASS: %d\nFAIL: %d\n", $this->passed, $this->failed);
        echo $this->failed === 0 ? "RESULT: PASS\n" : "RESULT: FAIL\n";

        return $this->failed === 0 ? 0 : 1;
    }
}

try {
    exit((new AiPreviewCheck(app_db()))->run());
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] 預覽驗收腳本執行失敗：' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
