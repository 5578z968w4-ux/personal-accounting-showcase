<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/html.php';

require_login();

$pdo = app_db();
$message = '';
$error = '';

function valid_setting_key(string $settingKey): bool
{
    return preg_match('/^[a-z0-9_]{2,80}$/', $settingKey) === 1;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post_string('action');

        if ($action === 'create') {
            $settingKey = post_string('setting_key');
            $label = post_string('label');
            $numericValue = post_decimal('numeric_value');
            $unit = post_string('unit');
            $sortOrder = post_int('sort_order');
            $note = post_string('note');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (!valid_setting_key($settingKey) || $label === '') {
                throw new InvalidArgumentException('invalid');
            }

            $statement = $pdo->prepare(
                'INSERT INTO settings (setting_key, label, numeric_value, unit, sort_order, note, is_active)
                 VALUES (:setting_key, :label, :numeric_value, :unit, :sort_order, :note, :is_active)'
            );
            $statement->execute([
                'setting_key' => $settingKey,
                'label' => $label,
                'numeric_value' => $numericValue,
                'unit' => $unit,
                'sort_order' => $sortOrder,
                'note' => $note,
                'is_active' => $isActive,
            ]);
            $message = '設定已新增';
        } elseif ($action === 'save_all') {
            $items = $_POST['settings'] ?? [];
            if (!is_array($items)) {
                throw new InvalidArgumentException('invalid');
            }

            $statement = $pdo->prepare(
                'UPDATE settings
                 SET setting_key = :setting_key, label = :label, numeric_value = :numeric_value, unit = :unit,
                     sort_order = :sort_order, note = :note, is_active = :is_active
                 WHERE id = :id'
            );

            $pdo->beginTransaction();
            foreach ($items as $id => $item) {
                if (!is_array($item)) {
                    throw new InvalidArgumentException('invalid');
                }

                $rowId = filter_var($id, FILTER_VALIDATE_INT);
                $settingKey = trim((string) ($item['setting_key'] ?? ''));
                $label = trim((string) ($item['label'] ?? ''));
                $numericValue = trim((string) ($item['numeric_value'] ?? '0.00'));
                $unit = trim((string) ($item['unit'] ?? ''));
                $sortOrder = filter_var($item['sort_order'] ?? 0, FILTER_VALIDATE_INT);
                $note = trim((string) ($item['note'] ?? ''));
                $isActive = isset($item['is_active']) ? 1 : 0;

                if ($rowId === false || $rowId < 1 || !valid_setting_key($settingKey) || $label === ''
                    || preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', $numericValue) !== 1) {
                    throw new InvalidArgumentException('invalid');
                }

                $statement->execute([
                    'id' => $rowId,
                    'setting_key' => $settingKey,
                    'label' => $label,
                    'numeric_value' => $numericValue,
                    'unit' => $unit,
                    'sort_order' => $sortOrder === false ? 0 : $sortOrder,
                    'note' => $note,
                    'is_active' => $isActive,
                ]);
            }
            $pdo->commit();
            $message = '全部設定已儲存';
        }
    }
} catch (Throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $error = safe_error_message();
}

$settings = $pdo->query(
    'SELECT id, setting_key, label, numeric_value, unit, note, is_active, sort_order, updated_at
     FROM settings
     ORDER BY sort_order, id'
)->fetchAll();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>系統設定</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="page">
        <div class="page-header">
            <div>
                <h1>系統設定</h1>
                <p>付款、帳戶、工作天、AI 模型與薪資參數集中在這裡。</p>
            </div>
            <nav class="nav">
                <a href="/dashboard.php">主控台</a>
                <a href="/expenses.php">支出</a>
                <a href="/overtime.php">加班</a>
                <a href="/ai_parse_logs.php">AI 紀錄</a>
            </nav>
        </div>

        <?php if ($message !== ''): ?><p class="success"><?= h($message) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

        <section class="menu-grid settings-hub">
            <a class="menu-card" href="/payment_methods.php"><strong>付款方式</strong><span>結算日、信用卡與現金設定</span></a>
            <a class="menu-card" href="/accounts.php"><strong>帳戶</strong><span>收入入帳帳戶與排序</span></a>
            <a class="menu-card" href="/monthly_work_settings.php"><strong>每月工作天</strong><span>每月應工作天與備註</span></a>
            <a class="menu-card" href="/ai_settings.php"><strong>AI 模型設定</strong><span>Provider、模型與解析類型</span></a>
            <a class="menu-card" href="/db-test.php"><strong>系統檢查</strong><span>資料庫連線與環境狀態</span></a>
            <a class="menu-card" href="/salary_detail.php"><strong>薪資明細</strong><span>查看目前薪資試算</span></a>
        </section>

        <section class="form-panel">
            <h2>新增薪資參數</h2>
            <form class="grid-form" method="post">
                <input type="hidden" name="action" value="create">
                <label>設定代碼<input name="setting_key" pattern="[a-z0-9_]{2,80}" required></label>
                <label>名稱<input name="label" required></label>
                <label>數值<input name="numeric_value" inputmode="decimal" value="0" required></label>
                <label>單位<input name="unit"></label>
                <label>排序<input name="sort_order" type="number" value="0"></label>
                <label class="check"><input name="is_active" type="checkbox" checked> 啟用</label>
                <label class="wide">備註<textarea name="note"></textarea></label>
                <button type="submit">新增</button>
            </form>
        </section>

        <section class="table-panel">
            <h2>薪資核心參數</h2>
            <p class="muted">預設應工作天只作為沒有設定月份時的預設值；每月應工作天請到上方入口維護。</p>
            <form method="post">
                <input type="hidden" name="action" value="save_all">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>代碼</th>
                                <th>名稱</th>
                                <th>數值</th>
                                <th>單位</th>
                                <th>排序</th>
                                <th>狀態</th>
                                <th>備註</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($settings as $row): ?>
                            <tr>
                                <td>
                                    <input name="settings[<?= h((string) $row['id']) ?>][setting_key]" value="<?= h($row['setting_key']) ?>" pattern="[a-z0-9_]{2,80}" required>
                                </td>
                                <td><input name="settings[<?= h((string) $row['id']) ?>][label]" value="<?= h($row['label']) ?>" required></td>
                                <td><input name="settings[<?= h((string) $row['id']) ?>][numeric_value]" inputmode="decimal" value="<?= h((string) $row['numeric_value']) ?>" required></td>
                                <td><input name="settings[<?= h((string) $row['id']) ?>][unit]" value="<?= h($row['unit']) ?>"></td>
                                <td><input name="settings[<?= h((string) $row['id']) ?>][sort_order]" type="number" value="<?= h((string) $row['sort_order']) ?>"></td>
                                <td><label class="check"><input name="settings[<?= h((string) $row['id']) ?>][is_active]" type="checkbox" <?= (int) $row['is_active'] === 1 ? 'checked' : '' ?>> 啟用</label></td>
                                <td><textarea name="settings[<?= h((string) $row['id']) ?>][note]" class="compact-note"><?= h($row['note']) ?></textarea></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="actions">
                    <button type="submit">儲存全部設定</button>
                    <a class="button secondary" href="/monthly_work_settings.php">設定每月工作天</a>
                </div>
            </form>
        </section>
    </main>
    <?php render_mobile_nav('work'); ?>
</body>
</html>
