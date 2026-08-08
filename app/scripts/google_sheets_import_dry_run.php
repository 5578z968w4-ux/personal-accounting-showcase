<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/AccountingMonthService.php';
require_once __DIR__ . '/google_sheets_import_preview.php';

const GOOGLE_SHEETS_IMPORT_DRY_RUN_REPORT = 'docs/testing/20260627_google_sheets_import_test_db_dry_run.md';

/**
 * @return array<string, string|bool>
 */
function google_sheets_import_dry_run_parse_args(array $argv): array
{
    $options = [
        'base_dir' => dirname(__DIR__, 2),
        'report' => GOOGLE_SHEETS_IMPORT_DRY_RUN_REPORT,
        'reset_source' => true,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--no-reset-source') {
            $options['reset_source'] = false;
            continue;
        }
        if (str_starts_with($arg, '--base-dir=')) {
            $options['base_dir'] = substr($arg, strlen('--base-dir='));
            continue;
        }
        if (str_starts_with($arg, '--report=')) {
            $options['report'] = substr($arg, strlen('--report='));
        }
    }

    return $options;
}

function google_sheets_import_dry_run_usage(): void
{
    fwrite(STDOUT, "Google Sheets CSV test DB dry-run import\n\n");
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  APP_ENV=testing DB_DATABASE=personal_accounting_test php app/scripts/google_sheets_import_dry_run.php\n\n");
    fwrite(STDOUT, "Options:\n");
    fwrite(STDOUT, "  --base-dir=/repo                 Project root containing imports/google_sheets/\n");
    fwrite(STDOUT, "  --report=docs/testing/file.md    Markdown report path\n");
    fwrite(STDOUT, "  --no-reset-source                Do not delete prior import_google_sheets rows first\n");
}

/**
 * @param array<string, string>|null $override
 * @return array<string, string>
 */
function google_sheets_import_assert_test_db_gate(PDO $pdo, ?array $override = null): array
{
    $appEnv = $override['APP_ENV'] ?? (string) app_env('APP_ENV', '');
    $configuredDatabase = $override['DB_DATABASE'] ?? (string) app_env('DB_DATABASE', '');
    $selectedDatabase = $override['SELECT_DATABASE'] ?? (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

    if (!in_array($appEnv, ['testing', 'development'], true)) {
        throw new RuntimeException('DB gate failed: APP_ENV must be testing or development.');
    }
    if ($configuredDatabase !== 'personal_accounting_test') {
        throw new RuntimeException('DB gate failed: DB_DATABASE must be personal_accounting_test.');
    }
    if ($selectedDatabase !== 'personal_accounting_test') {
        throw new RuntimeException('DB gate failed: SELECT DATABASE() must be personal_accounting_test.');
    }

    return [
        'APP_ENV' => $appEnv,
        'DB_DATABASE' => $configuredDatabase,
        'SELECT_DATABASE' => $selectedDatabase,
    ];
}

/**
 * @return array<int, string>
 */
function google_sheets_import_required_columns(string $type): array
{
    return match ($type) {
        'expenses' => [
            'record_date', 'item', 'amount', 'payment_method_id', 'payment_method',
            'accounting_month', 'category', 'raw_input', 'source', 'user_name',
        ],
        'incomes' => [
            'record_date', 'source_name', 'amount', 'account_id', 'account_name',
            'accounting_month', 'category', 'raw_input', 'source', 'user_name',
        ],
        'overtime_logs' => ['work_date', 'overtime_hours', 'raw_input', 'note', 'user_name', 'source'],
        'leave_logs' => ['leave_date', 'leave_type', 'leave_days', 'leave_hours', 'note', 'raw_input', 'user_name', 'source'],
        default => [],
    };
}

function google_sheets_import_require_mysql_schema(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return;
    }

    foreach (array_keys(import_preview_type_configs()) as $table) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $statement->execute(['table' => $table]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException("Required table missing in target DB: {$table}");
        }

        foreach (google_sheets_import_required_columns($table) as $column) {
            $columnStatement = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $columnStatement->execute(['table' => $table, 'column' => $column]);
            if ((int) $columnStatement->fetchColumn() !== 1) {
                throw new RuntimeException("Required column missing in target DB: {$table}.{$column}");
            }
        }
    }
}

/**
 * @return array<string, array<string, mixed>>
 */
