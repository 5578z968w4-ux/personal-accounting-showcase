<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';

$pdo = app_db();
$pdo->exec("SET time_zone = '+08:00'");

$failures = [];

function check_pass(string $message): void
{
    echo '[PASS] ' . $message . "\n";
}

function check_fail(array &$failures, string $message): void
{
    $failures[] = $message;
    echo '[FAIL] ' . $message . "\n";
}

function fetch_count(PDO $pdo, string $sql, array $params): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn();
}

function require_table(PDO $pdo, array &$failures, string $table): void
{
    $count = fetch_count(
        $pdo,
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',
        ['table' => $table]
    );

    $count > 0
        ? check_pass("table exists: {$table}")
        : check_fail($failures, "missing table: {$table}");
}

function require_column(PDO $pdo, array &$failures, string $table, string $column): void
{
    $count = fetch_count(
        $pdo,
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column',
        ['table' => $table, 'column' => $column]
    );

    $count > 0
        ? check_pass("column exists: {$table}.{$column}")
        : check_fail($failures, "missing column: {$table}.{$column}");
}

function require_index(PDO $pdo, array &$failures, string $table, string $index): void
{
    $count = fetch_count(
        $pdo,
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index_name',
        ['table' => $table, 'index_name' => $index]
    );

    $count > 0
        ? check_pass("index exists: {$table}.{$index}")
        : check_fail($failures, "missing index: {$table}.{$index}");
}

function require_foreign_key(PDO $pdo, array &$failures, string $table, string $constraint): void
{
    $count = fetch_count(
        $pdo,
        'SELECT COUNT(*) FROM information_schema.table_constraints
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND constraint_name = :constraint
           AND constraint_type = \'FOREIGN KEY\'',
        ['table' => $table, 'constraint' => $constraint]
    );

    $count > 0
        ? check_pass("foreign key exists: {$table}.{$constraint}")
        : check_fail($failures, "missing foreign key: {$table}.{$constraint}");
}

$appEnv = app_env('APP_ENV', '');
$configuredDatabase = app_env('DB_DATABASE', '');
$dbHost = app_env('DB_HOST', '');
$actualDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

echo "APP_ENV={$appEnv}\n";
echo "DB_DATABASE={$configuredDatabase}\n";
echo "DB_HOST={$dbHost}\n";
echo "SELECT_DATABASE={$actualDatabase}\n";

in_array($appEnv, ['testing', 'development'], true)
    ? check_pass('APP_ENV is testing/development')
    : check_fail($failures, 'APP_ENV must be testing or development');

$configuredDatabase === 'personal_accounting_test'
    ? check_pass('DB_DATABASE is personal_accounting_test')
    : check_fail($failures, 'DB_DATABASE must be personal_accounting_test');

$actualDatabase === 'personal_accounting_test'
    ? check_pass('SELECT DATABASE() is personal_accounting_test')
    : check_fail($failures, 'SELECT DATABASE() must be personal_accounting_test');

foreach (['ai_parse_logs', 'ai_ledger_links', 'expenses', 'incomes', 'overtime_logs', 'leave_logs'] as $table) {
    require_table($pdo, $failures, $table);
}

foreach ([
    'id',
    'ai_parse_log_id',
    'ledger_table',
    'ledger_id',
    'action',
    'source',
    'raw_input_snapshot',
    'parsed_type_snapshot',
    'parsed_json_snapshot',
    'user_name',
    'created_at',
] as $column) {
    require_column($pdo, $failures, 'ai_ledger_links', $column);
}

require_column($pdo, $failures, 'overtime_logs', 'source');
require_column($pdo, $failures, 'leave_logs', 'source');
require_column($pdo, $failures, 'leave_logs', 'raw_input');

require_index($pdo, $failures, 'ai_ledger_links', 'idx_ai_ledger_links_log');
require_index($pdo, $failures, 'ai_ledger_links', 'idx_ai_ledger_links_ledger');
require_index($pdo, $failures, 'ai_ledger_links', 'idx_ai_ledger_links_source_created');
require_index($pdo, $failures, 'overtime_logs', 'idx_overtime_logs_source');
require_index($pdo, $failures, 'leave_logs', 'idx_leave_logs_source');

require_foreign_key($pdo, $failures, 'ai_ledger_links', 'fk_ai_ledger_links_log');

if ($failures) {
    echo 'Schema check failed: ' . count($failures) . " issue(s)\n";
    exit(1);
}

echo "Schema check completed\n";
