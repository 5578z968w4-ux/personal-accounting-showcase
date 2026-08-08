<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/html.php';
require_once dirname(__DIR__) . '/src/form.php';
require_once dirname(__DIR__) . '/src/AiLedgerTraceDisplayService.php';
require_once dirname(__DIR__) . '/src/MonthlyOvertimeListService.php';

require_login();

$pdo = app_db();
$message = '';
$error = '';
$selectedMonth = normalize_month((string) ($_GET['month'] ?? date('Y/m')));
if ($selectedMonth === '') {
    $selectedMonth = date('Y/m');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post_string('action');
        $id = post_int('id');

        if ($action === 'delete' && $id > 0) {
            $statement = $pdo->prepare('UPDATE overtime_logs SET is_deleted = 1, deleted_at = NOW() WHERE id = :id');
            $statement->execute(['id' => $id]);
            $message = '加班紀錄已刪除';
        } elseif ($action === 'save') {
            $workDate = post_string('work_date');
            $overtimeHours = post_string('overtime_hours');
            $userName = post_string('user_name');
            $rawInput = post_string('raw_input');

            if (!valid_date_string($workDate) || !valid_hours_string($overtimeHours)) {
                throw new InvalidArgumentException('invalid');
            }

            if ($id > 0) {
                $conflictStatement = $pdo->prepare(
                    'SELECT id FROM overtime_logs WHERE work_date = :work_date AND id <> :id AND is_deleted = 0 LIMIT 1'
                );
                $conflictStatement->execute(['work_date' => $workDate, 'id' => $id]);
                if ($conflictStatement->fetchColumn()) {
                    throw new RuntimeException('overtime_date_conflict');
                }

                $statement = $pdo->prepare(
                    'UPDATE overtime_logs
                     SET work_date = :work_date, overtime_hours = :overtime_hours,
                         raw_input = :raw_input, note = :note, user_name = :user_name,
                         is_deleted = 0, deleted_at = NULL
                     WHERE id = :id'
                );
                $statement->execute([
                    'id' => $id,
                    'work_date' => $workDate,
                    'overtime_hours' => $overtimeHours,
                    'raw_input' => $rawInput,
                    'note' => $rawInput,
                    'user_name' => $userName,
                ]);
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO overtime_logs (work_date, overtime_hours, raw_input, note, user_name, is_deleted, deleted_at)
                     VALUES (:work_date, :overtime_hours, :raw_input, :note, :user_name, 0, NULL)
                     ON DUPLICATE KEY UPDATE
                        overtime_hours = VALUES(overtime_hours),
                        raw_input = VALUES(raw_input),
                        note = VALUES(note),
                        user_name = VALUES(user_name),
                        is_deleted = 0,
                        deleted_at = NULL'
                );
                $statement->execute([
                    'work_date' => $workDate,
                    'overtime_hours' => $overtimeHours,
                    'raw_input' => $rawInput,
                    'note' => $rawInput,
                    'user_name' => $userName,
                ]);
            }
            $message = '加班紀錄已儲存';
        }
    }
} catch (RuntimeException $exception) {
    $error = $exception->getMessage() === 'overtime_date_conflict'
        ? '該日期已存在其他加班紀錄，請先修改原紀錄。'
        : safe_error_message();
} catch (Throwable) {
    $error = safe_error_message();
}
$overtimeService = new MonthlyOvertimeListService($pdo);
$monthOptions = $overtimeService->availableMonths(date('Y/m'), $selectedMonth);
$rows = $overtimeService->listByMonth($selectedMonth);
$traceLinks = (new AiLedgerTraceDisplayService($pdo))->latestLinksByLedgerRows('overtime_logs', array_column($rows, 'id'));
$monthlyOvertimeHours = $overtimeService->formatHours($overtimeService->totalHoursByMonth($selectedMonth));

