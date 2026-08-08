<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/html.php';
require_once dirname(__DIR__) . '/src/form.php';
require_once dirname(__DIR__) . '/src/DashboardSummaryService.php';
require_once dirname(__DIR__) . '/src/EntryOwner.php';
require_once dirname(__DIR__) . '/src/ExpenseKeywordAnalyticsService.php';
require_once dirname(__DIR__) . '/src/IncomeAnalyticsService.php';

require_login();

function analytics_normalize_date(string $value, string $fallback): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Taipei'));
    return $date !== false && $date->format('Y-m-d') === $value ? $value : $fallback;
}

function analytics_filter_option(string $value, string $current): string
{
    return $value === $current ? 'selected' : '';
}

function analytics_page_number(mixed $value): int
{
    $page = filter_var($value, FILTER_VALIDATE_INT);
    return $page === false || $page === null ? 1 : max(1, $page);
}

function analytics_page_url(string $pageKey, int $page): string
{
    $allowedKeys = [
        'period_type', 'month_from', 'month_to', 'date_from', 'date_to',
        'entry_owner', 'payment_method_id', 'category', 'keyword',
        'income_category', 'income_keyword',
    ];
    $query = [];
    foreach ($allowedKeys as $key) {
        if (isset($_GET[$key]) && !is_array($_GET[$key])) {
            $query[$key] = (string) $_GET[$key];
        }
    }
    $query[$pageKey] = $page;

    return '/analytics.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

$pdo = app_db();
$periodType = trim((string) ($_GET['period_type'] ?? ExpenseKeywordAnalyticsService::PERIOD_ACCOUNTING_MONTH));
if (!in_array($periodType, [
    ExpenseKeywordAnalyticsService::PERIOD_ACCOUNTING_MONTH,
    ExpenseKeywordAnalyticsService::PERIOD_RECORD_DATE,
], true)) {
    $periodType = ExpenseKeywordAnalyticsService::PERIOD_ACCOUNTING_MONTH;
}

$legacyMonth = normalize_month((string) ($_GET['month'] ?? ''));
$monthFrom = normalize_month((string) ($_GET['month_from'] ?? $legacyMonth));
$monthTo = normalize_month((string) ($_GET['month_to'] ?? $legacyMonth));
if ($monthFrom === '') {
    $monthFrom = date('Y/m');
}
if ($monthTo === '') {
    $monthTo = date('Y/m');
}
if ($monthFrom > $monthTo) {
    [$monthFrom, $monthTo] = [$monthTo, $monthFrom];
}

$dateFrom = analytics_normalize_date((string) ($_GET['date_from'] ?? ''), date('Y-m-01'));
$dateTo = analytics_normalize_date((string) ($_GET['date_to'] ?? ''), date('Y-m-d'));
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$periodFrom = $periodType === ExpenseKeywordAnalyticsService::PERIOD_RECORD_DATE ? $dateFrom : $monthFrom;
$periodTo = $periodType === ExpenseKeywordAnalyticsService::PERIOD_RECORD_DATE ? $dateTo : $monthTo;
$keyword = trim((string) ($_GET['keyword'] ?? ''));
$entryOwnerFilter = trim((string) ($_GET['entry_owner'] ?? 'all'));
if (!in_array($entryOwnerFilter, ['all', EntryOwner::PROFILE_A, EntryOwner::PROFILE_B], true)) {
    $entryOwnerFilter = 'all';
}
$paymentMethodFilter = filter_input(INPUT_GET, 'payment_method_id', FILTER_VALIDATE_INT);
$paymentMethodFilter = $paymentMethodFilter === false || $paymentMethodFilter === null ? 0 : max(0, $paymentMethodFilter);
$categoryFilter = trim((string) ($_GET['category'] ?? ExpenseKeywordAnalyticsService::CATEGORY_ALL));
$incomeKeyword = trim((string) ($_GET['income_keyword'] ?? ''));
$incomeCategoryFilter = trim((string) ($_GET['income_category'] ?? IncomeAnalyticsService::CATEGORY_ALL));

$dashboardService = new DashboardSummaryService($pdo);
$monthOptions = $dashboardService->monthOptions($monthFrom, date('Y/m'));
if (!in_array($monthTo, $monthOptions, true)) {
    $monthOptions[] = $monthTo;
    rsort($monthOptions);
}
$paymentMethods = $pdo->query(
    'SELECT id, name FROM payment_methods ORDER BY sort_order, id'
)->fetchAll();
$paymentMethodLabels = [];
foreach ($paymentMethods as $method) {
    $paymentMethodLabels[(int) $method['id']] = (string) $method['name'];
}
if ($paymentMethodFilter > 0 && !isset($paymentMethodLabels[$paymentMethodFilter])) {
    $paymentMethodFilter = 0;
}

$analyticsService = new ExpenseKeywordAnalyticsService($pdo);
$categoryOptions = $analyticsService->categoryOptions($periodType, $periodFrom, $periodTo);
if ($categoryFilter !== ExpenseKeywordAnalyticsService::CATEGORY_ALL
    && !in_array($categoryFilter, $categoryOptions, true)) {
    $categoryFilter = ExpenseKeywordAnalyticsService::CATEGORY_ALL;
}

$incomeService = new IncomeAnalyticsService($pdo);
$incomeCategoryOptions = $incomeService->categoryOptions($periodType, $periodFrom, $periodTo);
if ($incomeCategoryFilter !== IncomeAnalyticsService::CATEGORY_ALL
    && !in_array($incomeCategoryFilter, $incomeCategoryOptions, true)) {
    $incomeCategoryFilter = IncomeAnalyticsService::CATEGORY_ALL;
}

$pageSize = 100;
$page = analytics_page_number($_GET['page'] ?? 1);
$result = $analyticsService->search(
    $periodType,
    $periodFrom,
    $periodTo,
    $keyword,
    $entryOwnerFilter,
    $paymentMethodFilter,
    $categoryFilter,
    $pageSize,
    ($page - 1) * $pageSize
);
$pageCount = max(1, (int) ceil($result['row_count'] / $pageSize));
if ($page > $pageCount) {
    $page = $pageCount;
    $result = $analyticsService->search(
        $periodType,
        $periodFrom,
        $periodTo,
        $keyword,
        $entryOwnerFilter,
        $paymentMethodFilter,
        $categoryFilter,
        $pageSize,
        ($page - 1) * $pageSize
    );
}
$rows = $result['rows'];
$incomePage = analytics_page_number($_GET['income_page'] ?? 1);
$incomeResult = $incomeService->search(
    $periodType,
    $periodFrom,
    $periodTo,
    $incomeKeyword,
    $incomeCategoryFilter,
    $pageSize,
    ($incomePage - 1) * $pageSize
);
$incomePageCount = max(1, (int) ceil($incomeResult['row_count'] / $pageSize));
if ($incomePage > $incomePageCount) {
    $incomePage = $incomePageCount;
    $incomeResult = $incomeService->search(
        $periodType,
        $periodFrom,
        $periodTo,
        $incomeKeyword,
        $incomeCategoryFilter,
        $pageSize,
        ($incomePage - 1) * $pageSize
    );
}
$incomeRows = $incomeResult['rows'];
$periodTitle = $periodType === ExpenseKeywordAnalyticsService::PERIOD_RECORD_DATE
    ? '消費日 ' . $periodFrom . '～' . $periodTo
    : '帳單月 ' . str_replace('/', '-', $periodFrom) . '～' . str_replace('/', '-', $periodTo);
$categoryLabel = match ($categoryFilter) {
    ExpenseKeywordAnalyticsService::CATEGORY_ALL => '全部分類',
    ExpenseKeywordAnalyticsService::CATEGORY_UNCATEGORIZED => '未分類',
    default => $categoryFilter,
};
$conditionParts = [
    $periodTitle,
    $entryOwnerFilter === 'all' ? '全部消費人' : EntryOwner::label($entryOwnerFilter),
    $paymentMethodFilter === 0 ? '全部支付方式' : ($paymentMethodLabels[$paymentMethodFilter] ?? '未知支付方式'),
    $categoryLabel,
];
if ($keyword !== '') {
    $conditionParts[] = $keyword;
}
$conditionTitle = implode('｜', $conditionParts);
$incomeCategoryLabel = match ($incomeCategoryFilter) {
    IncomeAnalyticsService::CATEGORY_ALL => '全部分類',
    IncomeAnalyticsService::CATEGORY_UNCATEGORIZED => '未分類',
    default => $incomeCategoryFilter,
};
$incomeConditionParts = [$periodTitle, $incomeCategoryLabel];
if ($incomeKeyword !== '') {
    $incomeConditionParts[] = $incomeKeyword;
}
$incomeConditionTitle = implode('｜', $incomeConditionParts);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>支出總覽</title>
    <link rel="stylesheet" href="/style.css?v=<?= h((string) (filemtime(__DIR__ . '/style.css') ?: 1)) ?>">
</head>
<body>
    <main class="page">
        <div class="page-header">
            <div>
                <h1>支出總覽</h1>
                <p>集中查看收支統計與明細；點擊支出或收入項目即可在彈窗編輯。</p>
            </div>
            <nav class="nav">
                <a href="/dashboard.php">主控台</a>
                <a href="/expenses.php">支出管理</a>
                <a href="/finance.php">收支</a>
            </nav>
        </div>

        <section class="form-panel dashboard-month-panel">
            <form class="grid-form month-form" method="get">
                <label>統計口徑
                    <select name="period_type" onchange="this.form.submit()">
                        <option value="<?= h(ExpenseKeywordAnalyticsService::PERIOD_ACCOUNTING_MONTH) ?>" <?= analytics_filter_option(ExpenseKeywordAnalyticsService::PERIOD_ACCOUNTING_MONTH, $periodType) ?>>帳單月份區間</option>
                        <option value="<?= h(ExpenseKeywordAnalyticsService::PERIOD_RECORD_DATE) ?>" <?= analytics_filter_option(ExpenseKeywordAnalyticsService::PERIOD_RECORD_DATE, $periodType) ?>>實際消費日期區間</option>
                    </select>
                </label>
                <?php if ($periodType === ExpenseKeywordAnalyticsService::PERIOD_RECORD_DATE): ?>
                    <label>開始消費日
                        <input type="date" name="date_from" value="<?= h($dateFrom) ?>" required>
                    </label>
                    <label>結束消費日
                        <input type="date" name="date_to" value="<?= h($dateTo) ?>" required>
                    </label>
                <?php else: ?>
                    <label>開始帳單月
                        <select name="month_from" required>
                            <?php foreach ($monthOptions as $month): ?>
                                <option value="<?= h($month) ?>" <?= analytics_filter_option($month, $monthFrom) ?>><?= h($month) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>結束帳單月
                        <select name="month_to" required>
                            <?php foreach ($monthOptions as $month): ?>
                                <option value="<?= h($month) ?>" <?= analytics_filter_option($month, $monthTo) ?>><?= h($month) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <label>消費人
                    <select name="entry_owner">
                        <option value="all" <?= analytics_filter_option('all', $entryOwnerFilter) ?>>全部</option>
                        <?php foreach (EntryOwner::labels() as $ownerValue => $ownerLabel): ?>
                            <option value="<?= h($ownerValue) ?>" <?= analytics_filter_option($ownerValue, $entryOwnerFilter) ?>><?= h($ownerLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>支付方式
                    <select name="payment_method_id">
                        <option value="0">全部</option>
                        <?php foreach ($paymentMethods as $method): ?>
                            <option value="<?= h((string) $method['id']) ?>" <?= (int) $method['id'] === $paymentMethodFilter ? 'selected' : '' ?>><?= h((string) $method['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>分類
                    <select name="category">
                        <option value="<?= h(ExpenseKeywordAnalyticsService::CATEGORY_ALL) ?>" <?= analytics_filter_option(ExpenseKeywordAnalyticsService::CATEGORY_ALL, $categoryFilter) ?>>全部</option>
                        <?php foreach ($categoryOptions as $category): ?>
                            <option value="<?= h($category) ?>" <?= analytics_filter_option($category, $categoryFilter) ?>><?= h($category === ExpenseKeywordAnalyticsService::CATEGORY_UNCATEGORIZED ? '未分類' : $category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>支出關鍵字
                    <input name="keyword" value="<?= h($keyword) ?>" placeholder="例如：午餐" autocomplete="off">
                </label>
                <label>收入分類
                    <select name="income_category">
                        <option value="<?= h(IncomeAnalyticsService::CATEGORY_ALL) ?>" <?= analytics_filter_option(IncomeAnalyticsService::CATEGORY_ALL, $incomeCategoryFilter) ?>>全部</option>
                        <?php foreach ($incomeCategoryOptions as $incomeCategory): ?>
                            <option value="<?= h($incomeCategory) ?>" <?= analytics_filter_option($incomeCategory, $incomeCategoryFilter) ?>><?= h($incomeCategory === IncomeAnalyticsService::CATEGORY_UNCATEGORIZED ? '未分類' : $incomeCategory) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>收入關鍵字
                    <input name="income_keyword" value="<?= h($incomeKeyword) ?>" placeholder="例如：薪資" autocomplete="off">
                </label>
                <div class="form-hint">支出條件：<?= h($conditionTitle) ?>；收入條件：<?= h($incomeConditionTitle) ?>。只顯示 active 資料。</div>
                <button class="analytics-submit" type="submit">搜尋</button>
            </form>
        </section>

        <div id="analytics-results">
            <section class="summary-grid dashboard-primary-summary">
                <div class="summary-card primary"><span>符合總額</span><strong><?= h(format_number_clean($result['total_amount'])) ?></strong></div>
                <div class="summary-card"><span>支出筆數</span><strong><?= h((string) $result['row_count']) ?></strong></div>
                <div class="summary-card"><span>平均金額</span><strong><?= h(format_number_clean($result['average_amount'])) ?></strong></div>
            </section>

            <section class="table-panel recent-panel">
                <div class="section-title-row">
                    <h2><?= h($conditionTitle) ?> 支出明細</h2>
                    <a class="link" href="/expenses.php">新增／管理支出</a>
                </div>
                <div class="mobile-transaction-list" aria-label="支出手機列表">
                    <?php if ($rows === []): ?>
                        <article class="transaction-row"><div class="transaction-main"><strong>沒有符合的支出</strong><span><?= h($conditionTitle) ?></span></div></article>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $expenseEditUrl = '/expenses.php?edit_id=' . rawurlencode((string) $row['id']); ?>
                        <article class="transaction-row expense-row">
                            <div class="transaction-main">
                                <a class="transaction-edit-link" href="<?= h($expenseEditUrl) ?>" data-ledger-edit="1" data-ledger-type="expense" data-ledger-id="<?= h((string) $row['id']) ?>" aria-label="編輯支出：<?= h($row['item'] !== '' ? $row['item'] : '未命名支出') ?>">
                                    <strong><?= h($row["item"] !== "" ? $row["item"] : "未命名支出") ?></strong>
                                    <span><?= h($row["record_date"]) ?> · <?= h($row["payment_method"] !== "" ? $row["payment_method"] : "未指定") ?> · <?= h(EntryOwner::label($row["entry_owner"] ?? EntryOwner::PROFILE_A)) ?> · <?= h(trim((string) ($row["category"] ?? '')) !== '' ? trim((string) $row["category"]) : "未分類") ?></span>
                                </a>
                            </div>
                            <div class="transaction-side">
                                <strong>-<?= h(format_number_clean($row["amount"])) ?></strong>
                                <span><?= h($row["accounting_month"]) ?></span>
                                <a class="transaction-edit-action" href="<?= h($expenseEditUrl) ?>" data-ledger-edit="1" data-ledger-type="expense" data-ledger-id="<?= h((string) $row['id']) ?>">編輯</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="table-scroll desktop-table">
                    <table>
                        <thead><tr><th>消費日</th><th>項目</th><th>分類</th><th>支付方式</th><th>消費人</th><th>金額</th><th>帳單月</th></tr></thead>
                        <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="7">沒有符合的支出。</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= h($row["record_date"]) ?></td>
                                <td><a class="table-edit-link" href="<?= h('/expenses.php?edit_id=' . rawurlencode((string) $row['id'])) ?>" data-ledger-edit="1" data-ledger-type="expense" data-ledger-id="<?= h((string) $row['id']) ?>" aria-label="編輯支出：<?= h($row['item'] !== '' ? $row['item'] : '未命名支出') ?>"><?= h($row["item"] !== "" ? $row["item"] : "未命名支出") ?></a></td>
                                <td><?= h(trim((string) ($row["category"] ?? '')) !== '' ? trim((string) $row["category"]) : "未分類") ?></td>
                                <td><?= h($row["payment_method"] !== "" ? $row["payment_method"] : "未指定") ?></td>
                                <td><?= h(EntryOwner::label($row["entry_owner"] ?? EntryOwner::PROFILE_A)) ?></td>
                                <td><?= h(format_number_clean($row["amount"])) ?></td>
                                <td><?= h($row["accounting_month"]) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pageCount > 1): ?>
                    <nav class="pagination" aria-label="分析支出明細分頁">
                        <?php if ($page > 1): ?><a class="button secondary" href="<?= h(analytics_page_url('page', $page - 1)) ?>">上一頁</a><?php endif; ?>
                        <span>第 <?= h((string) $page) ?> / <?= h((string) $pageCount) ?> 頁</span>
                        <?php if ($page < $pageCount): ?><a class="button" href="<?= h(analytics_page_url('page', $page + 1)) ?>">下一頁</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section>

            <section class="summary-grid dashboard-primary-summary income-summary">
                <div class="summary-card primary"><span>符合收入總額</span><strong><?= h(format_number_clean($incomeResult['total_amount'])) ?></strong></div>
                <div class="summary-card"><span>收入筆數</span><strong><?= h((string) $incomeResult['row_count']) ?></strong></div>
                <div class="summary-card"><span>收入平均金額</span><strong><?= h(format_number_clean($incomeResult['average_amount'])) ?></strong></div>
            </section>

            <section class="table-panel recent-panel">
                <div class="section-title-row">
                    <h2><?= h($incomeConditionTitle) ?> 收入明細</h2>
                    <a class="link" href="/incomes.php">新增／管理收入</a>
                </div>
                <div class="mobile-transaction-list" aria-label="收入手機列表">
                    <?php if ($incomeRows === []): ?>
                        <article class="transaction-row"><div class="transaction-main"><strong>沒有符合的收入</strong><span><?= h($incomeConditionTitle) ?></span></div></article>
                    <?php endif; ?>
                    <?php foreach ($incomeRows as $row): ?>
                        <?php $incomeEditUrl = '/incomes.php?edit_id=' . rawurlencode((string) $row['id']); ?>
                        <article class="transaction-row income-row">
                            <div class="transaction-main">
                                <a class="transaction-edit-link" href="<?= h($incomeEditUrl) ?>" data-ledger-edit="1" data-ledger-type="income" data-ledger-id="<?= h((string) $row['id']) ?>" aria-label="編輯收入：<?= h($row['source_name'] !== '' ? $row['source_name'] : '未命名收入') ?>">
                                    <strong><?= h($row["source_name"] !== "" ? $row["source_name"] : "未命名收入") ?></strong>
                                    <span><?= h($row["record_date"]) ?> · <?= h(($row["account_name"] ?? "") !== "" ? $row["account_name"] : "未指定帳戶") ?> · <?= h(trim((string) ($row["category"] ?? '')) !== '' ? trim((string) $row["category"]) : "未分類") ?></span>
                                </a>
                            </div>
                            <div class="transaction-side">
                                <strong>+<?= h(format_number_clean($row["amount"])) ?></strong>
                                <span><?= h($row["accounting_month"]) ?></span>
                                <a class="transaction-edit-action" href="<?= h($incomeEditUrl) ?>" data-ledger-edit="1" data-ledger-type="income" data-ledger-id="<?= h((string) $row['id']) ?>">編輯</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="table-scroll desktop-table">
                    <table>
                        <thead><tr><th>收入日</th><th>來源</th><th>分類</th><th>帳戶</th><th>金額</th><th>月份</th></tr></thead>
                        <tbody>
                        <?php if ($incomeRows === []): ?>
                            <tr><td colspan="6">沒有符合的收入。</td></tr>
                        <?php endif; ?>
                        <?php foreach ($incomeRows as $row): ?>
                            <tr>
                                <td><?= h($row["record_date"]) ?></td>
                                <td><a class="table-edit-link" href="<?= h('/incomes.php?edit_id=' . rawurlencode((string) $row['id'])) ?>" data-ledger-edit="1" data-ledger-type="income" data-ledger-id="<?= h((string) $row['id']) ?>" aria-label="編輯收入：<?= h($row['source_name'] !== '' ? $row['source_name'] : '未命名收入') ?>"><?= h($row["source_name"] !== "" ? $row["source_name"] : "未命名收入") ?></a></td>
                                <td><?= h(trim((string) ($row["category"] ?? '')) !== '' ? trim((string) $row["category"]) : "未分類") ?></td>
                                <td><?= h(($row["account_name"] ?? "") !== "" ? $row["account_name"] : "未指定帳戶") ?></td>
                                <td><?= h(format_number_clean($row["amount"])) ?></td>
                                <td><?= h($row["accounting_month"]) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($incomePageCount > 1): ?>
                    <nav class="pagination" aria-label="分析收入明細分頁">
                        <?php if ($incomePage > 1): ?><a class="button secondary" href="<?= h(analytics_page_url('income_page', $incomePage - 1)) ?>">上一頁</a><?php endif; ?>
                        <span>第 <?= h((string) $incomePage) ?> / <?= h((string) $incomePageCount) ?> 頁</span>
                        <?php if ($incomePage < $incomePageCount): ?><a class="button" href="<?= h(analytics_page_url('income_page', $incomePage + 1)) ?>">下一頁</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section>
        </div>

        <?php render_ledger_edit_modal('analytics-results', 'analytics-ajax-status', '分析結果'); ?>
    </main>
    <?php render_mobile_nav('finance'); ?>
    <script src="/analytics.js?v=<?= h((string) (filemtime(__DIR__ . '/analytics.js') ?: 1)) ?>" defer></script>
</body>
</html>
