<?php

declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/src/env.php';
require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/html.php';

$error = '';
$returnTo = trim((string) ($_POST['return_to'] ?? $_GET['return_to'] ?? ''));
if (!valid_login_return_to($returnTo)) {
    $returnTo = '/dashboard.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $expectedUsername = app_env('APP_LOGIN_USERNAME', 'admin');
    $expectedPassword = app_env('APP_LOGIN_PASSWORD', '');

    if (hash_equals((string) $expectedUsername, (string) $username)
        && hash_equals((string) $expectedPassword, (string) $password)) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        header('Location: ' . $returnTo);
        exit;
    }

    $error = '帳號或密碼錯誤';
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1f6f5b">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <title>登入 - <?= htmlspecialchars(app_env('APP_NAME', 'Personal Accounting'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body class="<?= DemoMode::isEnabled() ? 'demo-login-body' : '' ?>">
    <?php render_demo_banner(); ?>
    <main class="shell">
        <form class="panel login" method="post" action="/login.php">
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
            <div class="login-brand">
                <span>Personal Accounting</span>
                <h1>個人財務主控台</h1>
                <p>登入後管理收支、薪資、加班、請假與 AI 記帳紀錄。</p>
            </div>
            <?php if (DemoMode::isEnabled()): ?>
                <section class="demo-login-card" aria-labelledby="demo-login-title">
                    <strong id="demo-login-title">可操作的合成資料 Demo</strong>
                    <p>帳號 <code>demo</code>，密碼 <code>demo-local-only</code>。所有變更只會寫入獨立展示資料庫。</p>
                </section>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <label>
                帳號
                <input type="text" name="username" autocomplete="username" value="<?= DemoMode::isEnabled() ? 'demo' : '' ?>" required autofocus>
            </label>
            <label>
                密碼
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button type="submit">登入</button>
        </form>
    </main>
</body>
</html>
