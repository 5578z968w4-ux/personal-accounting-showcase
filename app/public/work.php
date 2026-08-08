<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/src/auth.php";
require_once dirname(__DIR__) . "/src/html.php";

require_login();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>工作</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="page">
        <div class="page-header">
            <div>
                <h1>工作</h1>
                <p>薪資、加班、請假與每月工作天集中在這裡。</p>
            </div>
            <nav class="nav">
                <a href="/dashboard.php">主控台</a>
                <a href="/finance.php">收支</a>
                <a href="/settings.php">設定</a>
            </nav>
        </div>

        <section class="menu-grid">
            <a class="menu-card" href="/salary_detail.php"><strong>薪資明細</strong><span>底薪、全勤、加班與扣款試算</span></a>
            <a class="menu-card" href="/overtime.php"><strong>加班</strong><span>新增與管理加班紀錄</span></a>
            <a class="menu-card" href="/leave.php"><strong>請假</strong><span>新增與管理請假紀錄</span></a>
            <a class="menu-card" href="/monthly_work_settings.php"><strong>每月工作天</strong><span>設定每月應工作天</span></a>
            <a class="menu-card" href="/settings.php"><strong>薪資設定</strong><span>底薪、津貼、扣款等參數</span></a>
        </section>
    </main>
    <?php render_mobile_nav("work"); ?>
</body>
</html>
