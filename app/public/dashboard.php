<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/html.php';
require_once dirname(__DIR__) . '/src/form.php';
require_once dirname(__DIR__) . '/src/DashboardSummaryService.php';
require_once dirname(__DIR__) . '/src/EntryOwner.php';

require_login();

$pdo = app_db();
$selectedMonth = normalize_month((string) ($_GET['month'] ?? date('Y/m')));
if ($selectedMonth === '') {
    $selectedMonth = date('Y/m');
}
$entryOwnerFilter = trim((string) ($_GET['entry_owner'] ?? 'all'));
if (!in_array($entryOwnerFilter, ['all', EntryOwner::PROFILE_A, EntryOwner::PROFILE_B], true)) {
    $entryOwnerFilter = 'all';
}
$entryOwnerLabel = $entryOwnerFilter === 'all' ? '全部' : EntryOwner::label($entryOwnerFilter);

$dashboardService = new DashboardSummaryService($pdo);
$monthOptions = $dashboardService->monthOptions($selectedMonth, date('Y/m'));
$dashboardSummary = $dashboardService->summary($selectedMonth, $entryOwnerFilter);

$expenseTotal = $dashboardSummary['expense_total'];
$overtime2Days = $dashboardSummary['overtime_2_days'];
$overtime3Days = $dashboardSummary['overtime_3_days'];
$leave = [
    'leave_days' => $dashboardSummary['leave_days'],
    'leave_hours' => $dashboardSummary['leave_hours'],
];
$expectedWorkDays = $dashboardSummary['expected_work_days'];
$workDaysSource = $dashboardSummary['work_days_source'];
$salaryNetTotal = $dashboardSummary['salary_net_total'];
$incomeTotal = $dashboardSummary['income_total'];
$netTotal = $dashboardSummary['net_total'];
$todayExpenseTotal = $dashboardSummary['today']['expense_total'] ?? 0;
$recentExpenses = $dashboardSummary['recent_expenses'];
$recentIncomes = $dashboardSummary['recent_incomes'];
$dashboardRecentExpenses = $recentExpenses;

