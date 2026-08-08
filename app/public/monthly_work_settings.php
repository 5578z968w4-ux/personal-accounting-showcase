<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/html.php';

require_login();

$pdo = app_db();
$message = '';
$error = '';

function normalize_work_month(string $value): string
{
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
        return str_replace('-', '/', $value);
    }
    if (preg_match('/^\d{4}\/\d{2}$/', $value) === 1) {
        return $value;
    }
    return '';
}

function html_month_value(string $workMonth): string
{
    return str_replace('/', '-', $workMonth);
}

$selectedMonth = normalize_work_month((string) ($_GET['work_month'] ?? date('Y/m')));

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $workMonth = normalize_work_month(post_string('work_month'));
        $expectedWorkDays = post_string('expected_work_days');
        $note = post_string('note');

        if ($workMonth === '' || preg_match('/^\d{1,3}(\.\d{1,2})?$/', $expectedWorkDays) !== 1) {
            throw new InvalidArgumentException('invalid');
        }

        $statement = $pdo->prepare(
            'INSERT INTO monthly_work_settings (work_month, expected_work_days, note)
             VALUES (:work_month, :expected_work_days, :note)
             ON DUPLICATE KEY UPDATE expected_work_days = VALUES(expected_work_days), note = VALUES(note)'
        );
        $statement->execute([
            'work_month' => $workMonth,
            'expected_work_days' => $expectedWorkDays,
            'note' => $note,
        ]);

        $selectedMonth = $workMonth;
        $message = '每月工作天設定已儲存';
    }
} catch (Throwable) {
    $error = safe_error_message();
}

$defaultStatement = $pdo->prepare(
    'SELECT numeric_value FROM settings WHERE setting_key = :setting_key AND is_active = 1 LIMIT 1'
);
$defaultStatement->execute(['setting_key' => 'default_work_days']);
$defaultWorkDays = (string) ($defaultStatement->fetchColumn() ?: '0.00');

$currentStatement = $pdo->prepare(
    'SELECT id, work_month, expected_work_days, note, updated_at
     FROM monthly_work_settings
     WHERE work_month = :work_month
     LIMIT 1'
);
$currentStatement->execute(['work_month' => $selectedMonth]);
$current = $currentStatement->fetch();

$rows = $pdo->query(
    'SELECT id, work_month, expected_work_days, note, updated_at
     FROM monthly_work_settings
     ORDER BY work_month DESC'
)->fetchAll();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>每月工作天設定</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="page">
        <div class="page-header">
            <div>
                <h1>每月工作天</h1>
                <p>每個月實際應工作天可單獨設定；未設定月份只參考預設應工作天。</p>
            </div>
            <nav class="nav">
                <a href="/dashboard.php">主控台</a>
                <a href="/settings.php">系統設定</a>
                <a href="/salary_detail.php">薪資明細</a>
            </nav>
        </div>

        <?php if ($message !== ''): ?><p class="success"><?= h($message) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

        <section class="form-panel">
            <h2>選擇月份</h2>
            <form class="grid-form" method="get">
                <label>月份<input type="month" name="work_month" value="<?= h(html_month_value($selectedMonth)) ?>" required></label>
                <button type="submit">查詢</button>
            </form>
        </section>

        <section class="form-panel">
            <h2><?= $current ? '修改該月應工作天' : '新增該月應工作天' ?></h2>
            <p>預設應工作天參考值：<?= h(format_number_clean($defaultWorkDays)) ?> 天。此頁儲存後只更新該月份，不會覆蓋系統設定中的預設值。</p>
            <form class="grid-form" method="post">
                <label>月份<input type="month" name="work_month" value="<?= h(html_month_value($selectedMonth)) ?>" required></label>
                <label>該月應工作天
                    <input name="expected_work_days" inputmode="decimal" value="<?= h(format_number_clean($current['expected_work_days'] ?? $defaultWorkDays)) ?>" required>
                </label>
                <label class="wide">備註<textarea name="note"><?= h($current['note'] ?? '') ?></textarea></label>
                <button type="submit">儲存該月設定</button>
            </form>
        </section>

        <section class="table-panel">
            <h2>已設定月份</h2>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>月份</th>
                            <th>應工作天</th>
                            <th>備註</th>
                            <th>更新時間</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= h($row['work_month']) ?></td>
                            <td><?= h(format_number_clean($row['expected_work_days'])) ?></td>
                            <td><?= h($row['note']) ?></td>
                            <td><?= h($row['updated_at']) ?></td>
                            <td><a class="link" href="/monthly_work_settings.php?work_month=<?= h(html_month_value($row['work_month'])) ?>">編輯</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <?php render_mobile_nav('work'); ?>
</body>
</html>
