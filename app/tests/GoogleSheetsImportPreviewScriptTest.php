<?php

declare(strict_types=1);

$appRoot = dirname(__DIR__);
$script = $appRoot . '/scripts/google_sheets_import_preview.php';
$fixtures = [
    'expenses' => $appRoot . '/tests/fixtures/google_sheets_expenses_sample.csv',
    'incomes' => $appRoot . '/tests/fixtures/google_sheets_incomes_sample.csv',
    'overtime_logs' => $appRoot . '/tests/fixtures/google_sheets_overtime_logs_sample.csv',
    'leave_logs' => $appRoot . '/tests/fixtures/google_sheets_leave_logs_sample.csv',
];
$missing = $appRoot . '/tests/fixtures/missing_google_sheets_sample.csv';

function preview_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        exit(1);
    }
}

/**
 * @param array<int, string> $args
 * @return array{exit_code: int, output: string}
 */
function preview_test_run(array $args): array
{
    $command = array_merge([PHP_BINARY], $args);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);
    preview_test_assert(is_resource($process), 'Unable to start preview script.');

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'output' => (string) $stdout . (string) $stderr,
    ];
}

$missingFile = preview_test_run([$script, '--file=' . $missing, '--type=expenses']);
preview_test_assert($missingFile['exit_code'] !== 0, 'Missing file should fail.');
preview_test_assert(str_contains($missingFile['output'], 'CSV file not found'), 'Missing file message should be clear.');
preview_test_assert(str_contains($missingFile['output'], 'required fields'), 'Missing file output should list expected fields.');

$unsupportedType = preview_test_run([$script, '--file=' . $fixtures['expenses'], '--type=unknown']);
preview_test_assert($unsupportedType['exit_code'] !== 0, 'Unsupported type should fail.');
preview_test_assert(str_contains($unsupportedType['output'], 'Unsupported type'), 'Unsupported type message should be clear.');

$cases = [
    'expenses' => [
        'contains' => [
            'Row count: 2',
            'record_date <= 日期',
            'item <= 項目',
            'amount <= 金額',
            'payment_method <= 支付',
            'category <= 分類',
            'legacy_month <= 月份',
            'legacy_metadata_time <= 時間',
            'legacy_metadata_person <= 人',
            'legacy_sort_key <= 排序鍵',
        ],
        'not_contains' => ['去識別早餐', 'fixture-expense-note'],
    ],
    'incomes' => [
        'contains' => [
            'Row count: 2',
            'record_date <= 日期',
            'source_name <= 來源',
            'amount <= 金額',
            'account_name <= 帳戶',
            'category <= 分類',
            'legacy_month <= 月份',
            'legacy_metadata_time <= 時間',
            'legacy_metadata_person <= 人',
            'legacy_blank <= (blank)',
        ],
        'not_contains' => ['去識別薪資', 'fixture-income-note'],
    ],
    'overtime_logs' => [
        'contains' => [
            'Row count: 2',
            'work_date <= 日期',
            'overtime_hours <= 加班時數',
            'legacy_system_time <= 系統時間',
            'legacy_bookkeeper <= 記帳人',
        ],
        'not_contains' => ['fixture-overtime-person'],
    ],
    'leave_logs' => [
        'contains' => [
            'Row count: 2',
            'leave_date <= 日期',
            'leave_type <= 假別',
            'leave_days <= 天',
            'leave_hours <= H',
            'note <= 備註',
            'legacy_calculated_leave <= 換算',
        ],
        'not_contains' => ['fixture-leave-note'],
    ],
];

foreach ($cases as $type => $case) {
    $sample = preview_test_run([$script, '--file=' . $fixtures[$type], '--type=' . $type]);
    preview_test_assert($sample['exit_code'] === 0, $type . ' fixture should pass.');
    preview_test_assert(str_contains($sample['output'], 'Missing required fields:'), $type . ' should report missing field section.');
    preview_test_assert(str_contains($sample['output'], '- none'), $type . ' should not miss required fields.');
    preview_test_assert(str_contains($sample['output'], 'Sample summaries'), $type . ' should show redacted sample summaries.');
    preview_test_assert(str_contains($sample['output'], 'No database connection was opened. No data was written.'), 'Script must state read-only DB behavior.');

    foreach ($case['contains'] as $needle) {
        preview_test_assert(str_contains($sample['output'], $needle), $type . ' output should contain: ' . $needle);
    }
    foreach ($case['not_contains'] as $needle) {
        preview_test_assert(!str_contains($sample['output'], $needle), $type . ' output must not print raw sample text: ' . $needle);
    }
}

echo "[PASS] GoogleSheetsImportPreviewScriptTest\n";
