<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/html.php';

require_login();

$pdo = app_db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_string('action');

    try {
        if ($action === 'save') {
            $id = post_int('id');
            $name = post_string('name');
            $startDay = post_int('settlement_start_day');
            $endDay = post_int('settlement_end_day');
            $sortOrder = post_int('sort_order');
            $note = post_string('note');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($name === '' || $startDay < 1 || $startDay > 31 || $endDay < 1 || $endDay > 31) {
                throw new InvalidArgumentException('invalid');
            }

            if ($id > 0) {
                $statement = $pdo->prepare(
                    'UPDATE payment_methods
                     SET name = :name,
                         settlement_start_day = :settlement_start_day,
                         settlement_end_day = :settlement_end_day,
                         cycle_start_day = :cycle_start_day,
                         cycle_end_day = :cycle_end_day,
                         sort_order = :sort_order, note = :note, is_active = :is_active
                     WHERE id = :id'
                );
                $statement->execute([
                    'id' => $id,
                    'name' => $name,
                    'settlement_start_day' => $startDay,
                    'settlement_end_day' => $endDay,
                    'cycle_start_day' => $startDay,
                    'cycle_end_day' => $endDay,
                    'sort_order' => $sortOrder,
                    'note' => $note,
                    'is_active' => $isActive,
                ]);
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO payment_methods
                        (name, settlement_start_day, settlement_end_day, cycle_start_day, cycle_end_day, sort_order, note, is_active)
                     VALUES
                        (:name, :settlement_start_day, :settlement_end_day, :cycle_start_day, :cycle_end_day, :sort_order, :note, :is_active)'
                );
                $statement->execute([
                    'name' => $name,
                    'settlement_start_day' => $startDay,
                    'settlement_end_day' => $endDay,
                    'cycle_start_day' => $startDay,
                    'cycle_end_day' => $endDay,
                    'sort_order' => $sortOrder,
                    'note' => $note,
                    'is_active' => $isActive,
                ]);
            }

            $message = '付款方式已儲存';
        } elseif ($action === 'disable') {
            $statement = $pdo->prepare('UPDATE payment_methods SET is_active = 0 WHERE id = :id');
            $statement->execute(['id' => post_int('id')]);
            $message = '付款方式已停用';
        } elseif ($action === 'delete') {
            $id = post_int('id');
            $usageStatement = $pdo->prepare('SELECT COUNT(*) FROM expenses WHERE payment_method_id = :id AND is_deleted = 0');
            $usageStatement->execute(['id' => $id]);
            if ((int) $usageStatement->fetchColumn() > 0) {
                throw new RuntimeException('payment_method_in_use');
            }
            $statement = $pdo->prepare('DELETE FROM payment_methods WHERE id = :id');
            $statement->execute(['id' => $id]);
            $message = '付款方式已刪除';
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage() === 'payment_method_in_use'
            ? '已有支出紀錄使用此付款方式，請改用停用。'
            : safe_error_message();
    } catch (Throwable) {
        $error = safe_error_message();
    }
}

$paymentMethods = $pdo->query(
    'SELECT id, name, settlement_start_day, settlement_end_day, is_active, sort_order, note, updated_at
     FROM payment_methods
     ORDER BY sort_order, id'
)->fetchAll();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>付款方式設定</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="page">
        <div class="page-header">
            <div>
                <h1>付款方式</h1>
                <p>結算日由資料庫設定，帳單月份計算不得寫死。</p>
            </div>
            <nav class="nav">
                <a href="/dashboard.php">主控台</a>
                <a href="/settings.php">系統設定</a>
                <a href="/expenses.php">支出</a>
            </nav>
        </div>

        <?php if ($message !== ''): ?><p class="success"><?= h($message) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

        <section class="form-panel">
            <h2>新增付款方式</h2>
            <form class="grid-form" method="post">
                <input type="hidden" name="action" value="save">
                <label>名稱<input name="name" required></label>
                <label>結算起始日<input name="settlement_start_day" type="number" min="1" max="31" required></label>
                <label>結算結束日<input name="settlement_end_day" type="number" min="1" max="31" required></label>
                <label class="check"><input name="is_active" type="checkbox" checked> 啟用</label>
                <label>排序<input name="sort_order" type="number" value="0"></label>
                <label class="wide">備註<textarea name="note"></textarea></label>
                <button type="submit">新增</button>
            </form>
        </section>

        <section class="table-panel record-panel">
            <div class="section-title-row">
                <h2>付款方式清單</h2>
                <span class="muted">點編輯展開設定</span>
            </div>
            <div class="record-list setting-list">
                <?php foreach ($paymentMethods as $row): ?>
                    <article class="record-card">
                        <div class="record-main">
                            <div class="record-title">
                                <strong><?= h($row['name']) ?></strong>
                                <span><?= (int) $row['is_active'] === 1 ? '啟用' : '停用' ?> · 排序 <?= h(format_number_clean($row['sort_order'])) ?></span>
                            </div>
                            <div class="record-amount neutral-amount"><?= h(format_number_clean($row['settlement_start_day'])) ?>～<?= h(format_number_clean($row['settlement_end_day'])) ?></div>
                        </div>
                        <div class="record-meta">
                            <span>備註：<?= h($row['note'] !== '' ? $row['note'] : '-') ?></span>
                        </div>
                        <details class="record-edit">
                            <summary>編輯</summary>
                            <form class="grid-form edit-form" method="post">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="id" value="<?= h((string) $row['id']) ?>">
                                <label>名稱<input name="name" value="<?= h($row['name']) ?>" required></label>
                                <label>結算起始日<input name="settlement_start_day" type="number" min="1" max="31" value="<?= h(format_number_clean($row['settlement_start_day'])) ?>" required></label>
                                <label>結算結束日<input name="settlement_end_day" type="number" min="1" max="31" value="<?= h(format_number_clean($row['settlement_end_day'])) ?>" required></label>
                                <label class="check"><input name="is_active" type="checkbox" <?= (int) $row['is_active'] === 1 ? 'checked' : '' ?>> 啟用</label>
                                <label>排序<input name="sort_order" type="number" value="<?= h(format_number_clean($row['sort_order'])) ?>"></label>
                                <label class="wide">備註<textarea name="note"><?= h($row['note']) ?></textarea></label>
                                <button type="submit">儲存</button>
                            </form>
                        </details>
                        <div class="record-inline-actions">
                            <form method="post">
                                <input type="hidden" name="action" value="disable">
                                <input type="hidden" name="id" value="<?= h((string) $row['id']) ?>">
                                <button type="submit" class="secondary text-button">停用</button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= h((string) $row['id']) ?>">
                                <button type="submit" class="secondary text-button">刪除</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
    <?php render_mobile_nav('finance'); ?>
</body>
</html>
