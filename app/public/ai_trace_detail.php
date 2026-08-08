<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/html.php';
require_once dirname(__DIR__) . '/src/AiLogDisplayHelper.php';
require_once dirname(__DIR__) . '/src/AiLedgerTraceDisplayService.php';

require_login();

$pdo = app_db();
$traceService = new AiLedgerTraceDisplayService($pdo);

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

function trace_detail_query_int(string $key): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? 0 : $value;
}

function trace_detail_link(string $ledgerTable, int $ledgerId): string
{
    return '/ai_trace_detail.php?' . http_build_query([
        'ledger_table' => $ledgerTable,
        'ledger_id' => $ledgerId,
    ]);
}

$logId = trace_detail_query_int('log_id');
$ledgerTable = trim((string) ($_GET['ledger_table'] ?? ''));
$ledgerId = trace_detail_query_int('ledger_id');
$parseLog = null;
$links = [];
$mode = 'none';
$error = '';

try {
    if ($logId > 0) {
        $mode = 'log';
        $parseLog = $traceService->parseLogById($logId);
        $links = $traceService->linksByParseLogId($logId);
    } elseif ($ledgerTable !== '' || $ledgerId > 0) {
        $mode = 'ledger';
        $links = $traceService->linksByLedgerRow($ledgerTable, $ledgerId);
    }
} catch (InvalidArgumentException) {
    $error = '不支援的 trace 查詢條件。';
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Trace 詳細</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="page trace-detail-page">
        <div class="page-header">
            <div>
                <h1>AI Trace 詳細</h1>
                <p>查看 AI 紀錄與帳務資料之間已建立的可靠寫入連結。</p>
            </div>
            <nav class="nav">
                <a href="/ai_parse_logs.php">AI 記帳紀錄</a>
                <a href="/expenses.php">支出</a>
                <a href="/incomes.php">收入</a>
                <a href="/overtime.php">加班</a>
                <a href="/leave.php">請假</a>
            </nav>
        </div>

        <?php if ($error !== ''): ?>
            <p class="error"><?= h($error) ?></p>
        <?php elseif ($mode === 'none'): ?>
            <section class="notice-panel">
                <h2>缺少查詢條件</h2>
            <p>請從 AI 記帳紀錄或四個帳務頁面的 Trace 詳細連結進入。</p>
            </section>
        <?php elseif ($mode === 'log'): ?>
            <?php if (!is_array($parseLog)): ?>
                <section class="notice-panel">
                    <h2>找不到 AI 記帳紀錄</h2>
                    <p>Log #<?= h((string) $logId) ?> 不存在，或目前資料庫沒有這筆紀錄。</p>
                </section>
            <?php else: ?>
                <section class="form-panel trace-summary-panel">
                    <div class="section-title-row">
                        <h2>解析紀錄 #<?= h((string) $parseLog['id']) ?></h2>
                        <span class="status-badge"><?= h($statusLabels[$parseLog['parse_status']] ?? $parseLog['parse_status']) ?></span>
                    </div>
                    <div class="trace-detail-grid">
                        <span>類型：<?= h(ai_log_type_label($parseLog['parsed_type'] ?: '未分類')) ?></span>
                        <span>來源：<?= h(ai_log_source_label($parseLog['source'])) ?></span>
                        <span>Provider：<?= h($parseLog['provider'] ?: '-') ?></span>
                        <span>Model：<?= h($parseLog['model_name'] ?: '-') ?></span>
                        <span>耗時：<?= h($parseLog['duration_ms'] === null ? '-' : (string) $parseLog['duration_ms'] . ' ms') ?></span>
                        <span>建立時間：<?= h($parseLog['created_at']) ?></span>
                        <span>記帳人：<?= h(AiLedgerTraceDisplayService::textOrDash($parseLog['user_name'] ?? null)) ?></span>
                        <?php if ($parseLog['error_code']): ?><span>錯誤代碼：<?= h($parseLog['error_code']) ?></span><?php endif; ?>
                    </div>
                    <?php $summaryRows = ai_log_summary_rows($parseLog['parsed_json'], $parseLog['parsed_type']); ?>
                    <?php if ($summaryRows !== []): ?>
                        <div class="record-meta ai-log-summary">
                            <?php foreach ($summaryRows as $label => $value): ?>
                                <span><?= h($label) ?>：<?= h($value) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="ai-log-input">
                        <strong>輸入內容</strong>
                        <p><?= nl2br(h($parseLog['raw_input'])) ?></p>
                    </div>
                    <?php if ($parseLog['error_message']): ?>
                        <p class="error"><?= h($parseLog['error_message']) ?></p>
                    <?php endif; ?>
                    <?php if ($parseLog['parsed_json'] || $parseLog['ai_response']): ?>
                        <details class="record-edit">
                            <summary>查看保存內容</summary>
                            <?php if ($parseLog['parsed_json']): ?>
                                <h3>解析 JSON</h3>
                                <pre class="json-preview"><?= h($parseLog['parsed_json']) ?></pre>
                            <?php endif; ?>
                            <?php if ($parseLog['ai_response']): ?>
                                <h3>AI 原始回應</h3>
                                <pre class="json-preview"><?= h($parseLog['ai_response']) ?></pre>
                            <?php endif; ?>
                        </details>
                    <?php endif; ?>
                </section>

                <section class="table-panel record-panel">
                    <div class="section-title-row">
                        <h2>寫入連結</h2>
                        <span class="muted"><?= h((string) count($links)) ?> 筆</span>
                    </div>
                    <?php if ($links === []): ?>
                        <p class="muted">這筆 AI 記帳紀錄尚無寫入連結。這通常代表解析未成功寫入，或是舊資料尚未建立 trace link。</p>
                    <?php endif; ?>
                    <div class="record-list">
                        <?php foreach ($links as $link): ?>
                            <article class="record-card">
                                <div class="record-main">
                                    <div class="record-title">
                                        <strong><?= h(AiLedgerTraceDisplayService::ledgerLabel($link['ledger_table'])) ?> #<?= h((string) $link['ledger_id']) ?></strong>
                                        <span>Link #<?= h((string) $link['id']) ?> · <?= h($link['created_at'] ?? '-') ?></span>
                                    </div>
                                    <a class="button secondary trace-detail-action" href="<?= h(trace_detail_link((string) $link['ledger_table'], (int) $link['ledger_id'])) ?>">Ledger 詳細</a>
                                </div>
                                <div class="record-meta">
                                    <span>動作：<?= h(AiLedgerTraceDisplayService::actionLabel($link['action'])) ?></span>
                                <span class="source-chip">來源：<?= h(AiLedgerTraceDisplayService::sourceLabel($link['source'])) ?></span>
                                <span class="debug-chip">Snapshot 類型：<?= h(ai_log_type_label($link['parsed_type_snapshot'] ?: '-')) ?></span>
                                <span>記帳人：<?= h(AiLedgerTraceDisplayService::textOrDash($link['user_name'] ?? null)) ?></span>
                                </div>
                                <div class="ai-log-input">
                                    <strong>Link 輸入快照</strong>
                                    <p><?= nl2br(h(AiLedgerTraceDisplayService::textOrDash($link['raw_input_snapshot'] ?? null))) ?></p>
                                </div>
                                <?php if (!empty($link['parsed_json_snapshot'])): ?>
                                    <details class="record-edit">
                                        <summary>查看 link JSON 快照</summary>
                                        <pre class="json-preview"><?= h($link['parsed_json_snapshot']) ?></pre>
                                    </details>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php elseif ($mode === 'ledger'): ?>
            <section class="form-panel trace-summary-panel">
                <div class="section-title-row">
                    <h2><?= h(AiLedgerTraceDisplayService::ledgerLabel($ledgerTable)) ?> #<?= h((string) $ledgerId) ?></h2>
                    <span class="muted">AI trace link history</span>
                </div>
                    <p class="muted">此頁只列出已存在的 trace link。若沒有連結，不會用帳務欄位反推 AI log。</p>
            </section>

            <section class="table-panel record-panel">
                <div class="section-title-row">
                    <h2>AI 連結歷史</h2>
                    <span class="muted"><?= h((string) count($links)) ?> 筆</span>
                </div>
                <?php if ($links === []): ?>
                    <p class="muted">這筆帳務資料尚無 AI trace link，可能是手動資料或 Phase 3D 前的舊資料。</p>
                <?php endif; ?>
                <div class="record-list">
                    <?php foreach ($links as $link): ?>
                        <article class="record-card">
                            <div class="record-main">
                                <div class="record-title">
                                    <strong>AI Log #<?= h((string) $link['ai_parse_log_id']) ?></strong>
                                    <span>Link #<?= h((string) $link['id']) ?> · <?= h($link['created_at'] ?? '-') ?></span>
                                </div>
                                <a class="button secondary trace-detail-action" href="/ai_trace_detail.php?log_id=<?= h((string) $link['ai_parse_log_id']) ?>">Log 詳細</a>
                            </div>
                            <div class="record-meta">
                                <span class="trace-chip">動作：<?= h(AiLedgerTraceDisplayService::actionLabel($link['action'])) ?></span>
                                <span class="source-chip">Link 來源：<?= h(AiLedgerTraceDisplayService::sourceLabel($link['source'])) ?></span>
                                <span>Log 狀態：<?= h($statusLabels[$link['parse_status']] ?? AiLedgerTraceDisplayService::textOrDash($link['parse_status'] ?? null)) ?></span>
                                <span>Log 類型：<?= h(ai_log_type_label($link['parsed_type'] ?? $link['parsed_type_snapshot'] ?? '-')) ?></span>
                                <span>Log 來源：<?= h(ai_log_source_label($link['log_source'] ?? null)) ?></span>
                            </div>
                            <div class="ai-log-input">
                                <strong>Link 輸入快照</strong>
                                <p><?= nl2br(h(AiLedgerTraceDisplayService::textOrDash($link['raw_input_snapshot'] ?? null))) ?></p>
                            </div>
                            <?php if (!empty($link['log_raw_input'])): ?>
                                <div class="ai-log-input">
                                    <strong>AI Log 原始輸入</strong>
                                    <p><?= nl2br(h($link['log_raw_input'])) ?></p>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($link['parsed_json_snapshot']) || !empty($link['parsed_json'])): ?>
                                <details class="record-edit">
                                    <summary>查看 JSON</summary>
                                    <?php if (!empty($link['parsed_json_snapshot'])): ?>
                                        <h3>Link JSON 快照</h3>
                                        <pre class="json-preview"><?= h($link['parsed_json_snapshot']) ?></pre>
                                    <?php endif; ?>
                                    <?php if (!empty($link['parsed_json'])): ?>
                                        <h3>AI Log 解析 JSON</h3>
                                        <pre class="json-preview"><?= h($link['parsed_json']) ?></pre>
                                    <?php endif; ?>
                                </details>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>
    <?php render_mobile_nav('back'); ?>
</body>
</html>
