<?php

declare(strict_types=1);

function backend_ui_feedback_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
require_once $root . '/src/html.php';
require_once $root . '/src/form.php';

$dashboard = file_get_contents($root . '/public/dashboard.php');
$finance = file_get_contents($root . '/public/finance.php');
$analytics = file_get_contents($root . '/public/analytics.php');
$analyticsJs = file_get_contents($root . '/public/analytics.js');
$incomeAnalyticsService = file_get_contents($root . '/src/IncomeAnalyticsService.php');
$aiEntry = file_get_contents($root . '/public/ai_entry.php');
$quickEntry = file_get_contents($root . '/public/quick_entry.php');
$quickEntryApi = file_get_contents($root . '/public/quick_entry_api.php');
$aiParseLogs = file_get_contents($root . '/public/ai_parse_logs.php');
$expenses = file_get_contents($root . '/public/expenses.php');
$incomes = file_get_contents($root . '/public/incomes.php');
$settings = file_get_contents($root . '/public/settings.php');
$salaryDetail = file_get_contents($root . '/public/salary_detail.php');
$overtime = file_get_contents($root . '/public/overtime.php');
$leave = file_get_contents($root . '/public/leave.php');
$css = file_get_contents($root . '/public/style.css');
ob_start();
render_ledger_edit_modal('first-target,second-target', 'test-ledger-status', '測試列表');
$ledgerModal = ob_get_clean();

foreach ([
    'dashboard.php' => $dashboard,
    'finance.php' => $finance,
    'analytics.php' => $analytics,
    'analytics.js' => $analyticsJs,
    'IncomeAnalyticsService.php' => $incomeAnalyticsService,
    'ai_entry.php' => $aiEntry,
    'quick_entry.php' => $quickEntry,
    'quick_entry_api.php' => $quickEntryApi,
    'ai_parse_logs.php' => $aiParseLogs,
    'expenses.php' => $expenses,
    'incomes.php' => $incomes,
    'settings.php' => $settings,
    'salary_detail.php' => $salaryDetail,
    'overtime.php' => $overtime,
    'leave.php' => $leave,
    'style.css' => $css,
    'ledger modal markup' => $ledgerModal,
] as $file => $content) {
    backend_ui_feedback_assert(is_string($content), $file . ' should be readable');
}

backend_ui_feedback_assert(
    str_contains($ledgerModal, 'id="ledger-edit-modal"')
    && str_contains($ledgerModal, 'id="ledger-edit-delete"')
    && str_contains($ledgerModal, 'data-refresh-targets="first-target,second-target"')
    && str_contains($ledgerModal, 'data-page-status-id="test-ledger-status"')
    && str_contains($ledgerModal, 'data-result-label="測試列表"'),
    'Shared ledger modal should expose its AJAX refresh and status configuration'
);

