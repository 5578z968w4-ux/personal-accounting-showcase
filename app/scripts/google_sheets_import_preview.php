<?php

declare(strict_types=1);

const GOOGLE_SHEETS_IMPORT_SOURCE = 'import_google_sheets';

/**
 * @return array<string, array<string, mixed>>
 */
function import_preview_type_configs(): array
{
    return [
        'expenses' => [
            'aliases' => ['expense', 'expenses', '支出'],
            'expected_file' => 'imports/google_sheets/expenses.csv',
            'required' => ['record_date', 'item', 'amount', 'payment_method'],
            'columns' => [
                'record_date' => ['日期', '支出日期', 'record_date', 'date'],
                'item' => ['項目', '品項', '內容', 'item', 'description', 'name'],
                'amount' => ['金額', 'amount', 'price', 'cost'],
                'payment_method' => ['支付', '付款方式', '支付方式', 'payment_method', 'method'],
                'category' => ['分類', 'category'],
                'raw_input' => ['備註', 'note', 'raw_input', 'memo'],
                'user_name' => ['使用者', 'user_name', 'user'],
            ],
            'ignored_columns' => [
                'legacy_month' => ['月份', 'month', 'legacy_month'],
                'legacy_metadata_time' => ['時間', 'time'],
                'legacy_metadata_person' => ['人', 'person'],
                'legacy_sort_key' => ['排序鍵', 'sort_key'],
            ],
        ],
        'incomes' => [
            'aliases' => ['income', 'incomes', '收入'],
            'expected_file' => 'imports/google_sheets/incomes.csv',
            'required' => ['record_date', 'source_name', 'amount'],
            'columns' => [
                'record_date' => ['日期', '收入日期', 'record_date', 'date'],
                'source_name' => ['來源', '收入來源', 'source_name', 'item', 'name'],
                'amount' => ['金額', 'amount'],
                'account_name' => ['帳戶', '收入帳戶', 'account', 'account_name'],
                'category' => ['分類', 'category'],
                'raw_input' => ['備註', 'note', 'raw_input', 'memo'],
                'user_name' => ['使用者', 'user_name', 'user'],
            ],
            'ignored_columns' => [
                'legacy_month' => ['月份', 'month', 'legacy_month'],
                'legacy_metadata_time' => ['時間', 'time'],
                'legacy_metadata_person' => ['人', 'person'],
                'legacy_blank' => ['', 'blank'],
            ],
        ],
        'overtime_logs' => [
            'aliases' => ['overtime', 'overtime_logs', '加班'],
            'expected_file' => 'imports/google_sheets/overtime_logs.csv',
            'required' => ['work_date', 'overtime_hours'],
            'columns' => [
                'work_date' => ['日期', '加班日期', 'work_date', 'date'],
                'overtime_hours' => ['加班時數', '時數', 'overtime_hours', 'hours'],
                'note' => ['備註', 'note', 'raw_input', 'memo'],
                'user_name' => ['使用者', 'user_name', 'user'],
            ],
            'ignored_columns' => [
                'legacy_system_time' => ['系統時間', 'system_time'],
                'legacy_bookkeeper' => ['記帳人', 'bookkeeper'],
            ],
        ],
        'leave_logs' => [
            'aliases' => ['leave', 'leave_logs', '請假'],
            'expected_file' => 'imports/google_sheets/leave_logs.csv',
            'required' => ['leave_date', 'leave_type'],
            'columns' => [
                'leave_date' => ['日期', '請假日期', 'leave_date', 'date'],
                'leave_type' => ['假別', '請假類型', 'leave_type', 'type'],
                'leave_days' => ['天', '請假天數', '天數', 'leave_days', 'days'],
                'leave_hours' => ['H', '請假時數', '時數', 'leave_hours', 'hours'],
                'note' => ['備註', 'note', 'raw_input', 'memo'],
                'user_name' => ['使用者', 'user_name', 'user'],
            ],
            'ignored_columns' => [
                'legacy_calculated_leave' => ['換算', 'calculated_leave', 'converted_leave'],
            ],
        ],
    ];
}

/**
 * @param array<int, string> $argv
 * @return array<string, string|bool>
 */
function import_preview_parse_args(array $argv): array
{
    $options = [
        'file' => '',
        'type' => '',
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if (str_starts_with($arg, '--file=')) {
            $options['file'] = substr($arg, strlen('--file='));
            continue;
        }

        if (str_starts_with($arg, '--type=')) {
            $options['type'] = substr($arg, strlen('--type='));
        }
    }

    return $options;
}

/**
 * @param array<string, array<string, mixed>> $configs
 */
function import_preview_normalize_type(string $type, array $configs): ?string
{
    $needle = import_preview_normalize_header($type);
    foreach ($configs as $name => $config) {
        foreach ($config['aliases'] as $alias) {
            if (import_preview_normalize_header((string) $alias) === $needle) {
                return $name;
            }
        }
    }

    return null;
}

