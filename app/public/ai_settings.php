<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/html.php';
require_once dirname(__DIR__) . '/src/env.php';

require_login();

$pdo = app_db();
$message = '';
$error = '';
$demoMode = DemoMode::isEnabled();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($demoMode) {
            throw new LogicException('展示模式已鎖定 AI 設定，不會呼叫外部服務。');
        }

        $provider = post_string('provider');
        $modelName = post_string('model_name');
        $temperature = post_string('temperature', '0.10');
        $maxTokens = post_int('max_tokens', 1000);

        if (preg_match('/^[a-z0-9_-]{2,40}$/', $provider) !== 1
            || strlen($modelName) > 120
            || preg_match('/^(0(\.\d{1,2})?|1(\.0{1,2})?)$/', $temperature) !== 1
            || $maxTokens < 1
            || $maxTokens > 100000) {
            throw new InvalidArgumentException('invalid');
        }

        $statement = $pdo->prepare(
            'INSERT INTO ai_settings (
                id, is_enabled, provider, model_name, temperature, max_tokens,
                save_raw_response, allow_expense, allow_income, allow_overtime, allow_leave
             ) VALUES (
                1, :is_enabled, :provider, :model_name, :temperature, :max_tokens,
                :save_raw_response, :allow_expense, :allow_income, :allow_overtime, :allow_leave
             )
             ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                provider = VALUES(provider),
                model_name = VALUES(model_name),
                temperature = VALUES(temperature),
                max_tokens = VALUES(max_tokens),
                save_raw_response = VALUES(save_raw_response),
                allow_expense = VALUES(allow_expense),
                allow_income = VALUES(allow_income),
                allow_overtime = VALUES(allow_overtime),
                allow_leave = VALUES(allow_leave)'
        );
        $statement->execute([
            'is_enabled' => isset($_POST['is_enabled']) ? 1 : 0,
            'provider' => $provider,
            'model_name' => $modelName,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'save_raw_response' => isset($_POST['save_raw_response']) ? 1 : 0,
            'allow_expense' => isset($_POST['allow_expense']) ? 1 : 0,
            'allow_income' => isset($_POST['allow_income']) ? 1 : 0,
            'allow_overtime' => isset($_POST['allow_overtime']) ? 1 : 0,
            'allow_leave' => isset($_POST['allow_leave']) ? 1 : 0,
        ]);
        $message = 'AI 模型設定已儲存';
    }
} catch (LogicException $exception) {
    $error = $exception->getMessage();
} catch (Throwable) {
    $error = safe_error_message();
}

$settings = $pdo->query('SELECT * FROM ai_settings WHERE id = 1')->fetch() ?: [
    'is_enabled' => 0,
    'provider' => 'local',
    'model_name' => '',
    'temperature' => '0.10',
    'max_tokens' => 1000,
    'save_raw_response' => 0,
    'allow_expense' => 1,
    'allow_income' => 1,
    'allow_overtime' => 1,
    'allow_leave' => 1,
];
$geminiApiKeyValue = trim((string) app_env('GEMINI_API_KEY', ''));
$geminiApiKeyConfigured = !$demoMode
    && $geminiApiKeyValue !== ''
    && !str_starts_with($geminiApiKeyValue, 'change_this_');
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI 模型設定</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="page">
        <div class="page-header">
            <div>
                <h1>AI 模型設定</h1>
                <p>Gemini Provider 使用專案根目錄 `.env` 的金鑰；此頁不顯示或保存金鑰內容。</p>
            </div>
            <nav class="nav">
                <a href="/dashboard.php">主控台</a>
                <a href="/settings.php">系統設定</a>
                <a href="/ai_entry.php">AI 快速輸入</a>
                <a href="/ai_parse_logs.php">AI 記帳紀錄</a>
            </nav>
        </div>

        <?php if ($message !== ''): ?><p class="success"><?= h($message) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

        <section class="notice-panel">
            <?php if ($demoMode): ?>
                <strong>展示模式：外部 AI 已停用</strong>
                <p>本機 Demo 不讀取 Gemini Key，也不會送出任何資料。此頁設定已鎖定。</p>
            <?php else: ?>
                <strong>Gemini API Key：<?= $geminiApiKeyConfigured ? '已設定' : '未設定' ?></strong>
                <p>啟用後會呼叫 Gemini 產生預覽與解析紀錄，但不會寫入任何收入、支出、加班或請假資料。</p>
            <?php endif; ?>
        </section>

        <section class="form-panel">
            <form class="grid-form" method="post">
                <fieldset class="demo-lock-fieldset" <?= $demoMode ? 'disabled' : '' ?>>
                <label class="check"><input name="is_enabled" type="checkbox" <?= (int) $settings['is_enabled'] === 1 ? 'checked' : '' ?>> 啟用 AI 設定</label>
                <label>Provider 代碼
                    <input name="provider" value="<?= h($settings['provider']) ?>" pattern="[a-z0-9_-]{2,40}" required>
                </label>
                <label>模型名稱
                    <input name="model_name" value="<?= h($settings['model_name']) ?>" maxlength="120" placeholder="由使用者設定，不寫死">
                </label>
                <label>Temperature
                    <input name="temperature" inputmode="decimal" value="<?= h((string) $settings['temperature']) ?>" required>
                </label>
                <label>最大輸出 Token
                    <input name="max_tokens" type="number" min="1" max="100000" value="<?= h((string) $settings['max_tokens']) ?>" required>
                </label>
                <label class="check"><input name="save_raw_response" type="checkbox" <?= (int) $settings['save_raw_response'] === 1 ? 'checked' : '' ?>> 保存 Gemini 完整原始回應</label>
                <fieldset class="wide option-group">
                    <legend>允許解析類型</legend>
                    <label class="check"><input name="allow_expense" type="checkbox" <?= (int) $settings['allow_expense'] === 1 ? 'checked' : '' ?>> 支出</label>
                    <label class="check"><input name="allow_income" type="checkbox" <?= (int) $settings['allow_income'] === 1 ? 'checked' : '' ?>> 收入</label>
                    <label class="check"><input name="allow_overtime" type="checkbox" <?= (int) $settings['allow_overtime'] === 1 ? 'checked' : '' ?>> 加班</label>
                    <label class="check"><input name="allow_leave" type="checkbox" <?= (int) $settings['allow_leave'] === 1 ? 'checked' : '' ?>> 請假</label>
                </fieldset>
                <button type="submit">儲存 AI 設定</button>
                </fieldset>
            </form>
        </section>
    </main>
    <?php render_mobile_nav('back'); ?>
</body>
</html>