backend_ui_feedback_assert(
    !str_contains($dashboard, 'href="/quick_entry.php"'),
    'Dashboard should not expose Quick Entry / PWA as a backend quick action'
);
backend_ui_feedback_assert(
    str_contains($aiParseLogs, 'AiParseLogListService')
    && str_contains($aiParseLogs, 'name="source"')
    && str_contains($aiParseLogs, 'name="status"')
    && str_contains($aiParseLogs, 'name="type"')
    && str_contains($aiParseLogs, 'name="date_from"')
    && str_contains($aiParseLogs, 'name="date_to"')
    && str_contains($aiParseLogs, '載入更早的 20 筆')
    && str_contains($aiParseLogs, '查看完整紀錄與 Trace')
    && !str_contains($aiParseLogs, 'ai_response'),
    'AI parse logs should use a bounded summary list with filters, cursor pagination, and a separate full-detail link'
);
backend_ui_feedback_assert(
    !str_contains($dashboard, '今日加班'),
    'Dashboard first screen should not show today overtime summary'
);
backend_ui_feedback_assert(
    str_contains($dashboard, '本月收入總計')
    && str_contains($dashboard, 'format_number_clean($incomeTotal)'),
    'Dashboard secondary statistics should display the existing monthly total income'
);
backend_ui_feedback_assert(
    !str_contains($dashboard, '日常總覽')
    && !str_contains($dashboard, '今日支出')
    && !str_contains($dashboard, '本月收入（含薪資）'),
    'Dashboard should remove the daily overview and income main cards from the first screen'
);
backend_ui_feedback_assert(
    str_contains($dashboard, 'href="/ai_entry.php"') && str_contains($dashboard, 'AI 快速輸入'),
    'Dashboard should keep AI 快速輸入 as the backend entry'
);
backend_ui_feedback_assert(
    str_contains($dashboard, '<a href="/analytics.php">支出總覽</a>')
    && substr_count($dashboard, 'class="dashboard-action" href="/analytics.php"') === 1
    && !str_contains($dashboard, 'class="dashboard-action" href="/expenses.php"')
    && !str_contains($dashboard, '消費分析'),
    'Dashboard should use the integrated expense overview as its header and single quick-action entry'
);
backend_ui_feedback_assert(
    substr_count($finance, 'class="menu-card" href="/analytics.php"') === 1
    && str_contains($finance, '支出總覽')
    && !str_contains($finance, 'class="menu-card" href="/expenses.php"')
    && !str_contains($finance, '消費分析')
    && str_contains($finance, 'render_mobile_nav("finance")'),
    'Finance menu should expose one integrated expense overview while keeping the mobile finance nav grouping'
);
backend_ui_feedback_assert(
    str_contains($analytics, 'require_login()')
    && str_contains($analytics, 'ExpenseKeywordAnalyticsService')
    && str_contains($analytics, 'IncomeAnalyticsService')
    && str_contains($analytics, '<title>支出總覽</title>')
    && str_contains($analytics, '<h1>支出總覽</h1>')
    && str_contains($analytics, '<a href="/expenses.php">支出管理</a>')
    && str_contains($analytics, '新增／管理支出')
    && str_contains($analytics, '統計口徑')
    && str_contains($analytics, '實際消費日期區間')
    && str_contains($analytics, '帳單月份區間')
    && str_contains($analytics, 'name="date_from"')
    && str_contains($analytics, 'name="date_to"')
    && str_contains($analytics, 'name="month_from"')
    && str_contains($analytics, 'name="month_to"')
    && str_contains($analytics, 'name="category"')
    && str_contains($analytics, 'categoryOptions')
    && str_contains($analytics, '支出關鍵字')
    && str_contains($analytics, '符合總額')
    && str_contains($analytics, '平均金額')
    && str_contains($analytics, '收入明細')
    && str_contains($analytics, 'data-ledger-edit="1"')
    && str_contains($analytics, "render_ledger_edit_modal('analytics-results'")
    && str_contains($analytics, '/style.css?v=')
    && str_contains($analytics, '/analytics.js?v=')
    && str_contains($analytics, 'analytics-results')
    && !str_contains($analytics, '>編輯 <?= h($row["item"]) ?></a>')
    && !str_contains($analytics, '>編輯 <?= h($row["source_name"]) ?></a>')
    && str_contains($analytics, 'class="analytics-submit" type="submit">搜尋</button>')
    && !str_contains($analytics, 'class="month-submit"')
    && str_contains($analytics, 'render_mobile_nav(\'finance\')')
    && !str_contains($analytics, 'method="post"')
    && !str_contains($analytics, 'INSERT ')
    && !str_contains($analytics, 'UPDATE ')
    && !str_contains($analytics, 'DELETE '),
    'analytics.php should be the login-protected readonly integrated expense overview with a visible search action'
);
backend_ui_feedback_assert(
    str_contains($analyticsJs, 'fetch(')
    && str_contains($analyticsJs, 'FormData(form)')
    && str_contains($analyticsJs, 'refreshResults')
    && str_contains($analyticsJs, 'data-refresh-targets')
    && str_contains($analyticsJs, 'preventScroll: true')
    && str_contains($analyticsJs, 'credentials: \'same-origin\'')
    && str_contains($analyticsJs, 'data-ledger-edit')
    && str_contains($analyticsJs, "deleteData.append('action', 'delete')")
    && str_contains($analyticsJs, 'window.confirm(')
    && str_contains($analyticsJs, 'Escape'),
    'analytics.js should load, save, and delete modal items through same-origin AJAX and refresh the result block'
);
backend_ui_feedback_assert(
    str_contains($salaryDetail, 'FROM settings WHERE is_active = 1')
    && str_contains($salaryDetail, 'if (isset($settings["base_salary"]))')
    && str_contains($salaryDetail, 'if (isset($settings["full_attendance_bonus"]))')
    && str_contains($salaryDetail, 'if (isset($settings["attendance_allowance_unit"]))')
    && str_contains($salaryDetail, 'if (isset($settings["overtime_134_hourly_rate"]))')
    && str_contains($salaryDetail, 'if (isset($settings["overtime_167_hourly_rate"]))')
    && str_contains($salaryDetail, 'if (isset($settings["overtime_2h_meal_fee"]))')
    && str_contains($salaryDetail, 'if (isset($settings["overtime_3h_night_snack_fee"]))')
    && str_contains($salaryDetail, 'if (isset($settings["labor_insurance_deduction"]))')
    && str_contains($salaryDetail, 'if (isset($settings["health_insurance_deduction"]))')
    && str_contains($salaryDetail, 'if (isset($settings["annual_special_leave_days"]))')
    && str_contains($salaryDetail, '目前沒有啟用中的薪資項目設定。'),
    'salary detail should only render items backed by active salary settings'
);
backend_ui_feedback_assert(
    str_contains($incomeAnalyticsService, 'FROM incomes')
    && str_contains($incomeAnalyticsService, 'LIMIT \' . $limit . \' OFFSET \' . $offset')
    && str_contains($incomeAnalyticsService, 'is_deleted = 0'),
    'Income analytics service should provide active-only paginated income rows'
);
backend_ui_feedback_assert(
    str_contains($aiEntry, 'require_login($quickEntryMode ? \'/quick_entry.php\' : null)')
    && str_contains($aiEntry, "AdminAiInputWriteService::SOURCE")
    && str_contains($aiEntry, "確認記帳"),
    'AI entry must remain login-protected and expose an admin confirmation write action'
);
backend_ui_feedback_assert(
    str_contains($aiEntry, "post_string('action', 'preview')")
    && str_contains($aiEntry, "in_array(\$action, ['write', 'confirm', 'preview'], true)")
    && str_contains($aiEntry, 'name="action" value="<?= $quickEntryMode ? \'parse\' : \'write\' ?>"')
    && str_contains($aiEntry, 'AI 記帳')
    && str_contains($aiEntry, 'name="action" value="preview"')
    && str_contains($aiEntry, 'AI 解析預覽'),
    'AI entry should only write for explicit write actions and fail safely to preview otherwise'
);
backend_ui_feedback_assert(
    str_contains($aiEntry, 'admin_ai_consume_direct_write_token')
    && str_contains($aiEntry, 'admin_ai_redirect_with_result')
    && str_contains($aiEntry, "header('Location: /ai_entry.php?result='")
    && str_contains($aiEntry, 'event.submitter'),
    'AI entry direct write should use one-time submit token, PRG result page, and clicked-button submit locking'
);
backend_ui_feedback_assert(
    str_contains($aiEntry, 'name="action" value="confirm"')
    && str_contains($aiEntry, 'name="ai_parse_log_id"')
    && str_contains($aiEntry, 'data-disable-submit="1"'),
    'AI entry confirmation form should carry the parse log id and disable duplicate submit'
);
backend_ui_feedback_assert(
    str_contains($aiEntry, "AdminAiInputAlreadyWrittenException")
    && str_contains($aiEntry, "未重複新增"),
    'AI entry should show an already-written state instead of duplicating preview writes'
);
backend_ui_feedback_assert(
    str_contains($dashboard, '<h2>最近 10 筆支出</h2>')
    && !str_contains($dashboard, '<h2><?= h($selectedMonth) ?> 支出</h2>')
    && str_contains($dashboard, '$dashboardRecentExpenses = $recentExpenses;')
    && str_contains($dashboard, 'id="dashboard-summary-results"')
    && str_contains($dashboard, 'id="dashboard-recent-expenses"')
    && str_contains($dashboard, 'data-ledger-edit="1"')
    && str_contains($dashboard, "render_ledger_edit_modal('dashboard-summary-results,dashboard-recent-expenses'")
    && str_contains($dashboard, '/style.css?v=')
    && str_contains($dashboard, '/analytics.js?v='),
    'Dashboard should show a limited recent 10 expense list instead of a duplicate full monthly expense block'
);
backend_ui_feedback_assert(
    str_contains($dashboard, '帳單月份')
    && str_contains($dashboard, '記帳對象')
    && str_contains($dashboard, 'value="all"')
    && str_contains($dashboard, "EntryOwner::labels()")
    && str_contains($dashboard, 'EntryOwner::label($row["entry_owner"] ?? EntryOwner::PROFILE_A)'),
    'Dashboard should keep billing month display and expose entry owner filter / labels'
);
backend_ui_feedback_assert(
    str_contains($dashboard, '尚無支出紀錄')
    && !str_contains($dashboard, '本月尚無支出紀錄'),
    'Dashboard recent expenses empty state should not describe the list as month-filtered'
);
backend_ui_feedback_assert(
    !str_contains($quickEntry, 'require_login('),
    'quick_entry.php must remain login-free for PWA / Shortcut use'
);
backend_ui_feedback_assert(
    str_contains($quickEntryApi, "Content-Type: application/json")
    && str_contains($quickEntryApi, "\$_SERVER['REQUEST_METHOD']")
    && str_contains($quickEntryApi, "!== 'POST'")
    && str_contains($quickEntryApi, "header('Allow: POST')"),
    'quick_entry_api.php must remain a POST-only JSON API'
);
backend_ui_feedback_assert(
    str_contains($expenses, "EntryOwner::labels()")
    && str_contains($expenses, "entry_owner = :entry_owner")
    && str_contains($expenses, '<option value="all"')
    && str_contains($expenses, '記帳對象：')
    && str_contains($expenses, 'EntryOwner::label($row["entry_owner"] ?? EntryOwner::PROFILE_A)')
    && !str_contains($expenses, '<label>記帳人<input name="user_name"')
    && str_contains($expenses, '<input type="hidden" name="user_name"'),
    'expenses.php should hide bookkeeper fields while displaying and filtering entry owner labels'
);
backend_ui_feedback_assert(
    str_contains($settings, 'value="<?= h((string) $row[\'numeric_value\']) ?>"')
    && !str_contains($settings, 'value="<?= h(format_number_clean($row[\'numeric_value\'])) ?>"'),
    'settings.php should render the stored raw decimal in the edit numeric value input'
);
backend_ui_feedback_assert(
    str_contains($expenses, 'edit_id')
    && str_contains($expenses, '$pageSize = 100')
    && str_contains($expenses, 'OFFSET')
    && str_contains($expenses, 'expenses_page_url')
    && str_contains($expenses, "\$_GET['ajax']")
    && str_contains($expenses, "=== 'edit'")
    && str_contains($expenses, 'Content-Type: application/json'),
    'expenses.php should expose old rows through pagination and the authenticated modal edit endpoint'
);
backend_ui_feedback_assert(
    str_contains($incomes, 'edit_id')
    && str_contains($incomes, '$pageSize = 100')
    && str_contains($incomes, 'OFFSET')
    && str_contains($incomes, 'incomes_page_url')
    && str_contains($incomes, "\$_GET['ajax']")
    && str_contains($incomes, "=== 'edit'")
    && str_contains($incomes, 'Content-Type: application/json'),
    'incomes.php should expose old rows through pagination and the authenticated modal edit endpoint'
);

