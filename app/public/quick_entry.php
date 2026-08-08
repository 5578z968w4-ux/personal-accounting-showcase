<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/html.php';
require_once dirname(__DIR__) . '/src/AiParseService.php';
require_once dirname(__DIR__) . '/src/AiInputDateResolver.php';
require_once dirname(__DIR__) . '/src/QuickEntryWriteService.php';

DemoMode::guardPublicEndpoint('快速記帳');

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
$error = '';
$summary = null;
$correctionType = '';
$correctionFields = [];
$fieldErrors = [];
$userName = (string) app_env('APP_LOGIN_USERNAME', '');
$traceAiParseLogId = 0;

/** @return array<string, mixed> */
function quick_entry_posted_fields(string $type): array
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

/** @param array<string, mixed> $fields */
function quick_entry_value(array $fields, string $key): string
{
    $value = $fields[$key] ?? '';
    return $value === null ? '' : (string) $value;
}

/** @return array<string, mixed>|null */
function quick_entry_trace_context(int $aiParseLogId): ?array
{
    if ($aiParseLogId < 1) {
        return null;
    }

    return [
        'ai_parse_log_id' => $aiParseLogId,
        'source' => 'quick_pwa',
    ];
}

/** @param array<string, mixed> $summary @param array<string, string> $typeLabels @return array<string, string> */
function quick_entry_summary_rows(array $summary, array $typeLabels): array
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

    quick_entry_add_summary_row($rows, '名稱', $summary['title'] ?? null);
    quick_entry_add_summary_row($rows, '日期', $summary['date'] ?? null);

    if (array_key_exists('amount', $summary) && $summary['amount'] !== null && $summary['amount'] !== '') {
        $amountLabel = match ($type) {
            'overtime' => '時數',
            'leave' => '天數',
            default => '金額',
        };
        $unit = trim((string) ($summary['unit'] ?? ''));
        $rows[$amountLabel] = format_number_clean($summary['amount']) . ($unit !== '' ? ' ' . $unit : '');
    }

    quick_entry_add_summary_row($rows, '分類', $summary['category'] ?? null);
    quick_entry_add_summary_row($rows, '付款方式', $summary['payment_method'] ?? null);
    quick_entry_add_summary_row($rows, '帳戶', $summary['account_name'] ?? null);
    quick_entry_add_summary_row($rows, '帳單月份', $summary['accounting_month'] ?? null);
    quick_entry_add_summary_row($rows, '備註', $summary['note'] ?? null);
    quick_entry_add_summary_row($rows, '原始輸入', $summary['raw_input'] ?? null);

    return $rows;
}

