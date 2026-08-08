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
            $sortOrder = post_int('sort_order');
            $note = post_string('note');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($name === '') {
                throw new InvalidArgumentException('invalid');
            }

            if ($id > 0) {
                $statement = $pdo->prepare(
                    'UPDATE accounts
                     SET name = :name, sort_order = :sort_order, note = :note, is_active = :is_active
                     WHERE id = :id'
                );
                $statement->execute([
                    'id' => $id,
                    'name' => $name,
                    'sort_order' => $sortOrder,
                    'note' => $note,
                    'is_active' => $isActive,
                ]);
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO accounts (name, sort_order, note, is_active)
                     VALUES (:name, :sort_order, :note, :is_active)'
                );
                $statement->execute([
                    'name' => $name,
                    'sort_order' => $sortOrder,
                    'note' => $note,
                    'is_active' => $isActive,
                ]);
            }

            $message = '帳戶已儲存';
        } elseif ($action === 'disable') {
            $statement = $pdo->prepare('UPDATE accounts SET is_active = 0 WHERE id = :id');
            $statement->execute(['id' => post_int('id')]);
            $message = '帳戶已停用';
        } elseif ($action === 'delete') {
            $id = post_int('id');
            $usageStatement = $pdo->prepare('SELECT COUNT(*) FROM incomes WHERE account_id = :id AND is_deleted = 0');
            $usageStatement->execute(['id' => $id]);
            if ((int) $usageStatement->fetchColumn() > 0) {
                throw new RuntimeException('account_in_use');
            }
            $statement = $pdo->prepare('DELETE FROM accounts WHERE id = :id');
            $statement->execute(['id' => $id]);
            $message = '帳戶已刪除';
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage() === 'account_in_use'
            ? '已有收入紀錄使用此帳戶，請改用停用。'
            : safe_error_message();
    } catch (Throwable) {
        $error = safe_error_message();
    }
}

$accounts = $pdo->query(
    'SELECT id, name, is_active, sort_order, note, updated_at
     FROM accounts
     ORDER BY sort_order, id'
)->fetchAll();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>帳戶設定</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="page">
        <div class="page-header">
            <div>
                <h1>帳戶</h1>
                <p>收入入帳帳戶由資料庫設定。</p>
            </div>
            <nav class="nav">
                <a href="/dashboard.php">主控台</a>
                <a href="/settings.php">系統設定</a>
                <a href="/incomes.php">收入</a>
            </nav>
        </div>

        <?php if ($message !== ''): ?><p class="success"><?= h($message) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

        <section class="form-panel">
            <h2>新增帳戶</h2>
            <form class="grid-form" method="post">
                <input type="hidden" name="action" value="save">
                <label>名稱<input name="name" required></label>
                <label class="check"><input name="is_active" type="checkbox" checked> 啟用</label>
                <label>排序<input name="sort_order" type="number" value="0"></label>
                <label class="wide">備註<textarea name="note"></textarea></label>
                <button type="submit">新增</button>
            </form>
        </section>

        <section class="table-panel record-panel">
            <div class="section-title-row">
                <h2>帳戶清單</h2>
                <span class="muted">點編輯展開設定</span>
            </div>
            <div class="record-list setting-list">
                <?php foreach ($accounts as $row): ?>
                    <article class="record-card">
                        <div class="record-main">
                            <div class="record-title">
                                <strong><?= h($row['name']) ?></strong>
                                <span><?= (int) $row['is_active'] === 1 ? '啟用' : '停用' ?> · 排序 <?= h(format_number_clean($row['sort_order'])) ?></span>
                            </div>
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