function google_sheets_import_reference_maps(PDO $pdo): array
{
    return [
        'payment_methods' => google_sheets_import_reference_map($pdo, 'payment_methods'),
        'accounts' => google_sheets_import_reference_map($pdo, 'accounts'),
        'leave_types' => google_sheets_import_reference_map($pdo, 'leave_types'),
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function google_sheets_import_reference_map(PDO $pdo, string $table): array
{
    if (!in_array($table, ['payment_methods', 'accounts', 'leave_types'], true)) {
        throw new InvalidArgumentException('Unsupported reference table.');
    }

    $columns = $table === 'payment_methods'
        ? 'id, name, settlement_start_day, settlement_end_day'
        : 'id, name';
    $rows = $pdo->query("SELECT {$columns} FROM `{$table}` WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $name = import_preview_clean_cell($row['name'] ?? '');
        if ($name !== '') {
            $map[$name] = ['id' => (int) $row['id'], 'name' => $name];
            if ($table === 'payment_methods') {
                $map[$name]['settlement_start_day'] = (int) ($row['settlement_start_day'] ?? 0);
                $map[$name]['settlement_end_day'] = (int) ($row['settlement_end_day'] ?? 0);
            }
        }
    }

    return $map;
}

/**
 * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
 */
function google_sheets_import_read_csv(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException('CSV file not found: ' . import_preview_safe_label($file));
    }

    $csv = new SplFileObject($file, 'r');
    $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
    $headers = null;
    $rows = [];

    foreach ($csv as $row) {
        if ($row === [null] || $row === false) {
            continue;
        }
        $cleanRow = array_map('import_preview_clean_cell', is_array($row) ? $row : []);
        if ($cleanRow === [] || implode('', $cleanRow) === '') {
            continue;
        }
        if ($headers === null) {
            $headers = $cleanRow;
            continue;
        }
        $rows[] = $cleanRow;
    }

    if ($headers === null || $headers === [] || implode('', $headers) === '') {
        throw new RuntimeException('CSV header is empty: ' . import_preview_safe_label($file));
    }

    return ['headers' => $headers, 'rows' => $rows];
}

/**
 * @param array<int, string> $headers
 * @param array<int, string> $row
 * @return array<string, string>
 */
function google_sheets_import_row_by_header(array $headers, array $row): array
{
    $values = [];
    foreach ($headers as $index => $header) {
        $values[$header] = import_preview_clean_cell($row[$index] ?? '');
    }

    return $values;
}

/**
 * @param array<string, string> $row
 * @param array<string, string> $mapping
 */
function google_sheets_import_value(array $row, array $mapping, string $field): string
{
    $header = $mapping[$field] ?? '';
    if ($header === '') {
        return '';
    }

    return import_preview_clean_cell($row[$header] ?? '');
}

function google_sheets_import_normalize_date(string $value, ?int $fallbackYear = null): ?string
{
    $value = import_preview_clean_cell($value);
    if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/u', $value, $matches)) {
        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
    } elseif ($fallbackYear !== null && preg_match('/^(\d{1,2})月(\d{1,2})日/u', $value, $matches)) {
        $year = $fallbackYear;
        $month = (int) $matches[1];
        $day = (int) $matches[2];
    } else {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-n-j',
        $year . '-' . $month . '-' . $day,
        new DateTimeZone(APP_TIMEZONE)
    );
    if ($date === false) {
        return null;
    }

    return checkdate($month, $day, $year)
        ? $date->format('Y-m-d')
        : null;
}

function google_sheets_import_accounting_month(string $recordDate): string
{
    return str_replace('-', '/', substr($recordDate, 0, 7));
}

function google_sheets_import_normalize_number(string $value, bool $positive): ?float
{
    $value = str_replace(',', '', import_preview_clean_cell($value));
    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    $number = (float) $value;
    if ($positive && $number <= 0) {
        return null;
    }
    if (!$positive && $number < 0) {
        return null;
    }

    return $number;
}

function google_sheets_import_optional_number(string $value): ?float
{
    $value = import_preview_clean_cell($value);
    if ($value === '') {
        return 0.0;
    }

    return google_sheets_import_normalize_number($value, false);
}

/**
 * @param array<string, string> $row
 * @param array<int, string> $aliases
 */
function google_sheets_import_legacy_value(array $row, array $aliases): string
{
    foreach ($aliases as $alias) {
        if (array_key_exists($alias, $row)) {
            return import_preview_clean_cell($row[$alias]);
        }
    }

    return '';
}

/**
 * @param array<string, int> $bucket
 */
function google_sheets_import_count_name(array &$bucket, string $name): void
{
    $name = import_preview_safe_label($name);
    $bucket[$name] = ($bucket[$name] ?? 0) + 1;
}

/**
 * @return array<string, mixed>
 */
function google_sheets_import_empty_type_result(string $type, string $file, int $rowCount): array
{
    return [
        'type' => $type,
        'file' => $file,
        'row_count' => $rowCount,
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
        'duplicates' => 0,
        'conflicts' => 0,
        'amount_total' => 0.0,
        'hours_total' => 0.0,
        'leave_days_total' => 0.0,
        'leave_hours_total' => 0.0,
        'mapping_errors' => [],
        'unmapped_payment_methods' => [],
        'unmapped_accounts' => [],
        'unmapped_leave_types' => [],
        'skipped_reasons' => [],
        'invalid_row_summaries' => [],
        'summary_row_summaries' => [],
        'duplicate_candidates' => [],
    ];
}

/**
 * @param array<string, mixed> $typeResult
 */
function google_sheets_import_add_error(array &$typeResult, string $field): void
{
    $typeResult['errors']++;
    $typeResult['mapping_errors'][$field] = ((int) ($typeResult['mapping_errors'][$field] ?? 0)) + 1;
}

/** @param array<string, mixed> $typeResult */
function google_sheets_import_mark_invalid_row(array &$typeResult): void
{
    $typeResult['skipped']++;
    google_sheets_import_count_skip_reason($typeResult, 'invalid_row');
}

/**
 * @param array<string, mixed> $typeResult
 */
function google_sheets_import_mark_duplicate(array &$typeResult): void
{
    $typeResult['duplicates']++;
    $typeResult['skipped']++;
    google_sheets_import_count_skip_reason($typeResult, 'duplicate');
}

/**
 * @param array<string, mixed> $typeResult
 */
function google_sheets_import_mark_conflict(array &$typeResult): void
{
    $typeResult['conflicts']++;
    $typeResult['skipped']++;
    google_sheets_import_count_skip_reason($typeResult, 'conflict');
}

/**
 * @param array<string, mixed> $typeResult
 */
function google_sheets_import_count_skip_reason(array &$typeResult, string $reason): void
{
    $typeResult['skipped_reasons'][$reason] = ((int) ($typeResult['skipped_reasons'][$reason] ?? 0)) + 1;
}

/**
 * @param array<string, mixed> $typeResult
 */
function google_sheets_import_mark_summary_row(array &$typeResult): void
{
    $typeResult['skipped']++;
    google_sheets_import_count_skip_reason($typeResult, 'summary_row');
}

/**
 * @param array<string, int> $before
 * @param array<string, int> $after
 * @return array<int, string>
 */
function google_sheets_import_error_delta(array $before, array $after): array
{
    $fields = [];
    foreach ($after as $field => $count) {
        if ($count > (int) ($before[$field] ?? 0)) {
            $fields[] = $field;
        }
    }

    return $fields;
}

