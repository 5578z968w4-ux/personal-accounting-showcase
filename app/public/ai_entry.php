<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/html.php';
require_once dirname(__DIR__) . '/src/AiParseService.php';
require_once dirname(__DIR__) . '/src/AiInputDateResolver.php';
require_once dirname(__DIR__) . '/src/AiLedgerTraceDisplayService.php';
require_once dirname(__DIR__) . '/src/AdminAiInputWriteService.php';

$quickEntryMode = ($quickEntryMode ?? false) === true;
require_login($quickEntryMode ? '/quick_entry.php' : null);

$pdo = app_db();
$settings = $pdo->query('SELECT * FROM ai_settings WHERE id = 1')->fetch() ?: [];
$paymentMethods = $pdo->query(
    'SELECT id, name FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id'
)->fetchAll();
$accounts = $pdo->query(
    'SELECT id, name FROM accounts WHERE is_active = 1 ORDER BY sort_order, id'
)->fetchAll();
$leaveTypes = $pdo->query(
    'SELECT name FROM leave_types WHERE is_active = 1 ORDER BY sort_order, id'
)->fetchAll(PDO::FETCH_COLUMN);
$inputText = '';
$requestedType = 'auto';
$directWriteToken = admin_ai_direct_write_token();
$resultId = trim((string) ($_GET['result'] ?? ''));
$preview = null;
$summary = admin_ai_flash_result($resultId);
$alreadyWrittenLink = null;
$fieldErrors = [];
$error = '';
$activeAiParseLogId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_string('action', 'preview');
    if (!in_array($action, ['write', 'confirm', 'preview'], true)) {
        $action = 'preview';
    }
    $inputText = post_string('input_text');
    $requestedType = post_string('requested_type', 'auto');

    try {
        if ($action === 'write' && !$quickEntryMode) {
            admin_ai_consume_direct_write_token(post_string('submit_token'));
            $service = admin_ai_parse_service($pdo);
            $parsed = $service->preview(
                $inputText,
                $requestedType,
                $settings,
                AdminAiInputWriteService::SOURCE,
                (string) app_env('APP_LOGIN_USERNAME', '')
            );
            $writer = new AdminAiInputWriteService($pdo);
            $activeAiParseLogId = (int) ($parsed['ai_parse_log_id'] ?? 0);
            $summary = $writer->saveParsed(
                $parsed,
                $inputText,
                (string) app_env('APP_LOGIN_USERNAME', '')
            );
            admin_ai_redirect_with_result($summary, $activeAiParseLogId);
        } elseif ($action === 'confirm' && !$quickEntryMode) {
            $traceAiParseLogId = post_int('ai_parse_log_id');
            $entryType = post_string('entry_type');
            $fields = admin_ai_posted_fields($entryType);
            $writer = new AdminAiInputWriteService($pdo);
            $summary = $writer->confirm(
                $traceAiParseLogId,
                $entryType,
                $fields,
                $inputText,
                (string) app_env('APP_LOGIN_USERNAME', '')
            );
            admin_ai_redirect_with_result($summary, $traceAiParseLogId);
        } else {
            $service = admin_ai_parse_service($pdo);
            $preview = $service->preview(
                $inputText,
                $requestedType,
                $settings,
                $quickEntryMode ? 'quick_pwa' : AdminAiInputWriteService::SOURCE,
                (string) app_env('APP_LOGIN_USERNAME', '')
            );
        }
    } catch (AdminAiInputAlreadyWrittenException $exception) {
        $alreadyWrittenLink = $exception->link();
        $error = $exception->getMessage();
    } catch (QuickEntryValidationException $exception) {
        $error = $exception->getMessage();
        $fieldErrors = $exception->fieldErrors();
        $exceptionFields = $exception->fields();
        $exceptionLogId = $activeAiParseLogId > 0 ? $activeAiParseLogId : post_int('ai_parse_log_id');
        if ($exceptionLogId > 0 || $exceptionFields !== []) {
            $preview = [
                'status' => 'needs_correction',
                'type' => $exception->entryType(),
                'provider' => '',
                'model' => '',
                'fields' => $exceptionFields,
                'raw_input' => $inputText,
                'warnings' => [],
                'ai_parse_log_id' => $exceptionLogId,
            ];
        }
    } catch (AiParseException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable) {
        $error = safe_error_message();
    }

    $directWriteToken = admin_ai_direct_write_token(true);
}

