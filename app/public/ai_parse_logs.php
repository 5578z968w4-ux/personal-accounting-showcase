<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/html.php';
require_once dirname(__DIR__) . '/src/AiLogDisplayHelper.php';
require_once dirname(__DIR__) . '/src/AiLedgerTraceDisplayService.php';
require_once dirname(__DIR__) . '/src/AiParseLogListService.php';

require_login();

$pdo = app_db();
$sourceOptions = [
    'quick_pwa' => ai_log_source_label('quick_pwa'),
    'ios_shortcut' => ai_log_source_label('ios_shortcut'),
    'shortcut_api' => ai_log_source_label('shortcut_api'),
    'admin_ai_input' => ai_log_source_label('admin_ai_input'),
    'web' => ai_log_source_label('web'),
    'quick_entry_check' => ai_log_source_label('quick_entry_check'),
    'stage2_check' => ai_log_source_label('stage2_check'),
];
$statusLabels = [
    'success' => '成功',
    'invalid_json' => 'JSON 錯誤',
    'validation_failed' => '驗證失敗',
    'provider_error' => 'Provider 錯誤',
    'timeout' => '逾時',
    'disabled' => '未啟用',
    'config_error' => '設定錯誤',
    'pending' => '等待中',
];
$typeOptions = [
    'expense' => ai_log_type_label('expense'),
    'income' => ai_log_type_label('income'),
    'overtime' => ai_log_type_label('overtime'),
    'leave' => ai_log_type_label('leave'),
];

function ai_parse_logs_query_string(string $key): string
{
    return trim((string) ($_GET[$key] ?? ''));
}

function ai_parse_logs_date(string $key): string
{
    $value = ai_parse_logs_query_string($key);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Taipei'));

    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : '';
}

function ai_parse_logs_url(array $filters, ?int $beforeId = null): string
{
    if ($beforeId !== null) {
        $filters['before_id'] = (string) $beforeId;
    }
    $query = http_build_query(array_filter($filters, static fn (string $value): bool => $value !== ''));

    return '/ai_parse_logs.php' . ($query === '' ? '' : '?' . $query);
}

$filters = [
    'source' => ai_parse_logs_query_string('source'),
    'status' => ai_parse_logs_query_string('status'),
    'type' => ai_parse_logs_query_string('type'),
    'date_from' => ai_parse_logs_date('date_from'),
    'date_to' => ai_parse_logs_date('date_to'),
];
foreach (['source' => $sourceOptions, 'status' => $statusLabels, 'type' => $typeOptions] as $key => $options) {
    if ($filters[$key] !== '' && !array_key_exists($filters[$key], $options)) {
        $filters[$key] = '';
    }
}
$beforeId = filter_input(INPUT_GET, 'before_id', FILTER_VALIDATE_INT);
$beforeId = is_int($beforeId) && $beforeId > 0 ? $beforeId : null;
$list = (new AiParseLogListService($pdo))->latest($filters, $beforeId);
$rows = $list['rows'];
$traceLinks = (new AiLedgerTraceDisplayService($pdo))->latestLinksByParseLogIds(array_column($rows, 'id'));
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI 記帳紀錄</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="page">
        <div class="page-header">
            <div>
                <h1>AI 記帳紀錄</h1>
                <p>最新 20 筆 AI 解析摘要、來源與寫入追蹤；完整內容請進入單筆 Trace 詳細。</p>
            </div>
            <nav class="nav">
                <a href="/dashboard.php">主控台</a>
                <a href="/ai_entry.php">AI 快速輸入</a>
                <a href="/ai_settings.php">AI 模型設定</a>
            </nav>
        </div>

        <section class="form-panel">
            <div class="section-title-row"><h2>篩選紀錄</h2><a class="link" href="/ai_parse_logs.php">清除條件</a></div>
            <form method="get" action="/ai_parse_logs.php" class="ai-log-filter-grid">
                <label>來源<select name="source"><option value="">全部來源</option><?php foreach ($sourceOptions as $value => $label): ?><option value="<?= h($value) ?>"<?= $filters['source'] === $value ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
                <label>狀態<select name="status"><option value="">全部狀態</option><?php foreach ($statusLabels as $value => $label): ?><option value="<?= h($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
                <label>類型<select name="type"><option value="">全部類型</option><?php foreach ($typeOptions as $value => $label): ?><option value="<?= h($value) ?>"<?= $filters['type'] === $value ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
                <label>開始日期<input type="date" name="date_from" value="<?= h($filters['date_from']) ?>"></label>
                <label>結束日期<input type="date" name="date_to" value="<?= h($filters['date_to']) ?>"></label>
                <button type="submit">套用篩選</button>
            </form>
        </section>

        <section class="record-list">
            <?php if ($rows === []): ?>
                <article class="record-card"><p class="muted">目前沒有符合條件的解析紀錄。</p></article>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php $summaryRows = ai_log_summary_rows($row['parsed_json_preview'], $row['parsed_type']); ?>
                <?php $traceLink = $traceLinks[(int) $row['id']] ?? null; ?>
                <article class="record-card ai-log-card">
                    <div class="record-main">
                        <div class="record-title">
                            <strong><?= h($statusLabels[$row['parse_status']] ?? $row['parse_status']) ?></strong>
                            <span><?= h(AiLedgerTraceDisplayService::dateTimeLabel($row['created_at'])) ?></span>
                        </div>
                        <span class="status-badge"><?= h(ai_log_type_label($row['parsed_type'] ?: '未分類')) ?></span>
                    </div>
                    <div class="ai-log-input">
                        <strong>原始輸入</strong>
                        <p><?= nl2br(h($row['raw_input_preview'])) ?></p>
                        <?php if ((int) $row['raw_input_is_truncated'] === 1): ?><span class="muted">內容較長，請進入 Trace 詳細查看全文。</span><?php endif; ?>
                    </div>
                    <?php if ($summaryRows !== []): ?>
                        <div class="record-meta ai-log-summary">
                            <?php foreach ($summaryRows as $label => $value): ?>
                                <span><?= h($label) ?>：<?= h($value) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="record-meta">
                        <span class="source-chip">來源：<?= h(ai_log_source_label($row['source'])) ?></span>
                        <a href="/ai_trace_detail.php?log_id=<?= h((string) $row['id']) ?>">查看完整紀錄與 Trace</a>
                    </div>
                    <div class="record-meta">
                        <?php if (is_array($traceLink)): ?>
                            <span class="trace-chip">寫入連結：<?= h(AiLedgerTraceDisplayService::ledgerLabel($traceLink['ledger_table'])) ?> #<?= h((string) $traceLink['ledger_id']) ?></span>
                            <span class="trace-chip">動作：<?= h(AiLedgerTraceDisplayService::actionLabel($traceLink['action'])) ?></span>
                            <?php if ((int) ($traceLink['link_count'] ?? 0) > 1): ?><span class="trace-chip">AI x<?= h((string) $traceLink['link_count']) ?></span><?php endif; ?>
                        <?php else: ?>
                            <span>寫入連結：尚無寫入連結</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($row['error_message_preview']): ?><p class="error"><?= h($row['error_message_preview']) ?><?= (int) $row['error_message_is_truncated'] === 1 ? '（完整錯誤請查看詳細）' : '' ?></p><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
        <?php if ($list['next_before_id'] !== null): ?>
            <div class="actions ai-log-pagination"><a class="button secondary" href="<?= h(ai_parse_logs_url($filters, $list['next_before_id'])) ?>">載入更早的 20 筆</a></div>
        <?php endif; ?>
    </main>
    <?php render_mobile_nav('back'); ?>
</body>
</html>