function dashboard_filter_option(string $value, string $current): string
{
    return $value === $current ? 'selected' : '';
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>主控台</title>
    <link rel="stylesheet" href="/style.css?v=<?= h((string) (filemtime(__DIR__ . '/style.css') ?: 1)) ?>">
</head>
<body>
    <main class="page">
        <div class="page-header">
            <div>
                <h1>儀表板</h1>
                <p>日常支出、收支摘要與 AI 快速輸入入口。</p>
            </div>
            <nav class="nav">
                <a href="/analytics.php">支出總覽</a>
                <a href="/finance.php">收支</a>
                <a href="/ai_parse_logs.php">AI 紀錄</a>
                <a href="/settings.php">設定</a>
            </nav>
        </div>

        <?php if (DemoMode::isEnabled()): ?>
            <section class="demo-tour-panel" aria-labelledby="demo-tour-title">
                <div>
                    <span class="status-badge">建議體驗順序</span>
                    <h2 id="demo-tour-title">這是可編輯、可重置的互動展示</h2>
                    <p>先看收支分析，再編輯一筆合成支出；所有操作都只作用於 <code>personal_accounting_demo</code>。</p>
                </div>
                <div class="demo-tour-actions">
                    <a class="button" href="/analytics.php">查看分析</a>
                    <a class="button secondary" href="/expenses.php">操作支出</a>
                </div>
            </section>
        <?php endif; ?>

        <section class="form-panel dashboard-month-panel">
            <form class="grid-form month-form" method="get">
                <label>帳單月份
                    <select name="month" required onchange="this.form.submit()">
                        <?php foreach ($monthOptions as $month): ?>
                            <option value="<?= h($month) ?>" <?= dashboard_filter_option($month, $selectedMonth) ?>><?= h($month) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>記帳對象
                    <select name="entry_owner" onchange="this.form.submit()">
                        <option value="all" <?= dashboard_filter_option('all', $entryOwnerFilter) ?>>全部</option>
                        <?php foreach (EntryOwner::labels() as $ownerValue => $ownerLabel): ?>
                            <option value="<?= h($ownerValue) ?>" <?= dashboard_filter_option($ownerValue, $entryOwnerFilter) ?>><?= h($ownerLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="form-hint">帳單月份：<?= h($selectedMonth) ?>　記帳對象：<?= h($entryOwnerLabel) ?></div>
                <button class="month-submit" type="submit">套用</button>
            </form>
        </section>

        <div id="dashboard-summary-results">
            <section class="summary-grid dashboard-primary-summary">
                <div class="summary-card primary"><span><?= h($selectedMonth) ?> 支出</span><strong><?= h(format_number_clean($expenseTotal)) ?></strong></div>
                <div class="summary-card"><span>今日消費</span><strong>$<?= h(format_number_clean($todayExpenseTotal)) ?></strong></div>
                <div class="summary-card"><span><?= h($selectedMonth) ?> 結餘</span><strong><?= h(format_number_clean($netTotal)) ?></strong></div>
            </section>

            <details class="form-panel secondary-dashboard-panel">
                <summary>次要統計</summary>
                <div class="summary-grid compact-summary-grid">
                    <div class="summary-card"><span>本月收入總計</span><strong><?= h(format_number_clean($incomeTotal)) ?></strong></div>
                    <div class="summary-card"><span>本月薪資</span><strong><?= h(format_number_clean($salaryNetTotal)) ?></strong></div>
                    <div class="summary-card"><span>加班 2H 天數</span><strong><?= h((string) $overtime2Days) ?></strong></div>
                    <div class="summary-card"><span>加班 3H 天數</span><strong><?= h((string) $overtime3Days) ?></strong></div>
                    <div class="summary-card"><span>請假天數</span><strong><?= h(format_number_clean($leave['leave_days'])) ?></strong></div>
                    <div class="summary-card"><span>請假小時</span><strong><?= h(format_number_clean($leave['leave_hours'])) ?></strong></div>
                    <div class="summary-card"><span>應工作天（<?= h($workDaysSource) ?>）</span><strong><?= h(format_number_clean($expectedWorkDays ?: '0.00')) ?></strong></div>
                </div>
            </details>
        </div>

        <section class="dashboard-action-grid" aria-label="常用操作">
            <?php if (DemoMode::isEnabled()): ?>
                <a class="dashboard-action featured" href="/analytics.php"><strong>互動數據展示</strong><span>使用合成資料查看篩選、統計與明細</span></a>
            <?php else: ?>
                <a class="dashboard-action featured" href="/ai_entry.php"><strong>AI 快速輸入</strong><span>登入後輸入、確認解析內容</span></a>
            <?php endif; ?>
            <a class="dashboard-action" href="/analytics.php"><strong>支出總覽</strong><span>查看統計、篩選與支出明細</span></a>
            <a class="dashboard-action" href="/overtime.php?month=<?= h($selectedMonth) ?>"><strong>加班管理</strong><span>查看本月加班紀錄與彙總</span></a>
            <a class="dashboard-action" href="/ai_parse_logs.php"><strong>AI 紀錄 / Trace</strong><span>查看解析、來源與寫入連結</span></a>
            <a class="dashboard-action" href="/work.php"><strong>工作頁</strong><span>加班、請假與薪資入口</span></a>
        </section>

        <section class="table-panel recent-panel" id="dashboard-recent-expenses">
            <div class="section-title-row">
                <h2>最近 10 筆支出</h2>
                <a class="link" href="/expenses.php?entry_owner=<?= h($entryOwnerFilter) ?>">管理支出</a>
            </div>
            <div class="mobile-transaction-list" aria-label="最近支出手機列表">
                <?php if ($dashboardRecentExpenses === []): ?>
                    <article class="transaction-row"><div class="transaction-main"><strong>尚無支出紀錄</strong><span>最近消費紀錄</span></div></article>
                <?php endif; ?>
                <?php foreach ($dashboardRecentExpenses as $row): ?>
                    <?php $expenseEditUrl = '/expenses.php?edit_id=' . rawurlencode((string) $row['id']); ?>
                    <article class="transaction-row expense-row">
                        <div class="transaction-main">
                            <a class="transaction-edit-link" href="<?= h($expenseEditUrl) ?>" data-ledger-edit="1" data-ledger-type="expense" data-ledger-id="<?= h((string) $row['id']) ?>" aria-label="編輯支出：<?= h($row['item'] !== '' ? $row['item'] : '未命名支出') ?>">
                                <strong><?= h($row["item"] !== "" ? $row["item"] : "未命名支出") ?></strong>
                                <span><?= h($row["record_date"]) ?> · <?= h($row["payment_method"] !== "" ? $row["payment_method"] : "未指定") ?> · <?= h(EntryOwner::label($row["entry_owner"] ?? EntryOwner::PROFILE_A)) ?></span>
                            </a>
                        </div>
                        <div class="transaction-side">
                            <strong>-<?= h(format_number_clean($row["amount"])) ?></strong>
                            <span><?= h($row["accounting_month"]) ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="table-scroll desktop-table">
                <table>
                    <thead><tr><th>日期</th><th>項目</th><th>金額</th><th>付款方式</th><th>帳單月份</th><th>記帳對象</th></tr></thead>
                    <tbody>
                    <?php if ($dashboardRecentExpenses === []): ?>
                        <tr><td colspan="6">尚無支出紀錄。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($dashboardRecentExpenses as $row): ?>
                        <tr>
                            <td><?= h($row["record_date"]) ?></td>
                            <td><a class="table-edit-link" href="<?= h('/expenses.php?edit_id=' . rawurlencode((string) $row['id'])) ?>" data-ledger-edit="1" data-ledger-type="expense" data-ledger-id="<?= h((string) $row['id']) ?>" aria-label="編輯支出：<?= h($row['item'] !== '' ? $row['item'] : '未命名支出') ?>"><?= h($row["item"] !== "" ? $row["item"] : "未命名支出") ?></a></td>
                            <td><?= h(format_number_clean($row["amount"])) ?></td>
                            <td><?= h($row["payment_method"]) ?></td>
                            <td><?= h($row["accounting_month"]) ?></td>
                            <td><?= h(EntryOwner::label($row["entry_owner"] ?? EntryOwner::PROFILE_A)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <details class="table-panel recent-panel secondary-dashboard-panel">
            <summary><?= h($selectedMonth) ?> 收入</summary>
            <div class="mobile-transaction-list" aria-label="最近收入手機列表">
                <article class="transaction-row income-row">
                    <div class="transaction-main">
                        <strong>薪資試算</strong>
                        <span><?= h($selectedMonth) ?> · 薪資明細</span>
                    </div>
                    <div class="transaction-side">
                        <strong>+<?= h(format_number_clean($salaryNetTotal)) ?></strong>
                        <span><?= h($selectedMonth) ?></span>
                    </div>
                </article>
                <?php if ($recentIncomes === []): ?>
                    <article class="transaction-row"><div class="transaction-main"><strong>本月尚無其他收入紀錄</strong><span><?= h($selectedMonth) ?></span></div></article>
                <?php endif; ?>
                <?php foreach ($recentIncomes as $row): ?>
                    <article class="transaction-row income-row">
                        <div class="transaction-main">
                            <strong><?= h($row["source_name"] !== "" ? $row["source_name"] : "未命名收入") ?></strong>
                            <span><?= h($row["record_date"]) ?> · <?= h(($row["account_name"] ?? "") !== "" ? $row["account_name"] : "未指定") ?></span>
                        </div>
                        <div class="transaction-side">
                            <strong>+<?= h(format_number_clean($row["amount"])) ?></strong>
                            <span><?= h($row["accounting_month"]) ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="table-scroll desktop-table">
                <table>
                    <thead><tr><th>日期</th><th>來源</th><th>金額</th><th>帳戶</th><th>月份</th></tr></thead>
                    <tbody>
                    <?php if ($recentIncomes === []): ?>
                        <tr><td colspan="5">本月尚無收入紀錄。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentIncomes as $row): ?>
                        <tr>
                            <td><?= h($row["record_date"]) ?></td>
                            <td><?= h($row["source_name"]) ?></td>
                            <td><?= h(format_number_clean($row["amount"])) ?></td>
                            <td><?= h(($row["account_name"] ?? "") !== "" ? $row["account_name"] : "未指定") ?></td>
                            <td><?= h($row["accounting_month"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>

        <?php render_ledger_edit_modal('dashboard-summary-results,dashboard-recent-expenses', 'dashboard-ajax-status', '儀表板'); ?>
    </main>
    <?php render_mobile_nav('dashboard'); ?>
    <script src="/analytics.js?v=<?= h((string) (filemtime(__DIR__ . '/analytics.js') ?: 1)) ?>" defer></script>
</body>
</html>