function import_preview_normalize_header(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    $value = trim($value);
    $value = strtolower($value);
    $value = str_replace([' ', '-', '.', '　'], '_', $value);
    $value = preg_replace('/_+/', '_', $value) ?? $value;

    return trim($value, '_');
}

function import_preview_clean_cell(mixed $value): string
{
    $value = (string) $value;
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

    return trim($value);
}

function import_preview_safe_label(string $value): string
{
    $value = import_preview_clean_cell($value);
    if ($value === '') {
        return '(blank)';
    }

    if (preg_match('/https?:\/\//i', $value) === 1) {
        return '[redacted-url]';
    }

    if (preg_match('/(password|passwd|token|secret|api[_-]?key|authorization)/i', $value) === 1) {
        return '[redacted-sensitive-label]';
    }

    return $value;
}

/**
 * @param array<int, string> $headers
 * @param array<string, array<int, string>> $columnAliases
 * @return array<string, string>
 */
function import_preview_build_mapping(array $headers, array $columnAliases): array
{
    $normalizedHeaders = [];
    foreach ($headers as $header) {
        $normalizedHeaders[import_preview_normalize_header($header)] = $header;
    }

    $mapping = [];
    foreach ($columnAliases as $target => $aliases) {
        $mapping[$target] = '';
        foreach ($aliases as $alias) {
            $normalizedAlias = import_preview_normalize_header($alias);
            if (isset($normalizedHeaders[$normalizedAlias])) {
                $mapping[$target] = $normalizedHeaders[$normalizedAlias] === '' ? '(blank)' : $normalizedHeaders[$normalizedAlias];
                break;
            }
        }
    }

    return $mapping;
}

/**
 * @param array<string, string> $mapping
 * @param array<int, string> $required
 * @return array<int, string>
 */
function import_preview_missing_required(array $mapping, array $required): array
{
    $missing = [];
    foreach ($required as $field) {
        if (($mapping[$field] ?? '') === '') {
            $missing[] = $field;
        }
    }

    return $missing;
}

/**
 * @param array<int, string> $headers
 * @param array<int, string> $row
 * @param array<string, string> $mapping
 * @return array<string, string>
 */
function import_preview_sample_summary(array $headers, array $row, array $mapping): array
{
    $rowByHeader = [];
    foreach ($headers as $index => $header) {
        $rowByHeader[$header] = import_preview_clean_cell($row[$index] ?? '');
    }

    $nonEmpty = 0;
    foreach ($rowByHeader as $value) {
        if ($value !== '') {
            $nonEmpty++;
        }
    }

    $summary = [
        'columns' => (string) count($headers),
        'non_empty_columns' => (string) $nonEmpty,
    ];

    foreach ($mapping as $target => $sourceHeader) {
        if ($sourceHeader === '') {
            continue;
        }
        $value = $rowByHeader[$sourceHeader] ?? '';
        if ($value === '') {
            $summary[$target] = 'blank';
        } elseif (preg_match('/date$/', $target) === 1 || in_array($target, ['record_date', 'work_date', 'leave_date'], true)) {
            $summary[$target] = 'date_len_' . strlen($value);
        } elseif (preg_match('/(amount|hours|days)$/', $target) === 1) {
            $summary[$target] = 'number_len_' . strlen(str_replace(',', '', $value));
        } else {
            $summary[$target] = 'text_len_' . strlen($value);
        }
    }

    return $summary;
}

/**
 * @param array<string, array<string, mixed>> $configs
 */
function import_preview_print_usage(array $configs): void
{
    fwrite(STDOUT, "Google Sheets CSV import preview (read-only)\n\n");
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php app/scripts/google_sheets_import_preview.php --file=imports/google_sheets/expenses.csv --type=expenses\n\n");
    fwrite(STDOUT, "Supported types and expected files:\n");
    foreach ($configs as $type => $config) {
        fwrite(STDOUT, "  - {$type}: {$config['expected_file']}\n");
    }
}

/**
 * @param array<string, mixed> $config
 */
function import_preview_print_expected_fields(string $type, array $config): void
{
    fwrite(STDOUT, "Expected CSV for type {$type}:\n");
    fwrite(STDOUT, "  file: {$config['expected_file']}\n");
    fwrite(STDOUT, '  required fields: ' . implode(', ', $config['required']) . "\n");
    fwrite(STDOUT, "  possible headers:\n");
    foreach ($config['columns'] as $field => $aliases) {
        fwrite(STDOUT, '    - ' . $field . ': ' . implode(' / ', $aliases) . "\n");
    }
    if (($config['ignored_columns'] ?? []) !== []) {
        fwrite(STDOUT, "  ignored legacy headers:\n");
        foreach ($config['ignored_columns'] as $field => $aliases) {
            fwrite(STDOUT, '    - ' . $field . ': ' . implode(' / ', $aliases) . "\n");
        }
    }
}

