<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ExpenseKeywordAnalyticsService.php';

function expense_keyword_analytics_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expense_keyword_analytics_near(float $actual, float $expected, string $message): void
{
    if (abs($actual - $expected) > 0.001) {
        throw new RuntimeException($message . ' actual=' . $actual . ' expected=' . $expected);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    record_date TEXT,
    item TEXT,
    amount REAL,
    payment_method_id INTEGER,
    payment_method TEXT,
    accounting_month TEXT,
    category TEXT,
    entry_owner TEXT DEFAULT "profile_a",
    is_deleted INTEGER DEFAULT 0,
    deleted_at TEXT
)');

$insert = $pdo->prepare(
    'INSERT INTO expenses (record_date, item, amount, payment_method_id, payment_method, accounting_month, category, entry_owner, is_deleted, deleted_at)
     VALUES (:record_date, :item, :amount, :payment_method_id, :payment_method, :accounting_month, :category, :entry_owner, :is_deleted, :deleted_at)'
);
foreach ([
    ['2026-05-31', '五月底午餐', 100, 1, '現金', '2026/06', '餐飲', 'profile_a', 0, null],
    ['2026-06-01', '捷運', 50, 1, '現金', '2026/06', '交通', 'profile_a', 0, null],
    ['2026-06-15', '展示對象 B午餐', 80, 1, '現金', '2026/06', '餐飲', 'profile_b', 0, null],
    ['2026-06-20', '空白分類', 20, 1, '現金', '2026/06', '', 'profile_a', 0, null],
    ['2026-06-21', 'NULL 分類', 30, 1, '現金', '2026/06', null, 'profile_a', 0, null],
    ['2026-06-30', '七月帳單購物', 200, 2, '展示方式 C', '2026/07', '購物', 'profile_a', 0, null],
    ['2026-07-01', '七月午餐', 300, 2, '展示方式 C', '2026/07', '餐飲', 'profile_a', 0, null],
    ['2026-06-10', '已刪除娛樂', 999, 1, '現金', '2026/06', '娛樂', 'profile_a', 1, null],
    ['2026-06-11', 'deleted_at 醫療', 999, 1, '現金', '2026/06', '醫療', 'profile_a', 0, '2026-06-11 10:00:00'],
] as [$recordDate, $item, $amount, $paymentMethodId, $paymentMethod, $month, $category, $entryOwner, $isDeleted, $deletedAt]) {
    $insert->execute([
        'record_date' => $recordDate,
        'item' => $item,
        'amount' => $amount,
        'payment_method_id' => $paymentMethodId,
        'payment_method' => $paymentMethod,
        'accounting_month' => $month,
        'category' => $category,
        'entry_owner' => $entryOwner,
        'is_deleted' => $isDeleted,
        'deleted_at' => $deletedAt,
    ]);
}

for ($index = 1; $index <= 105; $index++) {
    $insert->execute([
        'record_date' => '2026-08-01',
        'item' => '八月分頁測試 ' . $index,
        'amount' => 1,
        'payment_method_id' => 1,
        'payment_method' => '現金',
        'accounting_month' => '2026/08',
        'category' => '測試',
        'entry_owner' => 'profile_a',
        'is_deleted' => 0,
        'deleted_at' => null,
    ]);
}

$service = new ExpenseKeywordAnalyticsService($pdo);

$billingJune = $service->search(
    ExpenseKeywordAnalyticsService::PERIOD_ACCOUNTING_MONTH,
    '2026/06',
    '2026/06'
);
expense_keyword_analytics_near((float) $billingJune['total_amount'], 280, 'Billing-month total mismatch');
expense_keyword_analytics_assert((int) $billingJune['row_count'] === 5, 'Billing month should include May record date assigned to June');
expense_keyword_analytics_assert(
    !in_array('七月帳單購物', array_column($billingJune['rows'], 'item'), true),
    'Billing month should exclude a June record date assigned to July'
);

$recordDateJune = $service->search(
    ExpenseKeywordAnalyticsService::PERIOD_RECORD_DATE,
    '2026-06-01',
    '2026-06-30'
);
expense_keyword_analytics_near((float) $recordDateJune['total_amount'], 380, 'Record-date total mismatch');
expense_keyword_analytics_assert((int) $recordDateJune['row_count'] === 5, 'Record-date range should use actual expense dates');
expense_keyword_analytics_assert(
    in_array('七月帳單購物', array_column($recordDateJune['rows'], 'item'), true),
    'Record-date range should include a June expense assigned to the July bill'
);
expense_keyword_analytics_assert(
    !in_array('五月底午餐', array_column($recordDateJune['rows'], 'item'), true),
    'Record-date range should exclude May expenses even when assigned to the June bill'
);

$billingRange = $service->search(
    ExpenseKeywordAnalyticsService::PERIOD_ACCOUNTING_MONTH,
    '2026/06',
    '2026/07'
);
expense_keyword_analytics_near((float) $billingRange['total_amount'], 780, 'Billing-month range total mismatch');
expense_keyword_analytics_assert((int) $billingRange['row_count'] === 7, 'Billing-month range count mismatch');

$reversedRange = $service->search(
    ExpenseKeywordAnalyticsService::PERIOD_RECORD_DATE,
    '2026-06-30',
    '2026-06-01'
);
expense_keyword_analytics_assert($reversedRange['period_from'] === '2026-06-01', 'Reversed start date should normalize');
expense_keyword_analytics_assert($reversedRange['period_to'] === '2026-06-30', 'Reversed end date should normalize');
expense_keyword_analytics_near((float) $reversedRange['total_amount'], 380, 'Reversed date range total mismatch');

$foodOnly = $service->search(
    ExpenseKeywordAnalyticsService::PERIOD_ACCOUNTING_MONTH,
    '2026/06',
    '2026/06',
    '',
    'all',
    0,
    '餐飲'
);
expense_keyword_analytics_near((float) $foodOnly['total_amount'], 180, 'Category total mismatch');
expense_keyword_analytics_assert((int) $foodOnly['row_count'] === 2, 'Category should use exact matching');

$uncategorized = $service->search(
    ExpenseKeywordAnalyticsService::PERIOD_ACCOUNTING_MONTH,
    '2026/06',
    '2026/06',
    '',
    'all',
    0,
    ExpenseKeywordAnalyticsService::CATEGORY_UNCATEGORIZED
);
expense_keyword_analytics_near((float) $uncategorized['total_amount'], 50, 'Uncategorized total mismatch');
expense_keyword_analytics_assert((int) $uncategorized['row_count'] === 2, 'Blank and NULL categories should be grouped');

$ownerOnly = $service->search(
    ExpenseKeywordAnalyticsService::PERIOD_ACCOUNTING_MONTH,
    '2026/06',
    '2026/06',
    '',
    'profile_b'
);
expense_keyword_analytics_near((float) $ownerOnly['total_amount'], 80, 'Owner-only total mismatch');

$paymentAndKeyword = $service->search(
    ExpenseKeywordAnalyticsService::PERIOD_RECORD_DATE,
    '2026-06-01',
    '2026-06-30',
    '購物',
    'profile_a',
    2,
    '購物'
);
expense_keyword_analytics_near((float) $paymentAndKeyword['total_amount'], 200, 'Combined filter total mismatch');
expense_keyword_analytics_assert((int) $paymentAndKeyword['row_count'] === 1, 'Combined filter count mismatch');

$billingCategories = $service->categoryOptions(
    ExpenseKeywordAnalyticsService::PERIOD_ACCOUNTING_MONTH,
    '2026/06',
    '2026/06'
);
foreach (['交通', '餐飲', ExpenseKeywordAnalyticsService::CATEGORY_UNCATEGORIZED] as $category) {
    expense_keyword_analytics_assert(in_array($category, $billingCategories, true), $category . ' category option missing');
}
expense_keyword_analytics_assert(!in_array('購物', $billingCategories, true), 'Out-of-period category should be excluded');
expense_keyword_analytics_assert(!in_array('娛樂', $billingCategories, true), 'Deleted category should be excluded');
expense_keyword_analytics_assert(!in_array('醫療', $billingCategories, true), 'deleted_at category should be excluded');

$recordDateCategories = $service->categoryOptions(
    ExpenseKeywordAnalyticsService::PERIOD_RECORD_DATE,
    '2026-06-01',
    '2026-06-30'
);
expense_keyword_analytics_assert(in_array('購物', $recordDateCategories, true), 'Record-date category options should follow the selected range');

$invalidOwnerFallsBack = $service->search(
    ExpenseKeywordAnalyticsService::PERIOD_ACCOUNTING_MONTH,
    '2026/06',
    '2026/06',
    '',
    'invalid-owner'
);
expense_keyword_analytics_assert((int) $invalidOwnerFallsBack['row_count'] === 5, 'Invalid owner should fall back to all');

$invalidPeriodRejected = false;
try {
    $service->search(ExpenseKeywordAnalyticsService::PERIOD_RECORD_DATE, '2026-02-30', '2026-03-01');
} catch (InvalidArgumentException) {
    $invalidPeriodRejected = true;
}
expense_keyword_analytics_assert($invalidPeriodRejected, 'Invalid dates must be rejected');

$pagedAugust = $service->search(
    ExpenseKeywordAnalyticsService::PERIOD_ACCOUNTING_MONTH,
    '2026/08',
    '2026/08',
    '',
    'all',
    0,
    ExpenseKeywordAnalyticsService::CATEGORY_ALL,
    100,
    100
);
expense_keyword_analytics_assert((int) $pagedAugust['row_count'] === 105, 'Pagination should retain the full matching count');
expense_keyword_analytics_assert(count($pagedAugust['rows']) === 5, 'Pagination offset should expose older matching rows');

echo "ExpenseKeywordAnalyticsServiceTest passed\n";