function google_sheets_import_date_shape(string $value): string
{
    $value = import_preview_clean_cell($value);
    if ($value === '') {
        return 'blank';
    }
    if (preg_match('/^\d{4}[-\/]\d{1,2}[-\/]\d{1,2}/u', $value) === 1) {
        return 'full_ymd';
    }
    if (preg_match('/^\d{1,2}月\d{1,2}日/u', $value) === 1) {
        return 'month_day_without_year';
    }

    return 'unrecognized_len_' . strlen($value);
}

/**
 * @param array<string, string> $row
 * @param array<string, string> $mapping
 */
function google_sheets_import_is_leave_summary_row(array $row, array $mapping): bool
{
    $dateValue = google_sheets_import_value($row, $mapping, 'leave_date');
    if (google_sheets_import_normalize_date($dateValue) !== null) {
        return false;
    }

    $joined = implode(' ', array_values($row));

    return str_contains($dateValue, '統計') || str_contains($joined, '請假統計') || str_contains($joined, '統計');
}

/**
 * @param array<string, mixed> $typeResult
 * @param array<string, string> $row
 * @param array<string, string> $mapping
 */
function google_sheets_import_add_summary_row_summary(
    array &$typeResult,
    int $rowNumber,
    array $row,
    array $mapping
): void {
    if (count($typeResult['summary_row_summaries']) >= 10) {
        return;
    }

    $typeResult['summary_row_summaries'][] = [
        'row' => $rowNumber,
        'reason' => 'summary_row',
        'date_shape' => google_sheets_import_date_shape(google_sheets_import_value($row, $mapping, 'leave_date')),
        'leave_type_blank' => google_sheets_import_value($row, $mapping, 'leave_type') === '',
        'leave_days_blank' => google_sheets_import_value($row, $mapping, 'leave_days') === '',
        'leave_hours_blank' => google_sheets_import_value($row, $mapping, 'leave_hours') === '',
    ];
}

/**
 * @param array<string, mixed> $typeResult
 * @param array<int, string> $fields
 * @param array<string, string> $row
 * @param array<string, string> $mapping
 */
function google_sheets_import_add_invalid_row_summary(
    array &$typeResult,
    int $rowNumber,
    array $fields,
    array $row,
    array $mapping
): void {
    if ($fields === [] || count($typeResult['invalid_row_summaries']) >= 10) {
        return;
    }

    $summary = [
        'row' => $rowNumber,
        'fields' => $fields,
    ];

    if ($typeResult['type'] === 'leave_logs') {
        $summary['date_shape'] = google_sheets_import_date_shape(google_sheets_import_value($row, $mapping, 'leave_date'));
        $summary['leave_type_blank'] = google_sheets_import_value($row, $mapping, 'leave_type') === '';
        $summary['leave_days_blank'] = google_sheets_import_value($row, $mapping, 'leave_days') === '';
        $summary['leave_hours_blank'] = google_sheets_import_value($row, $mapping, 'leave_hours') === '';
    }

    $typeResult['invalid_row_summaries'][] = $summary;
}

/**
 * @param array<string, mixed> $normalized
 * @return array<string, string|int|float>
 */
function google_sheets_import_duplicate_summary(string $type, array $normalized): array
{
    return match ($type) {
        'expenses' => [
            'record_date' => (string) $normalized['record_date'],
            'amount' => google_sheets_import_format_number((float) $normalized['amount']),
            'payment_method' => (string) $normalized['payment_method'],
            'item_length' => strlen((string) $normalized['item']),
        ],
        'incomes' => [
            'record_date' => (string) $normalized['record_date'],
            'amount' => google_sheets_import_format_number((float) $normalized['amount']),
            'account_name' => (string) $normalized['account_name'],
            'source_name_length' => strlen((string) $normalized['source_name']),
        ],
        'overtime_logs' => [
            'work_date' => (string) $normalized['work_date'],
            'overtime_hours' => google_sheets_import_format_number((float) $normalized['overtime_hours']),
        ],
        'leave_logs' => [
            'leave_date' => (string) $normalized['leave_date'],
            'leave_type' => (string) $normalized['leave_type'],
            'leave_days' => google_sheets_import_format_number((float) $normalized['leave_days']),
            'leave_hours' => google_sheets_import_format_number((float) $normalized['leave_hours']),
        ],
        default => [],
    };
}

/**
 * @param array<string, mixed> $typeResult
 * @param array<string, string|int|float> $summary
 */
function google_sheets_import_add_duplicate_candidate(
    array &$typeResult,
    int $rowNumber,
    int $firstRowNumber,
    array $summary
): void {
    if (count($typeResult['duplicate_candidates']) >= 10) {
        return;
    }

    $typeResult['duplicate_candidates'][] = [
        'row' => $rowNumber,
        'duplicates_row' => $firstRowNumber,
        'summary' => $summary,
    ];
}

/**
 * @param array<string, mixed> $refs
 * @return array<string, mixed>|null
 */
function google_sheets_import_ref(array $refs, string $group, string $name): ?array
{
    $name = import_preview_clean_cell($name);
    return $refs[$group][$name] ?? null;
}

/**
 * @param array<string, mixed> $typeResult
 * @param array<string, string> $row
 * @param array<string, string> $mapping
 * @param array<string, mixed> $refs
 * @return array<string, mixed>|null
 */
function google_sheets_import_normalize_expense(array &$typeResult, array $row, array $mapping, array $refs): ?array
{
    $recordDate = google_sheets_import_normalize_date(google_sheets_import_value($row, $mapping, 'record_date'));
    $item = google_sheets_import_value($row, $mapping, 'item');
    $amount = google_sheets_import_normalize_number(google_sheets_import_value($row, $mapping, 'amount'), true);
    $paymentMethodName = google_sheets_import_value($row, $mapping, 'payment_method');
    $paymentMethod = google_sheets_import_ref($refs, 'payment_methods', $paymentMethodName);

    if ($recordDate === null) {
        google_sheets_import_add_error($typeResult, 'record_date');
    }
    if ($item === '') {
        google_sheets_import_add_error($typeResult, 'item');
    }
    if ($amount === null) {
        google_sheets_import_add_error($typeResult, 'amount');
    }
    if ($paymentMethod === null) {
        google_sheets_import_add_error($typeResult, 'payment_method');
        google_sheets_import_count_name($typeResult['unmapped_payment_methods'], $paymentMethodName);
    }

    if ($recordDate === null || $item === '' || $amount === null || $paymentMethod === null) {
        google_sheets_import_mark_invalid_row($typeResult);
        return null;
    }

    return [
        'record_date' => $recordDate,
        'item' => $item,
        'amount' => $amount,
        'payment_method_id' => $paymentMethod['id'],
        'payment_method' => $paymentMethod['name'],
        'accounting_month' => AccountingMonthService::forPaymentMethod($recordDate, $paymentMethod),
        'category' => google_sheets_import_value($row, $mapping, 'category') ?: '其他',
        'user_name' => null,
    ];
}