$typeLabels = [
    'unknown' => '尚未判斷',
    'expense' => '支出',
    'income' => '收入',
    'overtime' => '加班',
    'leave' => '請假',
];

/** @param array<string, mixed> $fields */
function preview_value(array $fields, string $key): string
{
    $value = $fields[$key] ?? '';
    return $value === null ? '' : (string) $value;
}

/** @param list<array<string, mixed>> $rows */
function reference_id_exists(array $rows, mixed $id): bool
{
    if ($id === null || $id === '') {
        return false;
    }

    foreach ($rows as $row) {
        if ((int) $row['id'] === (int) $id) {
            return true;
        }
    }

    return false;
}

function admin_ai_parse_service(PDO $pdo): AiParseService
{
    return new AiParseService(
        $pdo,
        new AiClientFactory(),
        new AiPromptBuilder(),
        new AiResponseValidator(),
        new AiBusinessValidator($pdo),
        new AiInputDateResolver()
    );
}

function admin_ai_direct_write_token(bool $refresh = false): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if ($refresh || !isset($_SESSION['admin_ai_direct_write_token'])) {
        $_SESSION['admin_ai_direct_write_token'] = bin2hex(random_bytes(16));
    }

    return (string) $_SESSION['admin_ai_direct_write_token'];
}

function admin_ai_consume_direct_write_token(string $postedToken): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $sessionToken = (string) ($_SESSION['admin_ai_direct_write_token'] ?? '');
    unset($_SESSION['admin_ai_direct_write_token']);
    if ($postedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
        throw new QuickEntryValidationException(
            '這次送出已處理或已失效，請重新輸入後再送出。',
            ['submit_token' => '送出已處理或已失效。'],
            'unknown',
            []
        );
    }
}

/** @param array<string, mixed> $summary */
function admin_ai_redirect_with_result(array $summary, int $aiParseLogId): never
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $resultId = bin2hex(random_bytes(12));
    $_SESSION['admin_ai_input_results'][$resultId] = [
        'summary' => $summary,
        'ai_parse_log_id' => $aiParseLogId,
    ];

    header('Location: /ai_entry.php?result=' . rawurlencode($resultId));
    exit;
}

