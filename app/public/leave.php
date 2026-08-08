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

$leaveTypes = $pdo->query(
    'SELECT name, is_active FROM leave_types ORDER BY is_active DESC, sort_order, id'
)->fetchAll();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post_string('action');
        $id = post_int('id');

        if ($action === 'delete' && $id > 0) {
            $statement = $pdo->prepare('UPDATE leave_logs SET is_deleted = 1, deleted_at = NOW() WHERE id = :id');
            $statement->execute(['id' => $id]);
            $message = '請假紀錄已刪除';
        } elseif ($action === 'save') {
            $leaveDate = post_string('leave_date');
            $leaveType = post_string('leave_type');
            $leaveDays = post_string('leave_days', '0.00');
            $leaveHours = post_string('leave_hours', '0.00');
            $note = post_string('note');

            if (!valid_date_string($leaveDate) || $leaveType === '' || !valid_hours_string($leaveDays) || !valid_hours_string($leaveHours)) {
                throw new InvalidArgumentException('invalid');
            }

            if ($id > 0) {
                $conflictStatement = $pdo->prepare(
                    'SELECT id FROM leave_logs WHERE leave_date = :leave_date AND id <> :id AND is_deleted = 0 LIMIT 1'
                );
                $conflictStatement->execute(['leave_date' => $leaveDate, 'id' => $id]);
                if ($conflictStatement->fetchColumn()) {
                    throw new RuntimeException('leave_date_conflict');
                }

                $statement = $pdo->prepare(
                    'UPDATE leave_logs
                     SET leave_date = :leave_date, leave_type = :leave_type, leave_days = :leave_days,
                         leave_hours = :leave_hours, note = :note, is_deleted = 0, deleted_at = NULL
                     WHERE id = :id'
                );
                $statement->execute([
                    'id' => $id,
                    'leave_date' => $leaveDate,
                    'leave_type' => $leaveType,
                    'leave_days' => $leaveDays,
                    'leave_hours' => $leaveHours,
                    'note' => $note,
                ]);
            } else {
                $existingStatement = $pdo->prepare(
                    'SELECT id FROM leave_logs WHERE leave_date = :leave_date AND is_deleted = 0 ORDER BY id LIMIT 1'
                );
                $existingStatement->execute(['leave_date' => $leaveDate]);
                $existingId = $existingStatement->fetchColumn();

                if ($existingId) {
                    $statement = $pdo->prepare(
                        'UPDATE leave_logs
                         SET leave_type = :leave_type, leave_days = :leave_days, leave_hours = :leave_hours,
                             note = :note, is_deleted = 0, deleted_at = NULL
                         WHERE id = :id'
                    );
                    $statement->execute([
                        'id' => $existingId,
                        'leave_type' => $leaveType,
                        'leave_days' => $leaveDays,
                        'leave_hours' => $leaveHours,
                        'note' => $note,
                    ]);
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO leave_logs (leave_date, leave_type, leave_days, leave_hours, note)
                         VALUES (:leave_date, :leave_type, :leave_days, :leave_hours, :note)'
                    );
                    $statement->execute([
                        'leave_date' => $leaveDate,
                        'leave_type' => $leaveType,
                        'leave_days' => $leaveDays,
                        'leave_hours' => $leaveHours,
                        'note' => $note,
                    ]);
                }
            }
            $message = '請假紀錄已儲存';
        }
    }
} catch (RuntimeException $exception) {
    $error = $exception->getMessage() === 'leave_date_conflict'
        ? '該日期已存在其他請假紀錄，請先修改原紀錄。'
        : safe_error_message();
} catch (Throwable) {
    $error = safe_error_message();
}

$rows = $pdo->query(
    'SELECT id, leave_date, leave_type, leave_days, leave_hours, total_leave_days, note, source
     FROM leave_logs
     WHERE is_deleted = 0
     ORDER BY leave_date DESC, id DESC
     LIMIT 100'
)->fetchAll();
$traceLinks = (new AiLedgerTraceDisplayService($pdo))->latestLinksByLedgerRows('leave_logs', array_column($rows, 'id'));

function leave_source_label(?string $source): string
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

