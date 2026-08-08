<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/IncomeAnalyticsService.php';

function income_analytics_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE incomes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    record_date TEXT,
    source_name TEXT,
    amount REAL,
    account_id INTEGER,
    account_name TEXT,
    accounting_month TEXT,
    category TEXT,
    is_deleted INTEGER DEFAULT 0,
    deleted_at TEXT
)');

$insert = $pdo->prepare(
    'INSERT INTO incomes (record_date, source_name, amount, account_id, account_name, accounting_month, category, is_deleted, deleted_at)
     VALUES (:record_date, :source_name, :amount, :account_id, :account_name, :accounting_month, :category, :is_deleted, :deleted_at)'
);
foreach ([
    ['2026-06-05', '薪資', 50000, 1, '銀行', '2026/06', '薪資', 0, null],
    ['2026-06-15', '退款', 100, null, '', '2026/06', '', 0, null],
    ['2026-06-20', '已刪除收入', 999, 1, '銀行', '2026/06', '其他', 1, null],
    ['2026-07-01', '七月獎金', 1000, 1, '銀行', '2026/07', '獎金', 0, null],
] as [$recordDate, $sourceName, $amount, $accountId, $accountName, $month, $category, $isDeleted, $deletedAt]) {
    $insert->execute([
        'record_date' => $recordDate,
        'source_name' => $sourceName,
        'amount' => $amount,
        'account_id' => $accountId,
        'account_name' => $accountName,
        'accounting_month' => $month,
        'category' => $category,
        'is_deleted' => $isDeleted,
        'deleted_at' => $deletedAt,
    ]);
}

$service = new IncomeAnalyticsService($pdo);
$june = $service->search(
    IncomeAnalyticsService::PERIOD_ACCOUNTING_MONTH,
    '2026/06',
    '2026/06'
);
income_analytics_assert((int) $june['row_count'] === 2, 'Income search should exclude deleted rows');
income_analytics_assert((float) $june['total_amount'] === 50100.0, 'Income total mismatch');
income_analytics_assert(count($june['rows']) === 2, 'Income rows mismatch');

$uncategorized = $service->search(
    IncomeAnalyticsService::PERIOD_ACCOUNTING_MONTH,
    '2026/06',
    '2026/06',
    '',
    IncomeAnalyticsService::CATEGORY_UNCATEGORIZED
);
income_analytics_assert((int) $uncategorized['row_count'] === 1, 'Income uncategorized filter mismatch');

$keyword = $service->search(
    IncomeAnalyticsService::PERIOD_RECORD_DATE,
    '2026-06-01',
    '2026-06-30',
    '薪資'
);
income_analytics_assert((int) $keyword['row_count'] === 1, 'Income keyword filter mismatch');

$categories = $service->categoryOptions(
    IncomeAnalyticsService::PERIOD_ACCOUNTING_MONTH,
    '2026/06',
    '2026/06'
);
income_analytics_assert(in_array('薪資', $categories, true), 'Income category option missing');
income_analytics_assert(in_array(IncomeAnalyticsService::CATEGORY_UNCATEGORIZED, $categories, true), 'Income uncategorized option missing');
income_analytics_assert(!in_array('其他', $categories, true), 'Deleted income category should be excluded');

echo "IncomeAnalyticsServiceTest passed\n";