foreach (['1000.00', '1234.50'] as $amount) {
    backend_ui_feedback_assert(
        valid_money_string($amount),
        $amount . ' should survive an edit form round-trip as a raw decimal'
    );
    backend_ui_feedback_assert(
        str_contains(format_number_clean($amount), ','),
        'Display formatting should remain separate from editable amount values for ' . $amount
    );
}

foreach ([
    'expenses.php' => $expenses,
    'incomes.php' => $incomes,
] as $file => $content) {
    backend_ui_feedback_assert(
        substr_count($content, 'type="number" name="amount" min="0" step="0.01" inputmode="decimal"') === 2,
        $file . ' should use decimal number inputs for create and edit forms'
    );
    backend_ui_feedback_assert(
        str_contains($content, 'value="<?= h((string) $row["amount"]) ?>"'),
        $file . ' should render the stored raw decimal in the edit amount input'
    );
    backend_ui_feedback_assert(
        !str_contains($content, 'value="<?= h(format_number_clean($row["amount"])) ?>"')
        && substr_count($content, 'format_number_clean($row["amount"])') === 1,
        $file . ' should reserve grouped amount formatting for display text only'
    );
    backend_ui_feedback_assert(
        str_contains($content, "throw new InvalidArgumentException('invalid_amount')")
        && str_contains($content, "\$exception->getMessage() === 'invalid_amount'")
        && str_contains($content, '金額格式不正確，請輸入數字（最多兩位小數），不要加入千分位逗號。')
        && str_contains($content, 'role="status"')
        && str_contains($content, 'role="alert"')
        && str_contains($content, 'catch (Throwable)')
        && str_contains($content, 'safe_error_message()'),
        $file . ' should provide actionable amount validation without leaking internal errors'
    );
}

