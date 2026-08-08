<?php

declare(strict_types=1);

require_once __DIR__ . '/google_sheets_import_dry_run.php';

/**
 * @return array<string, string|bool>
 */
function google_sheets_import_production_parse_args(array $argv): array
{
    $options = [
        'base_dir' => dirname(__DIR__, 2),
        'confirm' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--confirm-production-import') {
            $options['confirm'] = true;
            continue;
        }
        if (str_starts_with($arg, '--base-dir=')) {
            $options['base_dir'] = substr($arg, strlen('--base-dir='));
        }
    }

    return $options;
}

function google_sheets_import_production_usage(): void
{
    fwrite(STDOUT, "Google Sheets CSV production import\n\n");
    fwrite(STDOUT, "Usage:\n");
    fwrite(
        STDOUT,
        "  APP_ENV=production DB_DATABASE=personal_accounting php app/scripts/google_sheets_import_production.php --confirm-production-import\n\n"
    );
    fwrite(STDOUT, "Options:\n");
    fwrite(STDOUT, "  --base-dir=/repo                 Project root containing imports/google_sheets/\n");
    fwrite(STDOUT, "  --confirm-production-import      Required. Without this flag the tool refuses to run.\n");
}

/**
 * @param array<string, string>|null $override
 * @return array<string, string>
 */
