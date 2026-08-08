<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/html.php';
require_once dirname(__DIR__) . '/src/form.php';
require_once dirname(__DIR__) . '/src/AiLedgerTraceDisplayService.php';

require_login();

$pdo = app_db();
$message = '';
$error = '';
$isAjaxSaveRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && post_string('ajax') === '1';

function income_ajax_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && trim((string) ($_GET['ajax'] ?? '')) === 'edit') {
    $requestedId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if ($requestedId === false || $requestedId === null || $requestedId < 1) {
        income_ajax_json(422, ['ok' => false, 'message' => '找不到要編輯的收入。']);
    }

    try {
        $rowStatement = $pdo->prepare(
            'SELECT id, record_date, source_name, amount, account_id, category, user_name, raw_input
             FROM incomes
             WHERE id = :id AND is_deleted = 0
             LIMIT 1'
        );
        $rowStatement->execute(['id' => $requestedId]);
        $row = $rowStatement->fetch();
        if (!is_array($row)) {
            income_ajax_json(404, ['ok' => false, 'message' => '找不到可編輯的收入。']);
        }

        $accounts = $pdo->query(
            'SELECT id, name, is_active FROM accounts ORDER BY is_active DESC, sort_order, id'
        )->fetchAll();
        income_ajax_json(200, [
            'ok' => true,
            'type' => 'income',
            'record' => $row,
            'accounts' => $accounts,
        ]);
    } catch (Throwable) {
        income_ajax_json(500, ['ok' => false, 'message' => safe_error_message()]);
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post_string('action');
        $id = post_int('id');

        if ($action === 'delete' && $id > 0) {
            $statement = $pdo->prepare('UPDATE incomes SET is_deleted = 1, deleted_at = NOW() WHERE id = :id AND is_deleted = 0');
            $statement->execute(['id' => $id]);
            $message = '收入已刪除';
        } elseif ($action === 'save') {
            $recordDate = post_string('record_date');
            $sourceName = post_string('source_name');
            $amount = post_string('amount');
            $accountId = post_int('account_id');
            $category = post_string('category');
            $userName = post_string('user_name');
            $rawInput = post_string('raw_input');

            if (!valid_money_string($amount)) {
                throw new InvalidArgumentException('invalid_amount');
            }
            if (!valid_date_string($recordDate) || $sourceName === '') {
                throw new InvalidArgumentException('invalid');
            }

            $accountName = '';
            $accountIdValue = null;
            if ($accountId > 0) {
                $accountStatement = $pdo->prepare('SELECT id, name FROM accounts WHERE id = :id LIMIT 1');
                $accountStatement->execute(['id' => $accountId]);
                $account = $accountStatement->fetch();
                if (!$account) {
                    throw new InvalidArgumentException('missing account');
                }
                $accountName = (string) $account['name'];
                $accountIdValue = $accountId;
            }

            $accountingMonth = month_from_date($recordDate);

            if ($id > 0) {
                $statement = $pdo->prepare(
                    'UPDATE incomes
                     SET record_date = :record_date, source_name = :source_name, amount = :amount,
                         account_id = :account_id, account_name = :account_name, accounting_month = :accounting_month,
                         category = :category, raw_input = :raw_input, source = :source, user_name = :user_name,
                         is_deleted = 0, deleted_at = NULL
                     WHERE id = :id AND is_deleted = 0'
                );
                $statement->execute([
                    'id' => $id,
                    'record_date' => $recordDate,
                    'source_name' => $sourceName,
                    'amount' => $amount,
                    'account_id' => $accountIdValue,
                    'account_name' => $accountName,
                    'accounting_month' => $accountingMonth,
                    'category' => $category,
                    'raw_input' => $rawInput,
                    'source' => 'manual',
                    'user_name' => $userName,
                ]);
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO incomes
                        (record_date, source_name, amount, account_id, account_name, accounting_month, category, raw_input, source, user_name)
                     VALUES
                        (:record_date, :source_name, :amount, :account_id, :account_name, :accounting_month, :category, :raw_input, :source, :user_name)'
                );
                $statement->execute([
                    'record_date' => $recordDate,
                    'source_name' => $sourceName,
                    'amount' => $amount,
                    'account_id' => $accountIdValue,
                    'account_name' => $accountName,
                    'accounting_month' => $accountingMonth,
                    'category' => $category,
                    'raw_input' => $rawInput,
                    'source' => 'manual',
                    'user_name' => $userName,
                ]);
            }
            $message = '收入已儲存';
        } elseif ($isAjaxSaveRequest) {
            throw new InvalidArgumentException('invalid_action');
        }
    }

    if ($isAjaxSaveRequest) {
        income_ajax_json(200, ['ok' => true, 'message' => $message]);
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage() === 'invalid_amount'
        ? '金額格式不正確，請輸入數字（最多兩位小數），不要加入千分位逗號。'
        : '收入儲存失敗，請確認日期、來源、金額與帳戶後再試。';
    if ($isAjaxSaveRequest) {
        income_ajax_json(422, ['ok' => false, 'message' => $error]);
    }
} catch (Throwable) {
    $error = safe_error_message();
    if ($isAjaxSaveRequest) {
        income_ajax_json(500, ['ok' => false, 'message' => $error]);
    }
}

