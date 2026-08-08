<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/env.php';

function trace_detail_check_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo "[PASS] {$message}\n";
}

$appEnv = (string) app_env('APP_ENV', '');
$dbDatabase = (string) app_env('DB_DATABASE', '');

trace_detail_check_assert(
    in_array($appEnv, ['testing', 'development'], true),
    'APP_ENV is testing or development'
);
trace_detail_check_assert(
    $dbDatabase === 'personal_accounting_test',
    'DB_DATABASE is personal_accounting_test'
);

$pdo = app_db();
$selectedDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
trace_detail_check_assert(
    $selectedDatabase === 'personal_accounting_test',
    'SELECT DATABASE() is personal_accounting_test'
);

foreach (['ai_parse_logs', 'ai_ledger_links'] as $table) {
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $statement->execute(['table' => $table]);
    trace_detail_check_assert((int) $statement->fetchColumn() === 1, "{$table} table exists in test DB");
}

$root = dirname(__DIR__);
$requiredSnippets = [
    'public/ai_trace_detail.php' => [
        'linksByParseLogId',
        'linksByLedgerRow',
        'ai_ledger_links',
        '不以輸入文字、日期、金額或名稱推測關聯',
    ],
    'public/ai_parse_logs.php' => [
        '/ai_trace_detail.php?log_id=',
    ],
    'public/expenses.php' => [
        'ledger_table=expenses',
    ],
    'public/incomes.php' => [
        'ledger_table=incomes',
    ],
    'public/overtime.php' => [
        'ledger_table=overtime_logs',
    ],
    'public/leave.php' => [
        'ledger_table=leave_logs',
    ],
];

foreach ($requiredSnippets as $relativePath => $snippets) {
    $contents = file_get_contents($root . '/' . $relativePath);
    trace_detail_check_assert(is_string($contents), "{$relativePath} is readable");
    foreach ($snippets as $snippet) {
        trace_detail_check_assert(str_contains((string) $contents, $snippet), "{$relativePath} contains {$snippet}");
    }
}

echo "AI trace detail check passed\n";