/**
 * @param array<string, mixed> $typeResult
 * @param array<string, string> $row
 * @param array<string, string> $mapping
 * @param array<string, mixed> $refs
 * @return array<string, mixed>|null
 */
function google_sheets_import_normalize_income(array &$typeResult, array $row, array $mapping, array $refs): ?array
{
    $recordDate = google_sheets_import_normalize_date(google_sheets_import_value($row, $mapping, 'record_date'));
    $sourceName = google_sheets_import_value($row, $mapping, 'source_name');
    $amount = google_sheets_import_normalize_number(google_sheets_import_value($row, $mapping, 'amount'), true);
    $accountName = google_sheets_import_value($row, $mapping, 'account_name');
    $account = google_sheets_import_ref($refs, 'accounts', $accountName);

    if ($recordDate === null) {
        google_sheets_import_add_error($typeResult, 'record_date');
    }
    if ($sourceName === '') {
        google_sheets_import_add_error($typeResult, 'source_name');
    }
    if ($amount === null) {
        google_sheets_import_add_error($typeResult, 'amount');
    }
    if ($account === null) {
        google_sheets_import_add_error($typeResult, 'account_name');
        google_sheets_import_count_name($typeResult['unmapped_accounts'], $accountName);
    }

    if ($recordDate === null || $sourceName === '' || $amount === null || $account === null) {
        google_sheets_import_mark_invalid_row($typeResult);
        return null;
    }

    return [
        'record_date' => $recordDate,
        'source_name' => $sourceName,
        'amount' => $amount,
        'account_id' => $account['id'],
        'account_name' => $account['name'],
        'accounting_month' => google_sheets_import_accounting_month($recordDate),
        'category' => google_sheets_import_value($row, $mapping, 'category') ?: '其他',
        'user_name' => null,
    ];
}

/**
 * @param array<string, mixed> $typeResult
 * @param array<string, string> $row
 * @param array<string, string> $mapping
 * @return array<string, mixed>|null
 */
function google_sheets_import_normalize_overtime(array &$typeResult, array $row, array $mapping): ?array
{
    $systemDate = google_sheets_import_normalize_date(google_sheets_import_legacy_value($row, ['系統時間', 'system_time']));
    $fallbackYear = $systemDate !== null ? (int) substr($systemDate, 0, 4) : null;
    $workDate = google_sheets_import_normalize_date(google_sheets_import_value($row, $mapping, 'work_date'), $fallbackYear);
    $hours = google_sheets_import_normalize_number(google_sheets_import_value($row, $mapping, 'overtime_hours'), true);

    if ($workDate === null) {
        google_sheets_import_add_error($typeResult, 'work_date');
    }
    if ($hours === null) {
        google_sheets_import_add_error($typeResult, 'overtime_hours');
    }

    if ($workDate === null || $hours === null) {
        google_sheets_import_mark_invalid_row($typeResult);
        return null;
    }

    return [
        'work_date' => $workDate,
        'overtime_hours' => $hours,
        'note' => null,
        'user_name' => null,
    ];
}

/**
 * @param array<string, mixed> $typeResult
 * @param array<string, string> $row
 * @param array<string, string> $mapping
 * @param array<string, mixed> $refs
 * @return array<string, mixed>|null
 */
function google_sheets_import_normalize_leave(array &$typeResult, array $row, array $mapping, array $refs): ?array
{
    $leaveDate = google_sheets_import_normalize_date(google_sheets_import_value($row, $mapping, 'leave_date'));
    $leaveTypeName = google_sheets_import_value($row, $mapping, 'leave_type');
    $leaveType = google_sheets_import_ref($refs, 'leave_types', $leaveTypeName);
    $days = google_sheets_import_optional_number(google_sheets_import_value($row, $mapping, 'leave_days'));
    $hours = google_sheets_import_optional_number(google_sheets_import_value($row, $mapping, 'leave_hours'));

    if ($leaveDate === null) {
        google_sheets_import_add_error($typeResult, 'leave_date');
    }
    if ($leaveType === null) {
        google_sheets_import_add_error($typeResult, 'leave_type');
        google_sheets_import_count_name($typeResult['unmapped_leave_types'], $leaveTypeName);
    }
    if ($days === null) {
        google_sheets_import_add_error($typeResult, 'leave_days');
    }
    if ($hours === null) {
        google_sheets_import_add_error($typeResult, 'leave_hours');
    }
    if ($days !== null && $hours !== null && $days <= 0.0 && $hours <= 0.0) {
        google_sheets_import_add_error($typeResult, 'leave_duration');
    }

    if ($leaveDate === null || $leaveType === null || $days === null || $hours === null || ($days <= 0.0 && $hours <= 0.0)) {
        google_sheets_import_mark_invalid_row($typeResult);
        return null;
    }

    return [
        'leave_date' => $leaveDate,
        'leave_type' => $leaveType['name'],
        'leave_days' => $days,
        'leave_hours' => $hours,
        'note' => google_sheets_import_value($row, $mapping, 'note') ?: null,
        'user_name' => null,
    ];
}

/**
 * @param array<string, mixed> $typeResult
 * @param array<string, mixed> $normalized
 */
