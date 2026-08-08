<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

final class DemoMode
{
    public const DATABASE = 'personal_accounting_demo';

    public static function isRequested(): bool
    {
        return strtolower(trim((string) app_env('APP_ENV', ''))) === 'demo'
            || self::envFlagEnabled('DEMO_MODE');
    }

    public static function isEnabled(): bool
    {
        return strtolower(trim((string) app_env('APP_ENV', ''))) === 'demo'
            && self::envFlagEnabled('DEMO_MODE');
    }

    public static function assertDatabaseConfiguration(string $database): void
    {
        if (!self::isRequested()) {
            return;
        }

        if (!self::isEnabled()) {
            throw new RuntimeException('Demo mode configuration is incomplete.');
        }

        if (!hash_equals(self::DATABASE, trim($database))) {
            throw new RuntimeException('Demo mode refused a non-demo database.');
        }
    }

    public static function assertConnectedDatabase(PDO $pdo): void
    {
        if (!self::isRequested()) {
            return;
        }

        $actualDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!hash_equals(self::DATABASE, $actualDatabase)) {
            throw new RuntimeException('Demo mode database identity check failed.');
        }
    }

    public static function guardPublicEndpoint(string $label, bool $json = false): void
    {
        if (!self::isEnabled()) {
            return;
        }

        http_response_code(403);
        header('Cache-Control: no-store');

        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'message' => '展示模式已停用此入口。',
                'summary' => null,
                'error' => [
                    'code' => 'demo_endpoint_disabled',
                    'message' => '展示模式已停用此入口。',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>展示模式已停用此入口</title><link rel="stylesheet" href="/style.css"></head>'
            . '<body><main class="shell"><section class="panel">'
            . '<span class="status-badge">本機互動展示</span>'
            . '<h1>' . $safeLabel . '已停用</h1>'
            . '<p>此入口原本只供可信私網使用。展示模式不接受未登入寫入，也不會呼叫外部 AI。</p>'
            . '<a class="button" href="/login.php">前往展示登入</a>'
            . '</section></main></body></html>';
        exit;
    }

    private static function envFlagEnabled(string $key): bool
    {
        return in_array(strtolower(trim((string) app_env($key, '0'))), ['1', 'true', 'yes', 'on'], true);
    }
}
