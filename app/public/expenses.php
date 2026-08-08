<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/html.php';
require_once dirname(__DIR__) . '/src/form.php';
require_once dirname(__DIR__) . '/src/AccountingMonthService.php';
require_once dirname(__DIR__) . '/src/AiLedgerTraceDisplayService.php';
require_once dirname(__DIR__) . '/src/EntryOwner.php';

require_login();

$pdo = app_db();
$message = '';
$error = '';
$isAjaxSaveRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && post_string('ajax') === '1';

function expense_ajax_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && trim((string) ($_GET['ajax'] ?? '')) === 'edit') {
    $requestedId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if ($requestedId === false || $requestedId === null || $requestedId < 1) {
        expense_ajax_json(422, ['ok' => false, 'message' => '找不到要編輯的支出。']);
    }

    try {
        $rowStatement = $pdo->prepare(
            'SELECT id, record_date, item, amount, payment_method_id, category, user_name, entry_owner, raw_input
             FROM expenses
             WHERE id = :id AND is_deleted = 0
             LIMIT 1'
        );
        $rowStatement->execute(['id' => $requestedId]);
        $row = $rowStatement->fetch();
        if (!is_array($row)) {
            expense_ajax_json(404, ['ok' => false, 'message' => '找不到可編輯的支出。']);
        }

        $paymentMethods = $pdo->query(
            'SELECT id, name, is_active FROM payment_methods ORDER BY is_active DESC, sort_order, id'
        )->fetchAll();
        expense_ajax_json(200, [
            'ok' => true,
            'type' => 'expense',
            'record' => $row,
            'payment_methods' => $paymentMethods,
            'entry_owners' => EntryOwner::labels(),
        ]);
    } catch (Throwable) {
        expense_ajax_json(500, ['ok' => false, 'message' => safe_error_message()]);
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post_string('action');
        $id = post_int('id');

        if ($action === 'delete' && $id > 0) {
            $statement = $pdo->prepare('UPDATE expenses SET is_deleted = 1, deleted_at = NOW() WHERE id = :id AND is_deleted = 0');
            $statement->execute(['id' => $id]);
            $message = '支出已刪除';
        } elseif ($action === 'save') {
            $recordDate = post_string('record_date');
            $item = post_string('item');
            $amount = post_string('amount');
            $paymentMethodId = post_int('payment_method_id');
            $category = post_string('category');
            $userName = post_string('user_name');
            $entryOwner = EntryOwner::normalize(post_string('entry_owner', EntryOwner::PROFILE_A));
            $rawInput = post_string('raw_input');

            if (!valid_money_string($amount)) {
                throw new InvalidArgumentException('invalid_amount');
            }
            if (!valid_date_string($recordDate) || $item === '' || $paymentMethodId < 1) {
                throw new InvalidArgumentException('invalid');
            }

            $methodStatement = $pdo->prepare(
                'SELECT id, name, settlement_start_day, settlement_end_day FROM payment_methods WHERE id = :id LIMIT 1'
            );
            $methodStatement->execute(['id' => $paymentMethodId]);
            $method = $methodStatement->fetch();
            if (!$method) {
                throw new InvalidArgumentException('missing payment method');
            }

            $accountingMonth = AccountingMonthService::forPaymentMethod($recordDate, $method);

            if ($id > 0) {
                $statement = $pdo->prepare(
                    'UPDATE expenses
                     SET record_date = :record_date, item = :item, amount = :amount,
                         payment_method_id = :payment_method_id, payment_method = :payment_method,
                         accounting_month = :accounting_month, category = :category,
                         raw_input = :raw_input, source = :source, user_name = :user_name,
                         entry_owner = :entry_owner,
                         is_deleted = 0, deleted_at = NULL
                     WHERE id = :id AND is_deleted = 0'
                );
                $statement->execute([
                    'id' => $id,
                    'record_date' => $recordDate,
                    'item' => $item,
                    'amount' => $amount,
                    'payment_method_id' => $paymentMethodId,
                    'payment_method' => $method['name'],
                    'accounting_month' => $accountingMonth,
                    'category' => $category,
                    'raw_input' => $rawInput,
                    'source' => 'manual',
                    'user_name' => $userName,
                    'entry_owner' => $entryOwner,
                ]);
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO expenses
                        (record_date, item, amount, payment_method_id, payment_method, accounting_month, category, raw_input, source, user_name, entry_owner)
                     VALUES
                        (:record_date, :item, :amount, :payment_method_id, :payment_method, :accounting_month, :category, :raw_input, :source, :user_name, :entry_owner)'
                );
                $statement->execute([
                    'record_date' => $recordDate,
                    'item' => $item,
                    'amount' => $amount,
                    'payment_method_id' => $paymentMethodId,
                    'payment_method' => $method['name'],
                    'accounting_month' => $accountingMonth,
                    'category' => $category,
                    'raw_input' => $rawInput,
                    'source' => 'manual',
                    'user_name' => $userName,
                    'entry_owner' => $entryOwner,
                ]);
            }
            $message = '支出已儲存';
        } elseif ($isAjaxSaveRequest) {
            throw new InvalidArgumentException('invalid_action');
        }
    }

    if ($isAjaxSaveRequest) {
        expense_ajax_json(200, ['ok' => true, 'message' => $message]);
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage() === 'invalid_amount'
        ? '金額格式不正確，請輸入數字（最多兩位小數），不要加入千分位逗號。'
        : '支出儲存失敗，請確認日期、項目、金額與付款方式後再試。';
    if ($isAjaxSaveRequest) {
        expense_ajax_json(422, ['ok' => false, 'message' => $error]);
    }
} catch (Throwable) {
    $error = safe_error_message();
    if ($isAjaxSaveRequest) {
        expense_ajax_json(500, ['ok' => false, 'message' => $error]);
    }
}