function google_sheets_import_insert_expense(PDO $pdo, array &$typeResult, array $normalized): void
{
    $statement = $pdo->prepare(
        'INSERT INTO expenses
            (record_date, item, amount, payment_method_id, payment_method, accounting_month,
             category, raw_input, source, user_name)
         VALUES
            (:record_date, :item, :amount, :payment_method_id, :payment_method, :accounting_month,
             :category, NULL, :source, :user_name)'
    );
    $statement->execute($normalized + ['source' => GOOGLE_SHEETS_IMPORT_SOURCE]);
    $typeResult['inserted']++;
    $typeResult['amount_total'] += (float) $normalized['amount'];
}

/**
 * @param array<string, mixed> $typeResult
 * @param array<string, mixed> $normalized
 */
function google_sheets_import_insert_income(PDO $pdo, array &$typeResult, array $normalized): void
{
    $statement = $pdo->prepare(
        'INSERT INTO incomes
            (record_date, source_name, amount, account_id, account_name, accounting_month,
             category, raw_input, source, user_name)
         VALUES
            (:record_date, :source_name, :amount, :account_id, :account_name, :accounting_month,
             :category, NULL, :source, :user_name)'
    );
    $statement->execute($normalized + ['source' => GOOGLE_SHEETS_IMPORT_SOURCE]);
    $typeResult['inserted']++;
    $typeResult['amount_total'] += (float) $normalized['amount'];
}

/**
 * @param array<string, mixed> $typeResult
 * @param array<string, mixed> $normalized
 */
function google_sheets_import_insert_overtime(PDO $pdo, array &$typeResult, array $normalized): void
{
    $conflict = $pdo->prepare(
        'SELECT id FROM overtime_logs
         WHERE work_date = :work_date AND is_deleted = 0 AND (source IS NULL OR source <> :source)
         LIMIT 1'
    );
    $conflict->execute(['work_date' => $normalized['work_date'], 'source' => GOOGLE_SHEETS_IMPORT_SOURCE]);
    if ($conflict->fetchColumn() !== false) {
        google_sheets_import_mark_conflict($typeResult);
        return;
    }

    $statement = $pdo->prepare(
        'INSERT INTO overtime_logs
            (work_date, overtime_hours, raw_input, note, user_name, source, is_deleted, deleted_at)
         VALUES
            (:work_date, :overtime_hours, NULL, :note, :user_name, :source, 0, NULL)'
    );
    $statement->execute($normalized + ['source' => GOOGLE_SHEETS_IMPORT_SOURCE]);
    $typeResult['inserted']++;
    $typeResult['hours_total'] += (float) $normalized['overtime_hours'];
}

/**
 * @param array<string, mixed> $typeResult
 * @param array<string, mixed> $normalized
 */
function google_sheets_import_insert_leave(PDO $pdo, array &$typeResult, array $normalized): void
{
    $conflict = $pdo->prepare(
        'SELECT id FROM leave_logs
         WHERE leave_date = :leave_date AND is_deleted = 0 AND (source IS NULL OR source <> :source)
         LIMIT 1'
    );
    $conflict->execute(['leave_date' => $normalized['leave_date'], 'source' => GOOGLE_SHEETS_IMPORT_SOURCE]);
    if ($conflict->fetchColumn() !== false) {
        google_sheets_import_mark_conflict($typeResult);
        return;
    }

    $statement = $pdo->prepare(
        'INSERT INTO leave_logs
            (leave_date, leave_type, leave_days, leave_hours, note, raw_input, user_name, source, is_deleted, deleted_at)
         VALUES
            (:leave_date, :leave_type, :leave_days, :leave_hours, :note, NULL, :user_name, :source, 0, NULL)'
    );
    $statement->execute($normalized + ['source' => GOOGLE_SHEETS_IMPORT_SOURCE]);
    $typeResult['inserted']++;
    $typeResult['leave_days_total'] += (float) $normalized['leave_days'];
    $typeResult['leave_hours_total'] += (float) $normalized['leave_hours'];
}

/**
 * @param array<string, string> $mapping
 * @param array<int, string> $required
 * @return array<int, string>
 */
function google_sheets_import_missing_headers(array $mapping, array $required): array
{
    return import_preview_missing_required($mapping, $required);
}

/**
 * @return array<string, mixed>
 */