/**
 * @param array<int, string> $argv
 */
function import_preview_main(array $argv): int
{
    $configs = import_preview_type_configs();
    $options = import_preview_parse_args($argv);

    if ($options['help'] === true || $options['file'] === '' || $options['type'] === '') {
        import_preview_print_usage($configs);
        if ($options['file'] === '' || $options['type'] === '') {
            fwrite(STDERR, "\nERROR: Missing required --file or --type.\n");
            return 1;
        }

        return 0;
    }

    $type = import_preview_normalize_type((string) $options['type'], $configs);
    if ($type === null) {
        fwrite(STDERR, 'ERROR: Unsupported type: ' . import_preview_safe_label((string) $options['type']) . "\n");
        fwrite(STDERR, 'Supported types: ' . implode(', ', array_keys($configs)) . "\n");
        return 1;
    }

    $file = (string) $options['file'];
    if (!is_file($file)) {
        fwrite(STDERR, 'ERROR: CSV file not found: ' . import_preview_safe_label($file) . "\n\n");
        import_preview_print_expected_fields($type, $configs[$type]);
        return 1;
    }

    $csv = new SplFileObject($file, 'r');
    $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

    $headers = null;
    $rowCount = 0;
    $samples = [];

    foreach ($csv as $row) {
        if ($row === [null] || $row === false) {
            continue;
        }
        $row = array_map('import_preview_clean_cell', is_array($row) ? $row : []);
        if ($row === [] || implode('', $row) === '') {
            continue;
        }

        if ($headers === null) {
            $headers = $row;
            continue;
        }

        $rowCount++;
        if (count($samples) < 3) {
            $samples[] = $row;
        }
    }

    if ($headers === null || $headers === [] || implode('', $headers) === '') {
        fwrite(STDERR, 'ERROR: CSV header is empty: ' . import_preview_safe_label($file) . "\n");
        return 1;
    }

    $mapping = import_preview_build_mapping($headers, $configs[$type]['columns']);
    $ignoredMapping = import_preview_build_mapping($headers, $configs[$type]['ignored_columns'] ?? []);
    $missing = import_preview_missing_required($mapping, $configs[$type]['required']);

    fwrite(STDOUT, "Google Sheets CSV import preview (read-only)\n");
    fwrite(STDOUT, 'File: ' . import_preview_safe_label($file) . "\n");
    fwrite(STDOUT, "Type: {$type}\n");
    fwrite(STDOUT, 'Planned source: ' . GOOGLE_SHEETS_IMPORT_SOURCE . "\n");
    fwrite(STDOUT, 'Headers: ' . implode(', ', array_map('import_preview_safe_label', $headers)) . "\n");
    fwrite(STDOUT, "Row count: {$rowCount}\n\n");

    fwrite(STDOUT, "Possible field mapping:\n");
    foreach ($mapping as $target => $sourceHeader) {
        $source = $sourceHeader !== '' ? import_preview_safe_label($sourceHeader) : '(not found)';
        fwrite(STDOUT, "  - {$target} <= {$source}\n");
    }

    fwrite(STDOUT, "\nIgnored legacy fields (not imported by preview):\n");
    $ignoredFound = false;
    foreach ($ignoredMapping as $target => $sourceHeader) {
        if ($sourceHeader === '') {
            continue;
        }
        $ignoredFound = true;
        fwrite(STDOUT, '  - ' . $target . ' <= ' . import_preview_safe_label($sourceHeader) . "\n");
    }
    if (!$ignoredFound) {
        fwrite(STDOUT, "  - none\n");
    }

    fwrite(STDOUT, "\nMissing required fields:\n");
    fwrite(STDOUT, $missing === [] ? "  - none\n" : '  - ' . implode("\n  - ", $missing) . "\n");

    fwrite(STDOUT, "\nSample summaries (first 3 rows, values redacted):\n");
    if ($samples === []) {
        fwrite(STDOUT, "  - none\n");
    } else {
        foreach ($samples as $index => $sample) {
            $summary = import_preview_sample_summary($headers, $sample, $mapping);
            $parts = [];
            foreach ($summary as $key => $value) {
                $parts[] = "{$key}={$value}";
            }
            fwrite(STDOUT, '  - row ' . ($index + 1) . ': ' . implode(', ', $parts) . "\n");
        }
    }

    fwrite(STDOUT, "\nNo database connection was opened. No data was written.\n");
    fwrite(STDOUT, "Encoding note: UTF-8 / UTF-8 BOM is supported. Convert Big5 CSV to UTF-8 before import preview.\n");

    return 0;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    exit(import_preview_main($argv));
}