function render_leave_type_options(array $leaveTypes, string $selected = ''): void
{
    $selectedFound = false;
    foreach ($leaveTypes as $type) {
        if ((int) $type['is_active'] !== 1 && $type['name'] !== $selected) {
            continue;
        }
        $isSelected = $type['name'] === $selected;
        $selectedFound = $selectedFound || $isSelected;
        ?>
        <option value="<?= h($type['name']) ?>" <?= $isSelected ? 'selected' : '' ?>>
            <?= h($type['name']) ?><?= (int) $type['is_active'] === 0 ? '（停用）' : '' ?>
        </option>
        <?php
    }

    if ($selected !== '' && !$selectedFound) {
        ?>
        <option value="<?= h($selected) ?>" selected><?= h($selected) ?>（既有）</option>
        <?php
    }
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>請假管理</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="page">
        <div class="page-header">
            <div>
                <h1>請假管理</h1>
                <p>同一天只保留一筆請假紀錄，再次輸入會更新既有資料。</p>
            </div>
            <nav class="nav">
                <a href="/dashboard.php">主控台</a>
                <a href="/expenses.php">支出</a>
                <a href="/incomes.php">收入</a>
                <a href="/overtime.php">加班</a>
            </nav>
        </div>

        <?php if ($message !== ''): ?><p class="success"><?= h($message) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

        <details class="form-panel create-panel">
            <summary>新增 / 更新請假</summary>
            <form class="grid-form" method="post">
                <input type="hidden" name="action" value="save">
                <label>日期<input type="date" name="leave_date" value="<?= h(date('Y-m-d')) ?>" required></label>
                <label>假別
                    <select name="leave_type" required>
                        <option value="">請選擇</option>
                        <?php render_leave_type_options($leaveTypes, '特休'); ?>
                    </select>
                </label>
                <label>天<input name="leave_days" inputmode="decimal" value="0" required></label>
                <label>小時<input name="leave_hours" inputmode="decimal" value="0" required></label>
                <label class="wide">備註<textarea name="note"></textarea></label>
                <button type="submit">儲存</button>
            </form>
        </details>

        <section class="table-panel record-panel">
            <div class="section-title-row">
                <h2>請假清單</h2>
                <span class="muted">最近 100 筆</span>
            </div>
            <div class="record-list leave-list">
                <?php if ($rows === []): ?>
                    <article class="record-card empty-state"><p class="muted">目前沒有請假紀錄。</p></article>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <article class="record-card ledger-card leave-card">
                        <div class="record-main">
                            <div class="record-title">
                                <span>日期：<?= h($row["leave_date"]) ?></span>
                                <strong><?= h($row["leave_type"]) ?></strong>
                            </div>
                            <div class="record-amount neutral-amount">
                                <?= h(format_number_clean($row["leave_days"])) ?>天 <?= h(format_number_clean($row["leave_hours"])) ?>H
                            </div>
                        </div>
                        <?php if ($row["note"] !== ""): ?>
                            <p class="record-subline"><?= h($row["note"]) ?></p>
                        <?php endif; ?>
                        <?php $traceLink = $traceLinks[(int) $row["id"]] ?? null; ?>
                        <details class="record-details">
                            <summary>詳細 / 操作</summary>
                            <div class="record-meta trace-meta">
                                <span class="source-chip">來源：<?= h(leave_source_label($row["source"] ?? "")) ?></span>
                                <?php if (is_array($traceLink)): ?>
                                    <span class="trace-chip">AI：Log #<?= h((string) $traceLink["ai_parse_log_id"]) ?></span>
                                    <span class="trace-chip">Trace：<?= h(AiLedgerTraceDisplayService::actionLabel($traceLink["action"])) ?></span>
                                    <span class="debug-chip">輸入：<?= h(AiLedgerTraceDisplayService::textOrDash($traceLink["raw_input_snapshot"] ?? null)) ?></span>
                                    <span>連結時間：<?= h($traceLink["created_at"] ?? "-") ?></span>
                                    <a href="/ai_trace_detail.php?ledger_table=leave_logs&amp;ledger_id=<?= h((string) $row["id"]) ?>">Trace 詳細</a>
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
                                        <label>日期<input type="date" name="leave_date" value="<?= h($row["leave_date"]) ?>" required></label>
                                        <label>假別
                                            <select name="leave_type" required>
                                                <?php render_leave_type_options($leaveTypes, (string) $row["leave_type"]); ?>
                                            </select>
                                        </label>
                                        <label>天<input name="leave_days" inputmode="decimal" value="<?= h(format_number_clean($row["leave_days"])) ?>" required></label>
                                        <label>小時<input name="leave_hours" inputmode="decimal" value="<?= h(format_number_clean($row["leave_hours"])) ?>" required></label>
                                        <label class="wide">備註<textarea name="note"><?= h($row["note"]) ?></textarea></label>
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
        </section>
    </main>
    <?php render_mobile_nav('leave'); ?>
</body>
</html>