$paymentMethods = $pdo->query(
    'SELECT id, name, settlement_start_day, settlement_end_day, is_active FROM payment_methods ORDER BY is_active DESC, sort_order, id'
)->fetchAll();

$entryOwnerFilter = trim((string) ($_GET['entry_owner'] ?? 'all'));
if (!in_array($entryOwnerFilter, ['all', EntryOwner::PROFILE_A, EntryOwner::PROFILE_B], true)) {
    $entryOwnerFilter = 'all';
}

$editId = filter_var($_GET['edit_id'] ?? null, FILTER_VALIDATE_INT);
$editId = $editId === false || $editId === null ? 0 : max(0, $editId);
$pageSize = 100;

$countSql = 'SELECT COUNT(*)
     FROM expenses
     WHERE is_deleted = 0';
$countParams = [];
if ($entryOwnerFilter !== 'all') {
    $countSql .= ' AND entry_owner = :entry_owner';
    $countParams['entry_owner'] = $entryOwnerFilter;
}
$countStatement = $pdo->prepare($countSql);
$countStatement->execute($countParams);
$totalRows = (int) $countStatement->fetchColumn();
$pageCount = max(1, (int) ceil($totalRows / $pageSize));
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page === false || $page === null ? 1 : max(1, $page);
$page = min($page, $pageCount);
$offset = ($page - 1) * $pageSize;

$rowSql = 'SELECT id, record_date, item, amount, payment_method_id, payment_method, accounting_month,
            category, source, user_name, entry_owner, raw_input
     FROM expenses
     WHERE is_deleted = 0';
$rowParams = [];
if ($entryOwnerFilter !== 'all') {
    $rowSql .= ' AND entry_owner = :entry_owner';
    $rowParams['entry_owner'] = $entryOwnerFilter;
}
$rowSql .= '
     ORDER BY record_date DESC, id DESC
     LIMIT ' . $pageSize . ' OFFSET ' . $offset;
$rowStatement = $pdo->prepare($rowSql);
$rowStatement->execute($rowParams);
$rows = $rowStatement->fetchAll();

if ($editId > 0) {
    $selectedStatement = $pdo->prepare(
        'SELECT id, record_date, item, amount, payment_method_id, payment_method, accounting_month,
                category, source, user_name, entry_owner, raw_input
         FROM expenses
         WHERE id = :id AND is_deleted = 0
         LIMIT 1'
    );
    $selectedStatement->execute(['id' => $editId]);
    $selectedRow = $selectedStatement->fetch();
    $loadedIds = array_map('intval', array_column($rows, 'id'));
    if (is_array($selectedRow) && !in_array($editId, $loadedIds, true)) {
        array_unshift($rows, $selectedRow);
    }
}

$traceLinks = (new AiLedgerTraceDisplayService($pdo))->latestLinksByLedgerRows('expenses', array_column($rows, 'id'));

