<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/env.php';
require_once dirname(__DIR__) . '/src/QuickEntryApiService.php';

final class ShortcutOvertimeCheck
{
    private int $passed = 0;
    private int $failed = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(): int
    {
        echo "iOS Shortcut Overtime Focused Check\n";
        echo "===================================\n";
        $this->assertTestDbGate();

        $cases = [
            ['text' => '加班3小時', 'hours' => 3.0],
            ['text' => '加班 3 小時', 'hours' => 3.0],
            ['text' => '加班2小時', 'hours' => 2.0],
            ['text' => '加班 2 小時', 'hours' => 2.0],
        ];
        $workDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d');
        $overtimeMaxId = $this->maxId('overtime_logs');
        $logMaxId = $this->maxId('ai_parse_logs');
        $linkMaxId = $this->maxId('ai_ledger_links');
        $existingOvertime = $this->overtimeRowsForDate($workDate);

        try {
            $settings = $this->runtimeAiSettings($this->aiSettings());
            $this->assert(strtolower((string) ($settings['provider'] ?? '')) === 'gemini', 'AI provider is Gemini');
            $this->assert((int) ($settings['is_enabled'] ?? 0) === 1, 'AI parsing is enabled');
            $this->assert((int) ($settings['allow_overtime'] ?? 0) === 1, 'AI parsing allows overtime');

            $service = new QuickEntryApiService($this->pdo);
            foreach ($cases as $index => $case) {
                $text = $case['text'];
                $hours = $case['hours'];
                $clientRequestId = 'legacy_overtime_' . date('YmdHis') . '_' . (string) $index;
                $response = $service->handle([
                    'text' => $text,
                    'client_request_id' => $clientRequestId,
                ], $settings, 'shortcut-overtime-check');

                $this->assert(($response['ok'] ?? false) === true, "{$text} API response ok=true");
                $this->assert(($response['summary']['type'] ?? '') === 'overtime', "{$text} response summary type=overtime");
                $this->assert(in_array(($response['summary']['action'] ?? ''), ['created', 'updated'], true), "{$text} response action is valid");
                $this->assert((float) ($response['summary']['overtime_hours'] ?? 0) === $hours, "{$text} response summary overtime_hours matches");
                $this->assert(($response['summary']['unit'] ?? '') === '小時', "{$text} response summary unit is hours");
                $this->assert(($response['client_request_id'] ?? '') === $clientRequestId, "{$text} response echoes client_request_id");
                $responseJson = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $this->assert(!str_contains($responseJson, 'ledger_id'), "{$text} response does not expose ledger id");
                $this->assert(!str_contains($responseJson, 'ai_ledger_link_id'), "{$text} response does not expose trace link id");
                $this->assert(!str_contains($responseJson, 'trace_link_id'), "{$text} response does not expose trace link id alias");

                $rows = $this->overtimeRowsForDate($workDate);
                $this->assert(count($rows) === 1, "{$text} overtime_logs keeps one row for the same date");
                $overtime = $rows[0] ?? null;
                $this->assert(is_array($overtime), "{$text} overtime row exists");
                if (is_array($overtime)) {
                    $this->assert((float) $overtime['overtime_hours'] === $hours, "{$text} overtime_logs overtime_hours matches");
                    $this->assert((string) $overtime['source'] === QuickEntryApiService::SOURCE, "{$text} overtime_logs source=ios_shortcut");
                    $this->assert((string) $overtime['raw_input'] === $text, "{$text} overtime_logs raw_input matches Shortcut text");
                }
            }

            $rawInputs = array_column($cases, 'text');
            $placeholders = implode(', ', array_fill(0, count($rawInputs), '?'));
            $logs = $this->rowsSince(
                'ai_parse_logs',
                $logMaxId,
                sprintf('raw_input IN (%s) AND source = ?', $placeholders),
                array_merge($rawInputs, [QuickEntryApiService::SOURCE])
            );
            $this->assert(count($logs) === count($cases), 'ai_parse_logs has one successful Shortcut overtime parse per legacy phrase');
            foreach ($logs as $log) {
                $this->assert((string) $log['parse_status'] === 'success', 'ai_parse_logs success');
                $this->assert((string) $log['parsed_type'] === 'overtime', 'ai_parse_logs parsed_type=overtime');
                $this->assert((string) $log['source'] === QuickEntryApiService::SOURCE, 'ai_parse_logs source=ios_shortcut');
                $this->assert(strtolower((string) $log['provider']) === 'gemini', 'ai_parse_logs provider=gemini');
                $expectedHours = $this->expectedHoursForText((string) $log['raw_input'], $cases);
                $this->assert(str_contains((string) $log['parsed_json'], '"overtime_hours":' . $expectedHours), 'ai_parse_logs parsed_json contains expected overtime_hours');
            }

            $links = $this->rowsSince(
                'ai_ledger_links',
                $linkMaxId,
                sprintf('raw_input_snapshot IN (%s) AND source = ?', $placeholders),
                array_merge($rawInputs, [QuickEntryApiService::SOURCE])
            );
            $this->assert(count($links) === count($cases), 'ai_ledger_links has one Shortcut overtime link per legacy phrase');
            foreach ($links as $link) {
                $this->assert((string) $link['ledger_table'] === 'overtime_logs', 'ai_ledger_links ledger_table=overtime_logs');
                $this->assert((string) $link['source'] === QuickEntryApiService::SOURCE, 'ai_ledger_links source=ios_shortcut');
                $this->assert((string) $link['parsed_type_snapshot'] === 'overtime', 'ai_ledger_links parsed_type_snapshot=overtime');
            }
        } finally {
            $this->cleanup($workDate, $overtimeMaxId, $logMaxId, $linkMaxId, $existingOvertime, array_column($cases, 'text'));
        }

        $this->assert(
            count($this->rowsSince('ai_parse_logs', $logMaxId, 'source = ?', [QuickEntryApiService::SOURCE])) === 0,
            'test ai_parse_logs cleaned up'
        );
        $this->assert(
            count($this->rowsSince('ai_ledger_links', $linkMaxId, 'source = ?', [QuickEntryApiService::SOURCE])) === 0,
            'test ai_ledger_links cleaned up'
        );
        $this->assert($this->overtimeStateMatches($workDate, $existingOvertime), 'test overtime row restored or cleaned up');

        echo "===================================\n";
        echo sprintf("PASS: %d\nFAIL: %d\n", $this->passed, $this->failed);
        echo $this->failed === 0 ? "RESULT: PASS\n" : "RESULT: FAIL\n";

        return $this->failed === 0 ? 0 : 1;
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
            throw new RuntimeException('Shortcut overtime check requires APP_ENV testing/development.');
        }
        if ($configuredDatabase !== 'personal_accounting_test') {
            throw new RuntimeException('Shortcut overtime check requires DB_DATABASE=personal_accounting_test.');
        }
        if ($actualDatabase !== 'personal_accounting_test') {
            throw new RuntimeException('Shortcut overtime check requires SELECT DATABASE()=personal_accounting_test.');
        }
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
        $provider = trim((string) app_env('SHORTCUT_OVERTIME_CHECK_PROVIDER', ''));
        if ($provider !== '') {
            $settings['provider'] = $provider;
        }