/** @param array<string, string> $rows */
function quick_entry_add_summary_row(array &$rows, string $label, mixed $value): void
{
    $value = trim((string) ($value ?? ''));
    if ($value !== '') {
        $rows[$label] = $value;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_string('action', 'parse');
    $inputText = post_string('input_text');

    try {
        $writer = new QuickEntryWriteService($pdo);
        if ($action === 'correct') {
            $correctionType = post_string('entry_type');
            $correctionFields = quick_entry_posted_fields($correctionType);
            $traceAiParseLogId = post_int('ai_parse_log_id');
            $summary = $writer->save(
                $correctionType,
                $correctionFields,
                $inputText,
                $userName,
                quick_entry_trace_context($traceAiParseLogId)
            );
        } else {
            $service = new AiParseService(
                $pdo,
                new AiClientFactory(),
                new AiPromptBuilder(),
                new AiResponseValidator(),
                new AiBusinessValidator($pdo),
                new AiInputDateResolver()
            );
            $parsed = $service->preview($inputText, 'auto', $settings, 'quick_pwa', $userName);
            $traceAiParseLogId = (int) ($parsed['ai_parse_log_id'] ?? 0);
            $summary = $writer->save(
                (string) $parsed['type'],
                is_array($parsed['fields'] ?? null) ? $parsed['fields'] : [],
                $inputText,
                $userName,
                quick_entry_trace_context($traceAiParseLogId)
            );
        }

        $inputText = '';
        $correctionType = '';
        $correctionFields = [];
    } catch (QuickEntryValidationException $exception) {
        $error = $exception->getMessage();
        $correctionType = $exception->entryType();
        $correctionFields = $exception->fields();
        $fieldErrors = $exception->fieldErrors();
    } catch (AiParseException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable) {
        $error = safe_error_message();
    }
}

$typeLabels = [
    'expense' => '支出',
    'income' => '收入',
    'overtime' => '加班',
    'leave' => '請假',
];
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1f6f5b">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="快速記帳">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <title>快速記帳</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body class="quick-entry-body">
    <main class="page ai-entry-page quick-entry-page">
        <div>
            <h1 class="quick-entry-title">快速記帳</h1>
        </div>

        <?php if ($error !== ''): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

        <?php if (is_array($summary)): ?>
            <section class="preview-panel quick-entry-success" aria-live="polite" data-quick-entry-complete="1">
                <span class="status-badge">寫入成功</span>
                <h2>已完成</h2>
                <p>已完成，可返回主畫面</p>
                <?php $summaryRows = quick_entry_summary_rows($summary, $typeLabels); ?>
                <?php if ($summaryRows !== []): ?>
                    <div class="record-meta" aria-label="本次記帳摘要">
                        <?php foreach ($summaryRows as $label => $value): ?>
                            <span><?= h($label) ?>：<?= h($value) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (!is_array($summary)): ?>
            <section class="form-panel ai-input-panel quick-entry-input-panel">
                <form method="post" data-disable-submit="1">
                    <input type="hidden" name="action" value="parse">
                    <label>輸入記帳內容
                        <textarea class="ai-input" name="input_text" maxlength="2000" required autofocus placeholder="例如：早餐 80 現金"><?= h($inputText) ?></textarea>
                    </label>
                    <div class="actions quick-entry-actions">
                        <button type="submit">直接記帳</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($correctionType !== ''): ?>
            <section class="preview-panel">
                <div class="preview-heading">
                    <div>
                        <span class="status-badge">需要修正</span>
                        <h2><?= h($typeLabels[$correctionType] ?? '記帳資料') ?></h2>
                    </div>
                </div>
                <form method="post" class="preview-edit-grid" data-disable-submit="1">
                    <input type="hidden" name="action" value="correct">
                    <input type="hidden" name="entry_type" value="<?= h($correctionType) ?>">
                    <input type="hidden" name="input_text" value="<?= h($inputText) ?>">
                    <input type="hidden" name="ai_parse_log_id" value="<?= $traceAiParseLogId ?>">

                    <?php if ($correctionType === 'expense'): ?>
                        <label class="preview-field">日期
                            <input type="date" name="record_date" value="<?= h(quick_entry_value($correctionFields, 'record_date')) ?>" required>
                            <?php if (isset($fieldErrors['record_date'])): ?><small class="field-error"><?= h($fieldErrors['record_date']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">項目
                            <input name="item" value="<?= h(quick_entry_value($correctionFields, 'item')) ?>" required>
                            <?php if (isset($fieldErrors['item'])): ?><small class="field-error"><?= h($fieldErrors['item']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">金額
                            <input type="number" name="amount" min="0.01" step="0.01" value="<?= h(quick_entry_value($correctionFields, 'amount')) ?>" required>
                            <?php if (isset($fieldErrors['amount'])): ?><small class="field-error"><?= h($fieldErrors['amount']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">付款方式
                            <select name="payment_method_id" required>
                                <option value="">請選擇</option>
                                <?php foreach ($paymentMethods as $method): ?>
                                    <option value="<?= (int) $method['id'] ?>" <?= (int) ($correctionFields['payment_method_id'] ?? 0) === (int) $method['id'] ? 'selected' : '' ?>><?= h((string) $method['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($fieldErrors['payment_method_id'])): ?><small class="field-error"><?= h($fieldErrors['payment_method_id']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">分類
                            <input name="category" value="<?= h(quick_entry_value($correctionFields, 'category') ?: '其他') ?>" required>
                        </label>
                    <?php elseif ($correctionType === 'income'): ?>
                        <label class="preview-field">日期
                            <input type="date" name="record_date" value="<?= h(quick_entry_value($correctionFields, 'record_date')) ?>" required>
                        </label>
                        <label class="preview-field">收入來源
                            <input name="source_name" value="<?= h(quick_entry_value($correctionFields, 'source_name')) ?>" required>
                        </label>
                        <label class="preview-field">金額
                            <input type="number" name="amount" min="0.01" step="0.01" value="<?= h(quick_entry_value($correctionFields, 'amount')) ?>" required>
                        </label>
                        <label class="preview-field">帳戶
                            <select name="account_id">
                                <option value="">未指定</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?= (int) $account['id'] ?>" <?= (int) ($correctionFields['account_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>><?= h((string) $account['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="account_name" value="">
                            <?php if (isset($fieldErrors['account_id'])): ?><small class="field-error"><?= h($fieldErrors['account_id']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">分類
                            <input name="category" value="<?= h(quick_entry_value($correctionFields, 'category') ?: '其他') ?>" required>
                        </label>
                    <?php elseif ($correctionType === 'overtime'): ?>
                        <label class="preview-field">加班日期
                            <input type="date" name="work_date" value="<?= h(quick_entry_value($correctionFields, 'work_date')) ?>" required>
                        </label>
                        <label class="preview-field">加班時數
                            <input type="number" name="overtime_hours" min="0.5" step="0.5" value="<?= h(quick_entry_value($correctionFields, 'overtime_hours')) ?>" required>
                        </label>
                    <?php elseif ($correctionType === 'leave'): ?>
                        <label class="preview-field">請假日期
                            <input type="date" name="leave_date" value="<?= h(quick_entry_value($correctionFields, 'leave_date')) ?>" required>
                        </label>
                        <label class="preview-field">假別
                            <select name="leave_type" required>
                                <option value="">請選擇</option>
                                <?php foreach ($leaveTypes as $leaveType): ?>
                                    <option value="<?= h((string) $leaveType) ?>" <?= (string) $leaveType === quick_entry_value($correctionFields, 'leave_type') ? 'selected' : '' ?>><?= h((string) $leaveType) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($fieldErrors['leave_type'])): ?><small class="field-error"><?= h($fieldErrors['leave_type']) ?></small><?php endif; ?>
                        </label>
                        <label class="preview-field">請假天數
                            <input type="number" name="leave_days" min="0" step="0.5" value="<?= h(quick_entry_value($correctionFields, 'leave_days')) ?>" required>
                        </label>
                        <label class="preview-field">請假時數
                            <input type="number" name="leave_hours" min="0" step="0.5" value="<?= h(quick_entry_value($correctionFields, 'leave_hours')) ?>" required>
                        </label>
                        <label class="preview-field wide">備註
                            <textarea name="note"><?= h(quick_entry_value($correctionFields, 'note')) ?></textarea>
                        </label>
                    <?php endif; ?>
                    <div class="actions wide">
                        <button type="submit">修正後寫入</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </main>
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/service-worker.js');
        });
    }
    document.querySelectorAll('form[data-disable-submit="1"]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var button = form.querySelector('button[type="submit"]');
            if (!button || button.disabled) {
                return;
            }
            button.disabled = true;
            button.textContent = '處理中...';
        });
    });
    if (document.querySelector('[data-quick-entry-complete="1"]')) {
        window.setTimeout(function () {
            window.close();
        }, 500);
    }
    </script>
</body>
</html>