/** @return array<string, mixed>|null */
function admin_ai_flash_result(string $resultId): ?array
{
    if ($resultId === '') {
        return null;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $results = $_SESSION['admin_ai_input_results'] ?? [];
    if (!is_array($results) || !isset($results[$resultId]) || !is_array($results[$resultId])) {
        return null;
    }
    $result = $results[$resultId];

    return [
        'summary' => is_array($result['summary'] ?? null) ? $result['summary'] : null,
        'ai_parse_log_id' => (int) ($result['ai_parse_log_id'] ?? 0),
    ];
}

/** @return array<string, mixed> */
function admin_ai_posted_fields(string $type): array
{
    return match ($type) {
        'expense' => [
            'record_date' => post_string('record_date'),
            'item' => post_string('item'),
            'amount' => post_string('amount'),
            'payment_method_id' => post_int('payment_method_id'),
            'payment_method' => post_string('payment_method'),
            'category' => post_string('category'),
        ],
        'income' => [
            'record_date' => post_string('record_date'),
            'source_name' => post_string('source_name'),
            'amount' => post_string('amount'),
            'account_id' => post_int('account_id'),
            'account_name' => post_string('account_name'),
            'category' => post_string('category'),
        ],
        'overtime' => [
            'work_date' => post_string('work_date'),
            'overtime_hours' => post_string('overtime_hours'),
        ],
        'leave' => [
            'leave_date' => post_string('leave_date'),
            'leave_type' => post_string('leave_type'),
            'leave_days' => post_string('leave_days', '0'),
            'leave_hours' => post_string('leave_hours', '0'),
            'note' => post_string('note'),
        ],
        default => [],
    };
}

/** @param array<string, mixed> $summary @param array<string, string> $typeLabels @return array<string, string> */
function admin_ai_summary_rows(array $summary, array $typeLabels): array
{
    $rows = [];
    $type = (string) ($summary['type'] ?? '');
    if ($type !== '' && isset($typeLabels[$type])) {
        $rows['類型'] = $typeLabels[$type];
    }
    $action = (string) ($summary['action'] ?? '');
    if ($action !== '') {
        $rows['狀態'] = $action === 'updated' ? '更新既有資料' : '新增資料';
    }

    admin_ai_add_summary_row($rows, '名稱', $summary['title'] ?? null);
    admin_ai_add_summary_row($rows, '日期', $summary['date'] ?? null);

    if (array_key_exists('amount', $summary) && $summary['amount'] !== null && $summary['amount'] !== '') {
        $amountLabel = match ($type) {
            'overtime' => '時數',
            'leave' => '天數',
            default => '金額',
        };
        $unit = trim((string) ($summary['unit'] ?? ''));
        $rows[$amountLabel] = format_number_clean($summary['amount']) . ($unit !== '' ? ' ' . $unit : '');
    }

    admin_ai_add_summary_row($rows, '分類', $summary['category'] ?? null);
    admin_ai_add_summary_row($rows, '付款方式', $summary['payment_method'] ?? null);
    admin_ai_add_summary_row($rows, '帳戶', $summary['account_name'] ?? null);
    admin_ai_add_summary_row($rows, '帳單月份', $summary['accounting_month'] ?? null);
    admin_ai_add_summary_row($rows, '備註', $summary['note'] ?? null);

    return $rows;
}

/** @param array<string, string> $rows */
function admin_ai_add_summary_row(array &$rows, string $label, mixed $value): void
{
    $value = trim((string) ($value ?? ''));
    if ($value !== '') {
        $rows[$label] = $value;
    }
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($quickEntryMode): ?>
        <meta name="theme-color" content="#1f6f5b">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="快速記帳">
        <link rel="manifest" href="/manifest.json">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <?php endif; ?>
    <title><?= $quickEntryMode ? '快速記帳' : 'AI 快速輸入' ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body class="<?= $quickEntryMode ? 'quick-entry-body' : '' ?>">
    <main class="page ai-entry-page <?= $quickEntryMode ? 'quick-entry-page' : '' ?>">
        <?php if (!$quickEntryMode): ?>
            <div class="page-header">
                <div>
                    <h1>AI 快速輸入</h1>
                    <p>輸入像「早餐 80 現金」這樣的日常句子，AI 會整理成記帳資料。想直接完成記帳可按「AI 記帳」；想先確認日期、金額和付款方式，請選「AI 解析預覽」。</p>
                </div>
                <nav class="nav">
                    <a href="/dashboard.php">首頁</a>
                    <a href="/ai_settings.php">AI 模型設定</a>
                    <a href="/ai_parse_logs.php">解析紀錄</a>
                </nav>
            </div>
        <?php else: ?>
            <div>
                <h1 class="quick-entry-title">快速記帳</h1>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

        <?php if (is_array($summary) && is_array($summary['summary'] ?? null)): ?>
            <section class="preview-panel admin-ai-write-success" aria-live="polite">
                <span class="status-badge">已寫入</span>
                <h2>AI 記帳完成</h2>
                <?php $summaryRows = admin_ai_summary_rows($summary['summary'], $typeLabels); ?>
                <?php if ($summaryRows !== []): ?>
                    <div class="record-meta admin-ai-summary" aria-label="本次寫入摘要">
                        <?php foreach ($summaryRows as $label => $value): ?>
                            <span><?= h($label) ?>：<?= h($value) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="actions">
                    <?php if ((int) ($summary['ai_parse_log_id'] ?? 0) > 0): ?>
                        <a class="button secondary" href="/ai_trace_detail.php?log_id=<?= h((string) $summary['ai_parse_log_id']) ?>">Trace 詳細</a>
                    <?php endif; ?>
                    <a class="button" href="/ai_entry.php">新增下一筆</a>
                </div>
            </section>
        <?php endif; ?>

        <?php if (is_array($alreadyWrittenLink)): ?>
            <section class="preview-panel admin-ai-already-written" aria-live="polite">
                <span class="status-badge">已寫入</span>
                <h2>未重複新增</h2>
                <div class="record-meta admin-ai-summary">
                    <span>類型：<?= h(AiLedgerTraceDisplayService::ledgerLabel($alreadyWrittenLink['ledger_table'] ?? '')) ?></span>
                    <span>狀態：<?= h(AiLedgerTraceDisplayService::actionLabel($alreadyWrittenLink['action'] ?? '')) ?></span>
                    <span>Ledger #<?= h((string) ($alreadyWrittenLink['ledger_id'] ?? '')) ?></span>
                </div>
                <div class="actions">
                    <a class="button secondary" href="/ai_trace_detail.php?log_id=<?= h((string) ($alreadyWrittenLink['ai_parse_log_id'] ?? '')) ?>">查看 trace</a>
                    <a class="button" href="/ai_entry.php">新增下一筆</a>
                </div>
            </section>
        <?php endif; ?>

        <section class="form-panel ai-input-panel <?= $quickEntryMode ? 'quick-entry-input-panel' : '' ?>">
            <form method="post" data-disable-submit="1">
                <?php if (!$quickEntryMode): ?>
                    <input type="hidden" name="submit_token" value="<?= h($directWriteToken) ?>">
                <?php endif; ?>
                <label><?= $quickEntryMode ? '輸入記帳內容' : '自然語言輸入' ?>
                    <textarea class="ai-input" name="input_text" maxlength="2000" required autofocus placeholder="例如：早餐 80 現金"><?= h($inputText) ?></textarea>
                </label>
                <?php if (!$quickEntryMode): ?>
                    <p class="muted">日期可輸入 `6/8`、`2026/6/8`、`今天`、`昨天`或`前天`；未輸入日期時使用今天。</p>
                    <label>預覽資料類型
                        <select name="requested_type">
                            <option value="auto" <?= $requestedType === 'auto' ? 'selected' : '' ?>>自動判斷</option>
                            <option value="expense" <?= $requestedType === 'expense' ? 'selected' : '' ?>>支出</option>
                            <option value="income" <?= $requestedType === 'income' ? 'selected' : '' ?>>收入</option>
                            <option value="overtime" <?= $requestedType === 'overtime' ? 'selected' : '' ?>>加班</option>
                            <option value="leave" <?= $requestedType === 'leave' ? 'selected' : '' ?>>請假</option>
                        </select>
                    </label>
                <?php else: ?>
                    <input type="hidden" name="requested_type" value="auto">
                <?php endif; ?>
                <div class="actions <?= $quickEntryMode ? 'quick-entry-actions' : '' ?>">
                    <button type="submit" name="action" value="<?= $quickEntryMode ? 'parse' : 'write' ?>"><?= $quickEntryMode ? 'AI 解析' : 'AI 記帳' ?></button>
                    <?php if (!$quickEntryMode): ?>
                        <button class="secondary" type="submit" name="action" value="preview">AI 解析預覽</button>
                        <a class="button secondary" href="/ai_entry.php">清除</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <?php if (is_array($preview)): ?>
            <?php
            $fields = is_array($preview['fields'] ?? null) ? $preview['fields'] : [];
            $paymentMethodId = $fields['payment_method_id'] ?? null;
            $paymentMethodMatched = reference_id_exists($paymentMethods, $paymentMethodId);
            $accountId = $fields['account_id'] ?? null;
            $accountMatched = reference_id_exists($accounts, $accountId);
            $leaveType = preview_value($fields, 'leave_type');
            $leaveTypeMatched = $leaveType !== '' && in_array($leaveType, $leaveTypes, true);
            ?>
            <section class="preview-panel">
                <div class="preview-heading">
                    <div>
                        <span class="status-badge">可編輯預覽</span>
                        <h2><?= h($typeLabels[$preview['type']] ?? '未知類型') ?></h2>
                    </div>
                        <?php if ((string) ($preview['provider'] ?? '') !== '' || (string) ($preview['model'] ?? '') !== ''): ?>
                            <p>Provider：<?= h($preview['provider']) ?> ／ Model：<?= h($preview['model'] !== '' ? $preview['model'] : '未設定') ?></p>
                        <?php endif; ?>
                </div>

                <?php if (!$quickEntryMode): ?>
                    <form method="post" class="preview-confirm-form" data-disable-submit="1">
                        <input type="hidden" name="action" value="confirm">
                        <input type="hidden" name="entry_type" value="<?= h((string) $preview['type']) ?>">
                        <input type="hidden" name="input_text" value="<?= h((string) $preview['raw_input']) ?>">
                        <input type="hidden" name="ai_parse_log_id" value="<?= h((string) ($preview['ai_parse_log_id'] ?? 0)) ?>">
                <?php endif; ?>
                <div class="preview-edit-grid">
                    <?php if ($fields === []): ?>
                        <div class="preview-field wide">
                            <span>解析欄位</span>
                            <p>尚未連接模型，無法自動判斷類型。</p>
                        </div>
                    <?php elseif ($preview['type'] === 'expense'): ?>
                        <label class="preview-field">日期
                            <input type="date" name="record_date" value="<?= h(preview_value($fields, 'record_date')) ?>" required>
                            <?php if (isset($fieldErrors['record_date'])): ?><small class="field-error"><?= h($fieldErrors['record_date']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">項目
                            <input name="item" value="<?= h(preview_value($fields, 'item')) ?>" required>
                            <?php if (isset($fieldErrors['item'])): ?><small class="field-error"><?= h($fieldErrors['item']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">金額
                            <input type="number" name="amount" min="0.01" step="0.01" value="<?= h(preview_value($fields, 'amount')) ?>" required>
                            <?php if (isset($fieldErrors['amount'])): ?><small class="field-error"><?= h($fieldErrors['amount']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">付款方式
                            <select name="payment_method_id" required>
                                <?php if (!$paymentMethodMatched): ?>
                                    <option value="" selected><?= h(preview_value($fields, 'payment_method') === '' ? '未比對，請選擇' : '未比對：' . preview_value($fields, 'payment_method')) ?></option>
                                <?php endif; ?>
                                <?php foreach ($paymentMethods as $method): ?>
                                    <option value="<?= (int) $method['id'] ?>" <?= (int) $method['id'] === (int) $paymentMethodId ? 'selected' : '' ?>><?= h((string) $method['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="match-status <?= $paymentMethodMatched ? 'matched' : 'unmatched' ?>">
                                <?= $paymentMethodMatched ? '已比對後台付款方式' : '未比對，請從啟用中的付款方式選擇' ?>
                            </small>
                            <?php if (isset($fieldErrors['payment_method_id'])): ?><small class="field-error"><?= h($fieldErrors['payment_method_id']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">分類
                            <input name="category" value="<?= h(preview_value($fields, 'category') ?: '其他') ?>" required>
                        </label>
                        <label class="preview-field">帳單月份
                            <input class="system-value" value="<?= h(preview_value($fields, 'accounting_month')) ?>" readonly>
                            <small>由 AccountingMonthService 依日期與付款方式結算日計算</small>
                        </label>
                    <?php elseif ($preview['type'] === 'income'): ?>
                        <label class="preview-field">日期
                            <input type="date" name="record_date" value="<?= h(preview_value($fields, 'record_date')) ?>" required>
                            <?php if (isset($fieldErrors['record_date'])): ?><small class="field-error"><?= h($fieldErrors['record_date']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">收入來源
                            <input name="source_name" value="<?= h(preview_value($fields, 'source_name')) ?>" required>
                            <?php if (isset($fieldErrors['source_name'])): ?><small class="field-error"><?= h($fieldErrors['source_name']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">金額
                            <input type="number" name="amount" min="0.01" step="0.01" value="<?= h(preview_value($fields, 'amount')) ?>" required>
                            <?php if (isset($fieldErrors['amount'])): ?><small class="field-error"><?= h($fieldErrors['amount']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">帳戶
                            <select name="account_id">
                                <?php if (!$accountMatched): ?>
                                    <option value="" selected><?= h(preview_value($fields, 'account_name') === '' ? '未指定帳戶' : '未比對：' . preview_value($fields, 'account_name')) ?></option>
                                <?php else: ?>
                                    <option value="">未指定帳戶</option>
                                <?php endif; ?>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?= (int) $account['id'] ?>" <?= (int) $account['id'] === (int) $accountId ? 'selected' : '' ?>><?= h((string) $account['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="match-status <?= $accountMatched ? 'matched' : 'unmatched' ?>">
                                <?= $accountMatched ? '已比對後台帳戶' : (preview_value($fields, 'account_name') === '' ? '帳戶為選填，目前未指定' : '未比對，請從啟用中的帳戶選擇') ?>
                            </small>
                            <input type="hidden" name="account_name" value="">
                            <?php if (isset($fieldErrors['account_id'])): ?><small class="field-error"><?= h($fieldErrors['account_id']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">分類
                            <input name="category" value="<?= h(preview_value($fields, 'category') ?: '其他') ?>" required>
                        </label>
                        <label class="preview-field">歸屬月份
                            <input class="system-value" value="<?= h(preview_value($fields, 'accounting_month')) ?>" readonly>
                        </label>
                    <?php elseif ($preview['type'] === 'overtime'): ?>
                        <label class="preview-field">加班日期
                            <input type="date" name="work_date" value="<?= h(preview_value($fields, 'work_date')) ?>" required>
                            <?php if (isset($fieldErrors['work_date'])): ?><small class="field-error"><?= h($fieldErrors['work_date']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">加班時數
                            <input type="number" name="overtime_hours" min="0.5" step="0.5" value="<?= h(preview_value($fields, 'overtime_hours')) ?>" required>
                            <?php if (isset($fieldErrors['overtime_hours'])): ?><small class="field-error"><?= h($fieldErrors['overtime_hours']) ?></small><?php endif; ?>
                        </label>
                    <?php elseif ($preview['type'] === 'leave'): ?>
                        <label class="preview-field">請假日期
                            <input type="date" name="leave_date" value="<?= h(preview_value($fields, 'leave_date')) ?>" required>
                            <?php if (isset($fieldErrors['leave_date'])): ?><small class="field-error"><?= h($fieldErrors['leave_date']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">假別
                            <select name="leave_type" required>
                                <?php if (!$leaveTypeMatched): ?>
                                    <option value="" selected><?= h($leaveType === '' ? '待確認，請選擇假別' : '未比對：' . $leaveType) ?></option>
                                <?php endif; ?>
                                <?php foreach ($leaveTypes as $type): ?>
                                    <option value="<?= h((string) $type) ?>" <?= (string) $type === $leaveType ? 'selected' : '' ?>><?= h((string) $type) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="match-status <?= $leaveTypeMatched ? 'matched' : 'unmatched' ?>">
                                <?= $leaveTypeMatched ? '已比對後台假別' : '未比對，請從啟用中的假別選擇' ?>
                            </small>
                            <?php if (isset($fieldErrors['leave_type'])): ?><small class="field-error"><?= h($fieldErrors['leave_type']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">請假天數
                            <input type="number" name="leave_days" min="0" step="0.5" value="<?= h(preview_value($fields, 'leave_days')) ?>" required>
                            <?php if (isset($fieldErrors['leave_days'])): ?><small class="field-error"><?= h($fieldErrors['leave_days']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">請假時數
                            <input type="number" name="leave_hours" min="0" step="0.5" value="<?= h(preview_value($fields, 'leave_hours')) ?>" required>
                            <?php if (isset($fieldErrors['leave_hours'])): ?><small class="field-error"><?= h($fieldErrors['leave_hours']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field wide">備註
                            <textarea class="preview-note" name="note"><?= h(preview_value($fields, 'note')) ?></textarea>
                        </label>
                        <label class="preview-field">折算請假天數
                            <input class="system-value" value="<?= h(preview_value($fields, 'total_leave_days')) ?>" readonly>
                            <small>系統依 8 小時折算 1 天</small>
                        </label>
                    <?php endif; ?>
                    <div class="preview-field wide preview-raw-input">
                        <span>原始輸入</span>
                        <p><?= nl2br(h($preview['raw_input'])) ?></p>
                    </div>
                    <?php if (!$quickEntryMode): ?>
                        <div class="actions wide preview-confirm-actions">
                            <button type="submit">確認記帳</button>
                            <a class="button secondary" href="/ai_entry.php">取消</a>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!$quickEntryMode): ?>
                    </form>
                <?php endif; ?>

                <?php if ($preview['warnings'] !== []): ?>
                    <div class="preview-warnings">
                        <?php foreach ($preview['warnings'] as $warning): ?>
                            <p><?= h($warning) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <p class="muted preview-safety-note">這是預覽，還沒有新增正式記錄。確認內容無誤後，再按「確認記帳」完成儲存；欄位不足或資料無法比對時，會先請你修正。</p>
            </section>
        <?php endif; ?>
    </main>
    <?php if (!$quickEntryMode): ?>
        <?php render_mobile_nav('back'); ?>
    <?php else: ?>
        <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/service-worker.js');
            });
        }
        </script>
    <?php endif; ?>
    <script>
    document.querySelectorAll('form[data-disable-submit="1"]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var button = event.submitter || form.querySelector('button[type="submit"]');
            if (!button || button.disabled) {
                return;
            }
            button.disabled = true;
            button.textContent = '處理中...';
        });
    });
    </script>
</body>
</html>