function google_sheets_import_assert_production_db_gate(PDO $pdo, ?array $override = null): array
{
    $appEnv = $override['APP_ENV'] ?? (string) app_env('APP_ENV', '');
    $configuredDatabase = $override['DB_DATABASE'] ?? (string) app_env('DB_DATABASE', '');
    $selectedDatabase = $override['SELECT_DATABASE'] ?? (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

    if ($appEnv !== 'production') {
        throw new RuntimeException('Production import gate failed: APP_ENV must be production.');
    }
    if ($configuredDatabase !== 'personal_accounting') {
        throw new RuntimeException('Production import gate failed: DB_DATABASE must be personal_accounting.');
    }
    if ($selectedDatabase !== 'personal_accounting') {
        throw new RuntimeException('Production import gate failed: SELECT DATABASE() must be personal_accounting.');
    }

    return [
        'APP_ENV' => $appEnv,
        'DB_DATABASE' => $configuredDatabase,
        'SELECT_DATABASE' => $selectedDatabase,
    ];
}

/**
 * @return array<string, int>
 */
function google_sheets_import_business_table_counts(PDO $pdo): array
{
    $counts = [];
    foreach (['expenses', 'incomes', 'overtime_logs', 'leave_logs'] as $table) {
        $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }

    return $counts;
}

/**
 * @param array<string, int> $counts
 */
function google_sheets_import_assert_empty_business_tables(array $counts): void
{
    $nonEmpty = [];
    foreach ($counts as $table => $count) {
        if ($count !== 0) {
            $nonEmpty[] = $table . '=' . $count;
        }
    }

    if ($nonEmpty !== []) {
        throw new RuntimeException('Production import refused: business tables are not empty (' . implode(', ', $nonEmpty) . ').');
    }
}

/**
 * @param array<string, mixed> $result
 */
function google_sheets_import_production_totals(array $result): array
{
    $totals = [
        'row_count' => 0,
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
        'duplicates' => 0,
        'conflicts' => 0,
        'expenses_amount_total' => (float) $result['results']['expenses']['amount_total'],
        'incomes_amount_total' => (float) $result['results']['incomes']['amount_total'],
        'overtime_hours_total' => (float) $result['results']['overtime_logs']['hours_total'],
        'leave_days_total' => (float) $result['results']['leave_logs']['leave_days_total'],
        'leave_hours_total' => (float) $result['results']['leave_logs']['leave_hours_total'],
    ];

    foreach ($result['results'] as $stats) {
        foreach (['row_count', 'inserted', 'updated', 'skipped', 'errors', 'duplicates', 'conflicts'] as $field) {
            $totals[$field] += (int) $stats[$field];
        }
    }

    return $totals;
}

/**
 * @return array<string, mixed>
 */
function google_sheets_import_production_run(
    PDO $pdo,
    string $baseDir,
    bool $confirmed,
    ?array $gateOverride = null
): array {
    if (!$confirmed) {
        throw new RuntimeException('Production import refused: missing --confirm-production-import.');
    }

    $gate = google_sheets_import_assert_production_db_gate($pdo, $gateOverride);
    google_sheets_import_require_mysql_schema($pdo);
    $beforeCounts = google_sheets_import_business_table_counts($pdo);
    google_sheets_import_assert_empty_business_tables($beforeCounts);

    $configs = import_preview_type_configs();
    $csvData = [];
    $results = [];
    foreach ($configs as $type => $config) {
        $file = rtrim($baseDir, '/') . '/' . $config['expected_file'];
        $csvData[$type] = google_sheets_import_read_csv($file);
        $results[$type] = google_sheets_import_empty_type_result($type, $config['expected_file'], count($csvData[$type]['rows']));
    }

    $refs = google_sheets_import_reference_maps($pdo);
    $pdo->beginTransaction();
    try {
        foreach ($configs as $type => $config) {
            $headers = $csvData[$type]['headers'];
            $mapping = import_preview_build_mapping($headers, $config['columns']);
            $missing = google_sheets_import_missing_headers($mapping, $config['required']);
            if ($missing !== []) {
                foreach ($missing as $field) {
                    google_sheets_import_add_error($results[$type], 'missing_header_' . $field);
                }
                $results[$type]['skipped'] += (int) $results[$type]['row_count'];
                google_sheets_import_count_skip_reason($results[$type], 'missing_header');
                continue;
            }

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

                match ($type) {
                    'expenses' => google_sheets_import_insert_expense($pdo, $results[$type], $normalized),
                    'incomes' => google_sheets_import_insert_income($pdo, $results[$type], $normalized),
                    'overtime_logs' => google_sheets_import_insert_overtime($pdo, $results[$type], $normalized),
                    'leave_logs' => google_sheets_import_insert_leave($pdo, $results[$type], $normalized),
                    default => null,
                };
            }
        }

        $result = [
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format('Y-m-d H:i:s T'),
            'gate' => $gate,
            'before_counts' => $beforeCounts,
            'results' => $results,
        ];
        $result['totals'] = google_sheets_import_production_totals($result);

        if ((int) $result['totals']['errors'] !== 0 || (int) $result['totals']['conflicts'] !== 0) {
            throw new RuntimeException('Production import refused: CSV normalization found errors or conflicts.');
        }

        $pdo->commit();
        $result['after_counts'] = google_sheets_import_business_table_counts($pdo);

        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @param array<string, mixed> $result
 */
function google_sheets_import_production_print_summary(array $result): void
{
    fwrite(STDOUT, "Google Sheets CSV production import\n");
    fwrite(STDOUT, 'Generated at: ' . $result['generated_at'] . "\n");
    fwrite(STDOUT, 'APP_ENV=' . $result['gate']['APP_ENV'] . "\n");
    fwrite(STDOUT, 'DB_DATABASE=' . $result['gate']['DB_DATABASE'] . "\n");
    fwrite(STDOUT, 'SELECT_DATABASE=' . $result['gate']['SELECT_DATABASE'] . "\n\n");

    foreach ($result['results'] as $type => $stats) {
        fwrite(
            STDOUT,
            sprintf(
                "%s: rows=%d inserted=%d skipped=%d errors=%d summary_rows=%d\n",
                $type,
                $stats['row_count'],
                $stats['inserted'],
                $stats['skipped'],
                $stats['errors'],
                (int) ($stats['skipped_reasons']['summary_row'] ?? 0)
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

function google_sheets_import_production_main(array $argv): int
{
    $options = google_sheets_import_production_parse_args($argv);
    if ($options['help'] === true) {
        google_sheets_import_production_usage();
        return 0;
    }
    if ($options['confirm'] !== true) {
        google_sheets_import_production_usage();
        fwrite(STDERR, "\nERROR: Production import refused: missing --confirm-production-import.\n");
        return 1;
    }

    $pdo = app_db();
    $result = google_sheets_import_production_run($pdo, (string) $options['base_dir'], true);
    google_sheets_import_production_print_summary($result);

    return 0;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    try {
        exit(google_sheets_import_production_main($argv));
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