function expense_owner_option(string $value, string $current): string
{
    return $value === $current ? 'selected' : '';
}

function expense_source_label(?string $source): string
{
    $source = trim((string) $source);
    return match ($source) {
        '', 'manual' => '手動',
        'quick_pwa' => '快速記帳',
        'ios_shortcut' => 'iOS Shortcut',
        'shortcut_api' => 'Shortcut API',
        'admin_ai_input' => '後台 AI 快速輸入',
        'quick_entry_check' => 'Quick Entry 驗收腳本',
        default => $source,
    };
}

function expenses_page_url(string $entryOwner, int $page): string
{
    $query = ['page' => $page];
    if ($entryOwner !== 'all') {
        $query['entry_owner'] = $entryOwner;
    }

    return '/expenses.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>支出管理</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="page">
        <div class="page-header">
            <div>
                <h1>支出管理</h1>
                <p>帳單月份依付款方式結算日起訖自動計算。</p>
            </div>
            <nav class="nav">
                <a href="/dashboard.php">主控台</a>
                <a href="/analytics.php">支出總覽</a>
                <a href="/incomes.php">收入</a>
                <a href="/overtime.php">加班</a>
                <a href="/leave.php">請假</a>
            </nav>
        </div>

        <?php if ($message !== ''): ?><p class="success" role="status"><?= h($message) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>

        <details class="form-panel create-panel">
            <summary>新增支出</summary>
            <form class="grid-form" method="post">
                <input type="hidden" name="action" value="save">
                <label>日期<input type="date" name="record_date" value="<?= h(date('Y-m-d')) ?>" required></label>
                <label>項目<input name="item" required></label>
                <label>金額<input type="number" name="amount" min="0" step="0.01" inputmode="decimal" required></label>
                <label>付款方式
                    <select name="payment_method_id" required>
                        <option value="">請選擇</option>
                        <?php foreach ($paymentMethods as $method): ?>
                            <?php if ((int) $method['is_active'] === 1): ?>
                                <option value="<?= h((string) $method['id']) ?>"><?= h($method['name']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>分類<input name="category"></label>
                <input type="hidden" name="user_name" value="">
                <label>記帳對象
                    <select name="entry_owner" required>
                        <?php foreach (EntryOwner::labels() as $ownerValue => $ownerLabel): ?>
                            <option value="<?= h($ownerValue) ?>" <?= expense_owner_option($ownerValue, EntryOwner::PROFILE_A) ?>><?= h($ownerLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="wide">備註 / 原始輸入<textarea name="raw_input"></textarea></label>
                <button type="submit">新增</button>
            </form>
        </details>

        <section class="table-panel record-panel">
            <div class="section-title-row">
                <h2>支出清單</h2>
                <span class="muted">第 <?= h((string) $page) ?> / <?= h((string) $pageCount) ?> 頁，每頁 <?= h((string) $pageSize) ?> 筆，共 <?= h((string) $totalRows) ?> 筆</span>
            </div>
            <form class="filter-form" method="get">
                <label>記帳對象
                    <select name="entry_owner" onchange="this.form.submit()">
                        <option value="all" <?= expense_owner_option('all', $entryOwnerFilter) ?>>全部</option>
                        <?php foreach (EntryOwner::labels() as $ownerValue => $ownerLabel): ?>
                            <option value="<?= h($ownerValue) ?>" <?= expense_owner_option($ownerValue, $entryOwnerFilter) ?>><?= h($ownerLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">篩選</button>
            </form>
            <div class="record-list expense-list">
                <?php if ($rows === []): ?>
                    <article class="record-card empty-state"><p class="muted">目前沒有支出紀錄。</p></article>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php $saveFormId = "expense-save-" . (string) $row["id"]; ?>
                    <article class="record-card ledger-card expense-card">
                        <div class="record-main">
                            <div class="record-title">
                                <strong><?= h($row["item"] !== "" ? $row["item"] : "未命名支出") ?></strong>
                                <span>日期：<?= h($row["record_date"]) ?></span>
                            </div>
                            <div class="record-amount expense-amount">-<?= h(format_number_clean($row["amount"])) ?></div>
                        </div>
                        <p class="record-subline">
                            <?= h($row["payment_method"] !== "" ? $row["payment_method"] : "未指定付款") ?>
                            · <?= h($row["accounting_month"] !== "" ? $row["accounting_month"] : "-") ?>
                            · 記帳對象：<?= h(EntryOwner::label($row["entry_owner"] ?? EntryOwner::PROFILE_A)) ?>
                        </p>
                        <?php $traceLink = $traceLinks[(int) $row["id"]] ?? null; ?>
                        <details class="record-details" <?= $editId === (int) $row["id"] ? 'open' : '' ?>>
                            <summary>詳細 / 操作</summary>
                            <div class="record-meta trace-meta">
                                <span class="source-chip">來源：<?= h(expense_source_label($row["source"] ?? "")) ?></span>
                                <?php if (is_array($traceLink)): ?>
                                    <span class="trace-chip">AI：Log #<?= h((string) $traceLink["ai_parse_log_id"]) ?></span>
                                    <span class="trace-chip">Trace：<?= h(AiLedgerTraceDisplayService::actionLabel($traceLink["action"])) ?></span>
                                    <span class="debug-chip">輸入：<?= h(AiLedgerTraceDisplayService::textOrDash($traceLink["raw_input_snapshot"] ?? null)) ?></span>
                                    <span>連結時間：<?= h($traceLink["created_at"] ?? "-") ?></span>
                                    <a href="/ai_trace_detail.php?ledger_table=expenses&amp;ledger_id=<?= h((string) $row["id"]) ?>">Trace 詳細</a>
                                    <?php if ((int) ($traceLink["link_count"] ?? 0) > 1): ?>
                                        <span class="trace-chip">AI x<?= h((string) $traceLink["link_count"]) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span>AI：手動 / 未連結</span>
                                <?php endif; ?>
                            </div>
                            <div class="record-card-actions">
                                <details class="record-edit">
                                    <summary>編輯</summary>
                                    <form id="<?= h($saveFormId) ?>" class="grid-form edit-form" method="post">
                                        <input type="hidden" name="action" value="save">
                                        <input type="hidden" name="id" value="<?= h((string) $row["id"]) ?>">
                                        <label>日期<input type="date" name="record_date" value="<?= h($row["record_date"]) ?>" required></label>
                                        <label>項目<input name="item" value="<?= h($row["item"]) ?>" required></label>
                                        <label>金額<input type="number" name="amount" min="0" step="0.01" inputmode="decimal" value="<?= h((string) $row["amount"]) ?>" required></label>
                                        <label>付款方式
                                            <select name="payment_method_id" required>
                                                <?php foreach ($paymentMethods as $method): ?>
                                                    <option value="<?= h((string) $method["id"]) ?>" <?= (int) $row["payment_method_id"] === (int) $method["id"] ? "selected" : "" ?>>
                                                        <?= h($method["name"]) ?><?= (int) $method["is_active"] === 0 ? "（停用）" : "" ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>分類<input name="category" value="<?= h($row["category"]) ?>"></label>
                                        <input type="hidden" name="user_name" value="<?= h($row["user_name"]) ?>">
                                        <label>記帳對象
                                            <select name="entry_owner" required>
                                                <?php foreach (EntryOwner::labels() as $ownerValue => $ownerLabel): ?>
                                                    <option value="<?= h($ownerValue) ?>" <?= expense_owner_option($ownerValue, EntryOwner::normalize($row["entry_owner"] ?? EntryOwner::PROFILE_A)) ?>>
                                                        <?= h($ownerLabel) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="wide">備註<textarea name="raw_input"><?= h($row["raw_input"]) ?></textarea></label>
                                        <button type="submit">儲存</button>
                                    </form>
                                </details>
                                <form class="record-delete" method="post">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= h((string) $row["id"]) ?>">
                                    <button type="submit" class="secondary text-button">刪除</button>
                                </form>
                            </div>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($pageCount > 1): ?>
                <nav class="pagination" aria-label="支出清單分頁">
                    <?php if ($page > 1): ?><a class="button secondary" href="<?= h(expenses_page_url($entryOwnerFilter, $page - 1)) ?>">上一頁</a><?php endif; ?>
                    <span>第 <?= h((string) $page) ?> / <?= h((string) $pageCount) ?> 頁</span>
                    <?php if ($page < $pageCount): ?><a class="button" href="<?= h(expenses_page_url($entryOwnerFilter, $page + 1)) ?>">下一頁</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </main>
    <?php render_mobile_nav('expenses'); ?>
</body>
</html>
