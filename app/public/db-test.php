<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: /login.php');
    exit;
}

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/DemoMode.php';

DemoMode::guardPublicEndpoint('資料庫診斷頁');

$status = 'fail';
$message = '';
$serverVersion = '';
$databaseName = '';
$lastCheck = '';

try {
    $pdo = app_db();
    $serverVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $statement = $pdo->prepare('INSERT INTO system_checks (check_name) VALUES (:check_name)');
    $statement->execute(['check_name' => 'manual_db_test']);
    $lastCheck = (string) $pdo->query('SELECT MAX(checked_at) FROM system_checks')->fetchColumn();
    $status = 'ok';
    $message = '資料庫連線成功';
} catch (Throwable $exception) {
    $message = '資料庫連線失敗，請檢查 .env 與容器狀態。';
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>資料庫連線測試</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="shell">
        <section class="panel">
            <div class="topline">
                <h1>資料庫連線測試</h1>
                <a class="link" href="/">返回</a>
            </div>
            <p class="<?= $status === 'ok' ? 'success' : 'error' ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
            <?php if ($status === 'ok'): ?>
                <dl>
                    <dt>Database</dt>
                    <dd><?= htmlspecialchars($databaseName, ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt>MariaDB Version</dt>
                    <dd><?= htmlspecialchars($serverVersion, ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt>Last Check</dt>
                    <dd><?= htmlspecialchars($lastCheck, ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