$accounts = $pdo->query(
    'SELECT id, name, is_active FROM accounts ORDER BY is_active DESC, sort_order, id'
)->fetchAll();

$editId = filter_var($_GET['edit_id'] ?? null, FILTER_VALIDATE_INT);
$editId = $editId === false || $editId === null ? 0 : max(0, $editId);
$pageSize = 100;
$countStatement = $pdo->query('SELECT COUNT(*) FROM incomes WHERE is_deleted = 0');
$totalRows = (int) $countStatement->fetchColumn();
$pageCount = max(1, (int) ceil($totalRows / $pageSize));
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page === false || $page === null ? 1 : max(1, $page);
$page = min($page, $pageCount);
$offset = ($page - 1) * $pageSize;

$rows = $pdo->query(
    'SELECT id, record_date, source_name, amount, account_id, account_name, accounting_month, category, source, user_name, raw_input
     FROM incomes
     WHERE is_deleted = 0
     ORDER BY record_date DESC, id DESC
     LIMIT ' . $pageSize . ' OFFSET ' . $offset
)->fetchAll();

if ($editId > 0) {
    $selectedStatement = $pdo->prepare(
        'SELECT id, record_date, source_name, amount, account_id, account_name, accounting_month, category, source, user_name, raw_input
         FROM incomes
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

$traceLinks = (new AiLedgerTraceDisplayService($pdo))->latestLinksByLedgerRows('incomes', array_column($rows, 'id'));

function income_source_label(?string $source): string
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

function incomes_page_url(int $page): string
{
    return '/incomes.php?' . http_build_query(['page' => $page], '', '&', PHP_QUERY_RFC3986);
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>收入管理</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="page">
        <div class="page-header">
            <div>
                <h1>收入管理</h1>
                <p>收入月份使用收入日期的年月。</p>
            </div>
            <nav class="nav">
                <a href="/dashboard.php">主控台</a>
                <a href="/expenses.php">支出</a>
                <a href="/overtime.php">加班</a>
                <a href="/leave.php">請假</a>
            </nav>
        </div>

        <?php if ($message !== ''): ?><p class="success" role="status"><?= h($message) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>

        <details class="form-panel create-panel">
            <summary>新增收入</summary>
            <form class="grid-form" method="post">
                <input type="hidden" name="action" value="save">
                <label>日期<input type="date" name="record_date" value="<?= h(date('Y-m-d')) ?>" required></label>
                <label>來源<input name="source_name" required></label>
                <label>金額<input type="number" name="amount" min="0" step="0.01" inputmode="decimal" required></label>
                <label>帳戶
                    <select name="account_id">
                        <option value="">未指定</option>
                        <?php foreach ($accounts as $account): ?>
                            <?php if ((int) $account['is_active'] === 1): ?>
                                <option value="<?= h((string) $account['id']) ?>"><?= h($account['name']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>分類<input name="category"></label>
                <label>記帳人<input name="user_name"></label>
                <label class="wide">備註 / 原始輸入<textarea name="raw_input"></textarea></label>
                <button type="submit">新增</button>
            </form>
        </details>

        <section class="table-panel record-panel">
            <div class="section-title-row">
                <h2>收入清單</h2>
                <span class="muted">第 <?= h((string) $page) ?> / <?= h((string) $pageCount) ?> 頁，每頁 <?= h((string) $pageSize) ?> 筆，共 <?= h((string) $totalRows) ?> 筆</span>
            </div>
            <div class="record-list income-list">
                <?php if ($rows === []): ?>
                    <article class="record-card empty-state"><p class="muted">目前沒有收入紀錄。</p></article>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <article class="record-card ledger-card income-card">
                        <div class="record-main">
                            <div class="record-title">
                                <strong><?= h($row["source_name"] !== "" ? $row["source_name"] : "未命名收入") ?></strong>
                                <span>日期：<?= h($row["record_date"]) ?></span>
                            </div>
                            <div class="record-amount income-amount">+<?= h(format_number_clean($row["amount"])) ?></div>
                        </div>
                        <p class="record-subline">
                            <?= h(($row["account_name"] ?? "") !== "" ? $row["account_name"] : "未指定帳戶") ?>
                            · <?= h($row["accounting_month"] !== "" ? $row["accounting_month"] : "-") ?>
                        </p>
                        <?php $traceLink = $traceLinks[(int) $row["id"]] ?? null; ?>
                        <details class="record-details" <?= $editId === (int) $row["id"] ? 'open' : '' ?>>
                            <summary>詳細 / 操作</summary>
                            <div class="record-meta trace-meta">
                                <span class="source-chip">來源：<?= h(income_source_label($row["source"] ?? "")) ?></span>
                                <?php if (is_array($traceLink)): ?>
                                    <span class="trace-chip">AI：Log #<?= h((string) $traceLink["ai_parse_log_id"]) ?></span>
                                    <span class="trace-chip">Trace：<?= h(AiLedgerTraceDisplayService::actionLabel($traceLink["action"])) ?></span>
                                    <span class="debug-chip">輸入：<?= h(AiLedgerTraceDisplayService::textOrDash($traceLink["raw_input_snapshot"] ?? null)) ?></span>
                                    <span>連結時間：<?= h($traceLink["created_at"] ?? "-") ?></span>
                                    <a href="/ai_trace_detail.php?ledger_table=incomes&amp;ledger_id=<?= h((string) $row["id"]) ?>">Trace 詳細</a>
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
                                    <form class="grid-form edit-form" method="post">
                                        <input type="hidden" name="action" value="save">
                                        <input type="hidden" name="id" value="<?= h((string) $row["id"]) ?>">
                                        <label>日期<input type="date" name="record_date" value="<?= h($row["record_date"]) ?>" required></label>
                                        <label>來源<input name="source_name" value="<?= h($row["source_name"]) ?>" required></label>
                                        <label>金額<input type="number" name="amount" min="0" step="0.01" inputmode="decimal" value="<?= h((string) $row["amount"]) ?>" required></label>
                                        <label>帳戶
                                            <select name="account_id">
                                                <option value="" <?= $row["account_id"] === null ? "selected" : "" ?>>未指定</option>
                                                <?php foreach ($accounts as $account): ?>
                                                    <option value="<?= h((string) $account["id"]) ?>" <?= (int) $row["account_id"] === (int) $account["id"] ? "selected" : "" ?>>
                                                        <?= h($account["name"]) ?><?= (int) $account["is_active"] === 0 ? "（停用）" : "" ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>分類<input name="category" value="<?= h($row["category"]) ?>"></label>
                                        <label>記帳人<input name="user_name" value="<?= h($row["user_name"]) ?>"></label>
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
                <nav class="pagination" aria-label="收入清單分頁">
                    <?php if ($page > 1): ?><a class="button secondary" href="<?= h(incomes_page_url($page - 1)) ?>">上一頁</a><?php endif; ?>
                    <span>第 <?= h((string) $page) ?> / <?= h((string) $pageCount) ?> 頁</span>
                    <?php if ($page < $pageCount): ?><a class="button" href="<?= h(incomes_page_url($page + 1)) ?>">下一頁</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </main>
    <?php render_mobile_nav('incomes'); ?>
</body>
</html>
