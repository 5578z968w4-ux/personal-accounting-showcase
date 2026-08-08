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
    <title>收支</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="page">
        <div class="page-header">
            <div>
                <h1>收支</h1>
                <p>收入、支出與帳戶付款設定集中在這裡。</p>
            </div>
            <nav class="nav">
                <a href="/dashboard.php">主控台</a>
                <a href="/work.php">工作</a>
                <a href="/settings.php">設定</a>
            </nav>
        </div>

        <section class="menu-grid">
            <a class="menu-card featured" href="/ai_entry.php"><strong>AI 快速輸入</strong><span>自然語言輸入與解析確認</span></a>
            <a class="menu-card" href="/analytics.php"><strong>支出總覽</strong><span>查看統計與篩選明細，並前往新增或編輯</span></a>
            <a class="menu-card" href="/incomes.php"><strong>收入</strong><span>新增與管理收入紀錄</span></a>
            <a class="menu-card" href="/payment_methods.php"><strong>付款方式</strong><span>信用卡、現金與結算日設定</span></a>
            <a class="menu-card" href="/accounts.php"><strong>帳戶</strong><span>收入帳戶與排序設定</span></a>
            <a class="menu-card" href="/ai_settings.php"><strong>AI 模型設定</strong><span>Provider、模型與解析權限</span></a>
            <a class="menu-card" href="/ai_parse_logs.php"><strong>AI 記帳紀錄</strong><span>查看解析、來源與 Trace</span></a>
        </section>
    </main>
    <?php render_mobile_nav("finance"); ?>
</body>
</html>