function overtime_source_label(?string $source): string
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
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>加班管理</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="page">
        <div class="page-header">
            <div>
                <h1>加班管理</h1>
                <p>同一天只保留一筆加班紀錄，重複儲存會更新既有資料。</p>
            </div>
            <nav class="nav">
                <a href="/dashboard.php">主控台</a>
                <a href="/expenses.php">支出</a>
                <a href="/incomes.php">收入</a>
                <a href="/leave.php">請假</a>
            </nav>
        </div>

        <?php if ($message !== ''): ?><p class="success"><?= h($message) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

        <details class="form-panel create-panel">
            <summary>新增 / 更新加班</summary>
            <form class="grid-form" method="post">
                <input type="hidden" name="action" value="save">
                <label>日期<input type="date" name="work_date" value="<?= h(date('Y-m-d')) ?>" required></label>
                <label>加班時數
                    <input name="overtime_hours" list="overtime-hour-options" inputmode="decimal" value="2" required>
                    <datalist id="overtime-hour-options">
                        <option value="2">
                        <option value="3">
                        <option value="1">
                        <option value="4">
                    </datalist>
                </label>
                <label>記帳人<input name="user_name"></label>
                <label class="wide">備註 / 原始輸入<textarea name="raw_input"></textarea></label>
                <button type="submit">儲存</button>
            </form>
        </details>

        <section class="table-panel record-panel">
            <div class="section-title-row">
                <h2><?= h($selectedMonth) ?> 加班清單</h2>
                <form class="inline-filter-form" method="get" action="/overtime.php">
                    <label>
                        月份
                        <select name="month" onchange="this.form.submit()">
                            <?php foreach ($monthOptions as $monthOption): ?>
                                <option value="<?= h($overtimeService->monthQueryValue($monthOption)) ?>" <?= $monthOption === $selectedMonth ? 'selected' : '' ?>>
                                    <?= h($monthOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            </div>
            <div class="record-list overtime-list">
                <?php if ($rows === []): ?>
                    <article class="record-card empty-state"><p class="muted">本月尚無加班紀錄</p></article>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <article class="record-card ledger-card overtime-card">
                        <div class="record-main">
                            <div class="record-title">
                                <span><?= h($row['display_line']) ?></span>
                            </div>
                        </div>
                        <?php if ($row["raw_input"] !== ""): ?>
                            <p class="record-subline"><?= h($row["raw_input"]) ?></p>
                        <?php endif; ?>
                        <?php $traceLink = $traceLinks[(int) $row["id"]] ?? null; ?>
                        <details class="record-details">
                            <summary>詳細 / 操作</summary>
                            <div class="record-meta trace-meta">
                                <span class="source-chip">來源：<?= h(overtime_source_label($row["source"] ?? "")) ?></span>
                                <?php if (is_array($traceLink)): ?>
                                    <span class="trace-chip">AI：Log #<?= h((string) $traceLink["ai_parse_log_id"]) ?></span>
                                    <span class="trace-chip">Trace：<?= h(AiLedgerTraceDisplayService::actionLabel($traceLink["action"])) ?></span>
                                    <span class="debug-chip">輸入：<?= h(AiLedgerTraceDisplayService::textOrDash($traceLink["raw_input_snapshot"] ?? null)) ?></span>
                                    <span>連結時間：<?= h($traceLink["created_at"] ?? "-") ?></span>
                                    <a href="/ai_trace_detail.php?ledger_table=overtime_logs&amp;ledger_id=<?= h((string) $row["id"]) ?>">Trace 詳細</a>
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
                                        <label>日期<input type="date" name="work_date" value="<?= h($row["work_date"]) ?>" required></label>
                                        <label>加班時數<input name="overtime_hours" inputmode="decimal" value="<?= h(format_number_clean($row["overtime_hours"])) ?>" required></label>
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
            <div class="section-title-row">
                <span class="muted"><?= h($selectedMonth) ?> 加班合計時數：<?= h($monthlyOvertimeHours) ?> 小時</span>
            </div>
        </section>
    </main>
    <?php render_mobile_nav('overtime'); ?>
</body>
</html>