function google_sheets_import_run(PDO $pdo, string $baseDir, bool $resetSource = true, ?array $gateOverride = null): array
{
    $gate = google_sheets_import_assert_test_db_gate($pdo, $gateOverride);
    google_sheets_import_require_mysql_schema($pdo);

    $configs = import_preview_type_configs();
    $files = [];
    $csvData = [];
    $results = [];

    foreach ($configs as $type => $config) {
        $file = rtrim($baseDir, '/') . '/' . $config['expected_file'];
        $files[$type] = $file;
        $csvData[$type] = google_sheets_import_read_csv($file);
        $results[$type] = google_sheets_import_empty_type_result($type, $config['expected_file'], count($csvData[$type]['rows']));
    }

    $refs = google_sheets_import_reference_maps($pdo);

    $pdo->beginTransaction();
    try {
        if ($resetSource) {
            foreach (['expenses', 'incomes', 'overtime_logs', 'leave_logs'] as $table) {
                $delete = $pdo->prepare("DELETE FROM `{$table}` WHERE source = :source");
                $delete->execute(['source' => GOOGLE_SHEETS_IMPORT_SOURCE]);
            }
        }

        foreach ($configs as $type => $config) {
            $headers = $csvData[$type]['headers'];
            $mapping = import_preview_build_mapping($headers, $config['columns']);
            $missing = google_sheets_import_missing_headers($mapping, $config['required']);
            if ($missing !== []) {
                foreach ($missing as $field) {
                    google_sheets_import_add_error($results[$type], 'missing_header_' . $field);
                }
                $results[$type]['skipped'] += (int) $results[$type]['row_count'];
                continue;
            }

            $seen = [];
            foreach ($csvData[$type]['rows'] as $index => $row) {
                $rowNumber = $index + 1;
                $rowByHeader = google_sheets_import_row_by_header($headers, $row);
                if ($type === 'leave_logs' && google_sheets_import_is_leave_summary_row($rowByHeader, $mapping)) {
                    google_sheets_import_mark_summary_row($results[$type]);
                    google_sheets_import_add_summary_row_summary($results[$type], $rowNumber, $rowByHeader, $mapping);
                    continue;
                }

                $beforeErrors = $results[$type]['mapping_errors'];
                $normalized = match ($type) {
                    'expenses' => google_sheets_import_normalize_expense($results[$type], $rowByHeader, $mapping, $refs),
                    'incomes' => google_sheets_import_normalize_income($results[$type], $rowByHeader, $mapping, $refs),
                    'overtime_logs' => google_sheets_import_normalize_overtime($results[$type], $rowByHeader, $mapping),
                    'leave_logs' => google_sheets_import_normalize_leave($results[$type], $rowByHeader, $mapping, $refs),
                    default => null,
                };

                if ($normalized === null) {
                    google_sheets_import_add_invalid_row_summary(
                        $results[$type],
                        $rowNumber,
                        google_sheets_import_error_delta($beforeErrors, $results[$type]['mapping_errors']),
                        $rowByHeader,
                        $mapping
                    );
                    continue;
                }

                $dedupeKey = match ($type) {
                    'expenses' => implode('|', [
                        $normalized['record_date'],
                        $normalized['item'],
                        number_format((float) $normalized['amount'], 2, '.', ''),
                        $normalized['payment_method'],
                    ]),
                    'incomes' => implode('|', [
                        $normalized['record_date'],
                        $normalized['source_name'],
                        number_format((float) $normalized['amount'], 2, '.', ''),
                        $normalized['account_name'],
                    ]),
                    'overtime_logs' => (string) $normalized['work_date'],
                    'leave_logs' => (string) $normalized['leave_date'],
                    default => '',
                };

                if (isset($seen[$dedupeKey])) {
                    google_sheets_import_add_duplicate_candidate(
                        $results[$type],
                        $rowNumber,
                        (int) $seen[$dedupeKey]['row_number'],
                        google_sheets_import_duplicate_summary($type, $normalized)
                    );
                    google_sheets_import_mark_duplicate($results[$type]);
                    continue;
                }
                $seen[$dedupeKey] = [
                    'row_number' => $rowNumber,
                    'summary' => google_sheets_import_duplicate_summary($type, $normalized),
                ];

                match ($type) {
                    'expenses' => google_sheets_import_insert_expense($pdo, $results[$type], $normalized),
                    'incomes' => google_sheets_import_insert_income($pdo, $results[$type], $normalized),
                    'overtime_logs' => google_sheets_import_insert_overtime($pdo, $results[$type], $normalized),
                    'leave_logs' => google_sheets_import_insert_leave($pdo, $results[$type], $normalized),
                    default => null,
                };
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $totals = [
        'row_count' => 0,
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
        'duplicates' => 0,
        'conflicts' => 0,
        'expenses_amount_total' => (float) $results['expenses']['amount_total'],
        'incomes_amount_total' => (float) $results['incomes']['amount_total'],
        'overtime_hours_total' => (float) $results['overtime_logs']['hours_total'],
        'leave_days_total' => (float) $results['leave_logs']['leave_days_total'],
        'leave_hours_total' => (float) $results['leave_logs']['leave_hours_total'],
    ];

    foreach ($results as $result) {
        foreach (['row_count', 'inserted', 'updated', 'skipped', 'errors', 'duplicates', 'conflicts'] as $field) {
            $totals[$field] += (int) $result[$field];
        }
    }

    return [
        'generated_at' => (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format('Y-m-d H:i:s T'),
        'gate' => $gate,
        'reset_source' => $resetSource,
        'files' => $files,
        'results' => $results,
        'totals' => $totals,
    ];
}

/**
 * @param array<string, mixed> $result
 */
function google_sheets_import_print_summary(array $result): void
{
    fwrite(STDOUT, "Google Sheets CSV test DB dry-run import\n");
    fwrite(STDOUT, 'Generated at: ' . $result['generated_at'] . "\n");
    fwrite(STDOUT, 'APP_ENV=' . $result['gate']['APP_ENV'] . "\n");
    fwrite(STDOUT, 'DB_DATABASE=' . $result['gate']['DB_DATABASE'] . "\n");
    fwrite(STDOUT, 'SELECT_DATABASE=' . $result['gate']['SELECT_DATABASE'] . "\n");
    fwrite(STDOUT, 'Reset import source rows: ' . ($result['reset_source'] ? 'yes' : 'no') . "\n\n");

    foreach ($result['results'] as $type => $stats) {
        fwrite(
            STDOUT,
            sprintf(
                "%s: rows=%d inserted=%d updated=%d skipped=%d errors=%d duplicates=%d conflicts=%d\n",
                $type,
                $stats['row_count'],
                $stats['inserted'],
                $stats['updated'],
                $stats['skipped'],
                $stats['errors'],
                $stats['duplicates'],
                $stats['conflicts']
            )
        );
    }

    fwrite(STDOUT, "\nTotals:\n");
    fwrite(STDOUT, '  expenses_amount_total=' . google_sheets_import_format_number($result['totals']['expenses_amount_total']) . "\n");
    fwrite(STDOUT, '  incomes_amount_total=' . google_sheets_import_format_number($result['totals']['incomes_amount_total']) . "\n");
    fwrite(STDOUT, '  overtime_hours_total=' . google_sheets_import_format_number($result['totals']['overtime_hours_total']) . "\n");
    fwrite(STDOUT, '  leave_days_total=' . google_sheets_import_format_number($result['totals']['leave_days_total']) . "\n");
    fwrite(STDOUT, '  leave_hours_total=' . google_sheets_import_format_number($result['totals']['leave_hours_total']) . "\n");
}

function google_sheets_import_format_number(float|int $value): string
{
    $formatted = number_format((float) $value, 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

/**
 * @param array<string, int> $items
 */
function google_sheets_import_markdown_counts(array $items): string
{
    if ($items === []) {
        return '- none';
    }

    $lines = [];
    foreach ($items as $name => $count) {
        $lines[] = "- {$name}: {$count}";
    }

    return implode("\n", $lines);
}

/**
 * @param array<int, array<string, mixed>> $items
 */
function google_sheets_import_markdown_invalid_rows(array $items): string
{
    if ($items === []) {
        return '- none';
    }

    $lines = [];
    foreach ($items as $item) {
        $parts = [
            'row=' . $item['row'],
            'fields=' . implode(',', $item['fields']),
        ];
        foreach (['date_shape', 'leave_type_blank', 'leave_days_blank', 'leave_hours_blank'] as $key) {
            if (array_key_exists($key, $item)) {
                $value = is_bool($item[$key]) ? ($item[$key] ? 'yes' : 'no') : (string) $item[$key];
                $parts[] = $key . '=' . $value;
            }
        }
        $lines[] = '- ' . implode('; ', $parts);
    }

    return implode("\n", $lines);
}

/**
 * @param array<int, array<string, mixed>> $items
 */
function google_sheets_import_markdown_summary_rows(array $items): string
{
    if ($items === []) {
        return '- none';
    }

    $lines = [];
    foreach ($items as $item) {
        $parts = [
            'row=' . $item['row'],
            'reason=' . $item['reason'],
            'date_shape=' . $item['date_shape'],
            'leave_type_blank=' . ($item['leave_type_blank'] ? 'yes' : 'no'),
            'leave_days_blank=' . ($item['leave_days_blank'] ? 'yes' : 'no'),
            'leave_hours_blank=' . ($item['leave_hours_blank'] ? 'yes' : 'no'),
        ];
        $lines[] = '- ' . implode('; ', $parts);
    }

    return implode("\n", $lines);
}

/**
 * @param array<int, array<string, mixed>> $items
 */
function google_sheets_import_markdown_duplicate_candidates(array $items): string
{
    if ($items === []) {
        return '- none';
    }

    $lines = [];
    foreach ($items as $item) {
        $parts = [
            'row=' . $item['row'],
            'duplicates_row=' . $item['duplicates_row'],
        ];
        foreach ($item['summary'] as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        $lines[] = '- ' . implode('; ', $parts);
    }

    return implode("\n", $lines);
}

/**
 * @param array<string, mixed> $result
 */
function google_sheets_import_write_markdown_report(array $result, string $reportPath): void
{
    $dir = dirname($reportPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $lines = [];
    $lines[] = '# Google Sheets CSV test DB dry-run';
    $lines[] = '';
    $lines[] = '## Dry-run status';
    $lines[] = '';
    $lines[] = '- Date: ' . $result['generated_at'];
    $lines[] = '- Project: `personal-accounting`';
    $lines[] = '- Source: `imports/google_sheets/*.csv`';
    $lines[] = '- Target DB: `personal_accounting_test` only';
    $lines[] = '- Source tag: `' . GOOGLE_SHEETS_IMPORT_SOURCE . '`';
    $lines[] = '- Reset prior source rows: ' . ($result['reset_source'] ? 'yes' : 'no');
    $lines[] = '';
    $lines[] = '## DB gate';
    $lines[] = '';
    $lines[] = '- APP_ENV: `' . $result['gate']['APP_ENV'] . '`';
    $lines[] = '- DB_DATABASE: `' . $result['gate']['DB_DATABASE'] . '`';
    $lines[] = '- SELECT DATABASE(): `' . $result['gate']['SELECT_DATABASE'] . '`';
    $lines[] = '';
    $lines[] = '## CSV row counts';
    $lines[] = '';
    $lines[] = '| Type | File | Rows |';
    $lines[] = '| --- | --- | ---: |';
    foreach ($result['results'] as $type => $stats) {
        $lines[] = '| `' . $type . '` | `' . $stats['file'] . '` | ' . $stats['row_count'] . ' |';
    }
    $lines[] = '';
    $lines[] = '## Import result';
    $lines[] = '';
    $lines[] = '| Type | Inserted | Updated | Skipped | Errors | Duplicates | Conflicts |';
    $lines[] = '| --- | ---: | ---: | ---: | ---: | ---: | ---: |';
    foreach ($result['results'] as $type => $stats) {
        $lines[] = sprintf(
            '| `%s` | %d | %d | %d | %d | %d | %d |',
            $type,
            $stats['inserted'],
            $stats['updated'],
            $stats['skipped'],
            $stats['errors'],
            $stats['duplicates'],
            $stats['conflicts']
        );
    }
    $lines[] = '';
    $lines[] = '## Totals';
    $lines[] = '';
    $lines[] = '- Expenses amount total: ' . google_sheets_import_format_number($result['totals']['expenses_amount_total']);
    $lines[] = '- Incomes amount total: ' . google_sheets_import_format_number($result['totals']['incomes_amount_total']);
    $lines[] = '- Overtime hours total: ' . google_sheets_import_format_number($result['totals']['overtime_hours_total']);
    $lines[] = '- Leave days total: ' . google_sheets_import_format_number($result['totals']['leave_days_total']);
    $lines[] = '- Leave hours total: ' . google_sheets_import_format_number($result['totals']['leave_hours_total']);
    $lines[] = '';
    $lines[] = '## Mapping issues';
    foreach ($result['results'] as $type => $stats) {
        $lines[] = '';
        $lines[] = '### ' . $type;
        $lines[] = google_sheets_import_markdown_counts($stats['mapping_errors']);
    }
    $lines[] = '';
    $lines[] = '## Skipped reasons';
    foreach ($result['results'] as $type => $stats) {
        $lines[] = '';
        $lines[] = '### ' . $type;
        $lines[] = google_sheets_import_markdown_counts($stats['skipped_reasons']);
    }
    $lines[] = '';
    $lines[] = '## Summary row skips';
    $lines[] = '';
    $lines[] = 'Only row numbers and field-level diagnostics are shown; raw CSV values are intentionally omitted.';
    foreach ($result['results'] as $type => $stats) {
        $lines[] = '';
        $lines[] = '### ' . $type;
        $lines[] = google_sheets_import_markdown_summary_rows($stats['summary_row_summaries']);
    }
    $lines[] = '';
    $lines[] = '## Invalid row summaries';
    $lines[] = '';
    $lines[] = 'Only row numbers and field-level diagnostics are shown; raw CSV values are intentionally omitted.';
    foreach ($result['results'] as $type => $stats) {
        $lines[] = '';
        $lines[] = '### ' . $type;
        $lines[] = google_sheets_import_markdown_invalid_rows($stats['invalid_row_summaries']);
    }
    $lines[] = '';
    $lines[] = '## Observed dry-run notes';
    $lines[] = '';
    $lines[] = '- `overtime_logs` rows with legacy month/day weekday dates were resolved using `系統時間` only as a year fallback; `系統時間` was not imported as a ledger field.';
    $leaveDateErrors = (int) ($result['results']['leave_logs']['mapping_errors']['leave_date'] ?? 0);
    $leaveTypeErrors = (int) ($result['results']['leave_logs']['mapping_errors']['leave_type'] ?? 0);
    $leaveSummaryRows = (int) ($result['results']['leave_logs']['skipped_reasons']['summary_row'] ?? 0);
    $lines[] = $leaveDateErrors > 0 || $leaveTypeErrors > 0
        ? '- `leave_logs` mostly resolved after the CSV date update, but still has '
            . $leaveDateErrors . ' invalid date row(s) and '
            . $leaveTypeErrors . ' missing leave type row(s); those rows were skipped instead of guessing.'
        : '- `leave_logs` date and leave type values resolved without mapping errors.';
    $lines[] = '- `leave_logs` summary rows skipped with reason `summary_row`: ' . $leaveSummaryRows . '.';
    $lines[] = ((int) ($result['results']['expenses']['duplicates'] ?? 0) > 0)
        ? '- `expenses` contains duplicate candidate rows by date, item, amount, and payment method; duplicate candidates were skipped in the dry-run.'
        : '- `expenses` did not contain duplicate candidates by the dry-run key.';
    $lines[] = '';
    $lines[] = '## Leave logs result';
    $lines[] = '';
    $lines[] = '- Inserted: ' . $result['results']['leave_logs']['inserted'];
    $lines[] = '- Skipped: ' . $result['results']['leave_logs']['skipped'];
    $lines[] = '- Summary row skipped: ' . $leaveSummaryRows;
    $lines[] = '- Errors: ' . $result['results']['leave_logs']['errors'];
    $lines[] = '- Leave days total: ' . google_sheets_import_format_number($result['totals']['leave_days_total']);
    $lines[] = '- Leave hours total: ' . google_sheets_import_format_number($result['totals']['leave_hours_total']);
    $lines[] = '';
    $lines[] = '## Unmapped references';
    $lines[] = '';
    $lines[] = '### Payment methods';
    $lines[] = google_sheets_import_markdown_counts($result['results']['expenses']['unmapped_payment_methods']);
    $lines[] = '';
    $lines[] = '### Accounts';
    $lines[] = google_sheets_import_markdown_counts($result['results']['incomes']['unmapped_accounts']);
    $lines[] = '';
    $lines[] = '### Leave types';
    $lines[] = google_sheets_import_markdown_counts($result['results']['leave_logs']['unmapped_leave_types']);
    $lines[] = '';
    $lines[] = '## Duplicate / conflict';
    $lines[] = '';
    foreach ($result['results'] as $type => $stats) {
        $lines[] = '- `' . $type . '`: duplicates=' . $stats['duplicates'] . ', conflicts=' . $stats['conflicts'];
    }
    $lines[] = '';
    $lines[] = '## Duplicate candidate summaries';
    $lines[] = '';
    $lines[] = 'Only normalized keys and value lengths are shown; item/source text and full CSV rows are intentionally omitted.';
    foreach ($result['results'] as $type => $stats) {
        $lines[] = '';
        $lines[] = '### ' . $type;
        $lines[] = google_sheets_import_markdown_duplicate_candidates($stats['duplicate_candidates']);
    }
    $lines[] = '';
    $lines[] = '## Next step';
    $lines[] = '';
    $lines[] = ((int) $result['totals']['errors'] === 0 && (int) $result['totals']['conflicts'] === 0)
        ? '- Dry-run PASS. A formal import execution package can be prepared next; production import still requires separate user approval.'
        : '- Dry-run found errors or conflicts. Fix mapping/reference/data issues before preparing a formal import execution package.';
    $lines[] = '';
    $lines[] = '## Safety statement';
    $lines[] = '';
    $lines[] = '- Did not write production-like `personal_accounting`.';
    $lines[] = '- Did not commit real CSV or Excel files.';
    $lines[] = '- Did not connect to Google Sheets API.';
    $lines[] = '- Did not modify `.env`, Docker, database schema, or migrations.';
    $lines[] = '- This report intentionally omits full personal CSV row contents.';
    $lines[] = '';

    file_put_contents($reportPath, implode("\n", $lines));
}

function google_sheets_import_main(array $argv): int
{
    $options = google_sheets_import_dry_run_parse_args($argv);
    if ($options['help'] === true) {
        google_sheets_import_dry_run_usage();
        return 0;
    }

    $pdo = app_db();
    $result = google_sheets_import_run($pdo, (string) $options['base_dir'], (bool) $options['reset_source']);
    google_sheets_import_print_summary($result);
    google_sheets_import_write_markdown_report($result, (string) $options['report']);
    fwrite(STDOUT, "\nReport written: " . $options['report'] . "\n");

    return ((int) $result['totals']['errors'] === 0 && (int) $result['totals']['conflicts'] === 0) ? 0 : 1;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    try {
        exit(google_sheets_import_main($argv));
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