        $modelName = trim((string) app_env('SHORTCUT_OVERTIME_CHECK_MODEL_NAME', ''));
        if ($modelName !== '') {
            $settings['model_name'] = $modelName;
        }

        if ((string) app_env('SHORTCUT_OVERTIME_CHECK_ENABLE_AI', '') === '1') {
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

    private function maxId(string $table): int
    {
        if (!in_array($table, ['overtime_logs', 'ai_parse_logs', 'ai_ledger_links'], true)) {
            throw new InvalidArgumentException('Unsupported table.');
        }

        return (int) $this->pdo->query(sprintf('SELECT COALESCE(MAX(id), 0) FROM `%s`', $table))->fetchColumn();
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rowsSince(string $table, int $maxId, string $where, array $params): array
    {
        if (!in_array($table, ['ai_parse_logs', 'ai_ledger_links'], true)) {
            throw new InvalidArgumentException('Unsupported table.');
        }
        $statement = $this->pdo->prepare(
            sprintf('SELECT * FROM `%s` WHERE id > ? AND %s ORDER BY id', $table, $where)
        );
        $statement->execute(array_merge([$maxId], $params));

        return $statement->fetchAll();
    }

    /**
     * @param list<array<string, mixed>> $existingOvertime
     */
    private function cleanup(
        string $workDate,
        int $overtimeMaxId,
        int $logMaxId,
        int $linkMaxId,
        array $existingOvertime,
        array $rawInputs
    ): void {
        $placeholders = implode(', ', array_fill(0, count($rawInputs), '?'));
        $statement = $this->pdo->prepare(
            'DELETE FROM ai_ledger_links
             WHERE id > ? AND source = ? AND raw_input_snapshot IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$linkMaxId, QuickEntryApiService::SOURCE], $rawInputs));

        $statement = $this->pdo->prepare(
            'DELETE FROM ai_parse_logs
             WHERE id > ? AND source = ? AND raw_input IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$logMaxId, QuickEntryApiService::SOURCE], $rawInputs));

        if ($existingOvertime === []) {
            $statement = $this->pdo->prepare(
                'DELETE FROM overtime_logs
                 WHERE id > ? AND work_date = ? AND source = ? AND raw_input IN (' . $placeholders . ')'
            );
            $statement->execute(array_merge([$overtimeMaxId, $workDate, QuickEntryApiService::SOURCE], $rawInputs));
            return;
        }

        $this->restoreOvertimeRow($existingOvertime[0]);
    }

    /** @param list<array{text: string, hours: float}> $cases */
    private function expectedHoursForText(string $text, array $cases): string
    {
        foreach ($cases as $case) {
            if ($case['text'] === $text) {
                return rtrim(rtrim((string) $case['hours'], '0'), '.');
            }
        }

        throw new RuntimeException('Unexpected overtime text in check result.');
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

exit((new ShortcutOvertimeCheck(app_db()))->run());