foreach ([
    'expenses.php' => $expenses,
    'incomes.php' => $incomes,
    'overtime.php' => $overtime,
    'leave.php' => $leave,
] as $file => $content) {
    backend_ui_feedback_assert(str_contains($content, 'record-subline'), $file . ' should use summary-first record rows');
    backend_ui_feedback_assert(str_contains($content, 'record-details'), $file . ' should fold technical metadata and actions');
    backend_ui_feedback_assert(str_contains($content, 'trace-meta'), $file . ' should keep trace metadata available');
    backend_ui_feedback_assert(str_contains($content, 'source-chip'), $file . ' should keep source labels visible');
    backend_ui_feedback_assert(str_contains($content, 'Trace 詳細'), $file . ' should keep trace detail links visible');
    backend_ui_feedback_assert(str_contains($content, 'record-edit'), $file . ' should keep edit controls available');
    backend_ui_feedback_assert(str_contains($content, 'record-delete'), $file . ' should keep delete controls available');
}

backend_ui_feedback_assert(
    str_contains($overtime, '本月尚無加班紀錄'),
    'overtime.php should keep the empty-state text for no overtime rows in current month'
);
backend_ui_feedback_assert(
    str_contains($overtime, 'name="month"')
    && str_contains($overtime, 'action="/overtime.php"')
    && str_contains($overtime, 'monthQueryValue($monthOption)')
    && str_contains($overtime, 'selected')
    && str_contains($overtime, 'onchange="this.form.submit()"')
    && !str_contains($overtime, '切換月份'),
    'overtime.php should expose a month dropdown that auto-submits YYYY-MM query values without a switch button'
);
backend_ui_feedback_assert(
    str_contains($overtime, '$row[\'display_line\']')
    && !str_contains($overtime, 'record-amount neutral-amount'),
    'overtime.php should show each overtime row as one simple line without a duplicate hours column'
);
backend_ui_feedback_assert(
    str_contains($overtime, '加班合計時數'),
    'overtime.php should expose selected month overtime total summary text'
);

backend_ui_feedback_assert(
    str_contains($css, '.record-subline') && str_contains($css, '.record-details') && str_contains($css, '.record-card-actions'),
    'CSS should define summary rows, folded details, and stable card actions'
);
backend_ui_feedback_assert(
    str_contains($css, 'overflow-x: hidden')
    && str_contains($css, '.preview-confirm-form')
    && str_contains($css, 'word-break: break-word')
    && str_contains($css, 'overflow-wrap: anywhere'),
    'AI input mobile CSS should prevent viewport overflow from long URLs and raw text'
);
backend_ui_feedback_assert(
    str_contains($css, 'flex-direction: column')
    && str_contains($css, 'calc(62px + env(safe-area-inset-bottom))')
    && str_contains($css, 'width: 100%;')
    && str_contains($css, 'max-width: 100%;'),
    'Mobile action buttons should wrap/full-width and leave bottom safe area'
);
backend_ui_feedback_assert(
    str_contains($css, '.ai-log-filter-grid') && str_contains($css, '.ai-log-pagination'),
    'CSS should support AI log filters and cursor pagination'
);

echo "BackendUiFeedbackStaticTest passed\n";
