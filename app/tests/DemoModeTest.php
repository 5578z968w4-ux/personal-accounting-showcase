<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/DemoMode.php';
require_once dirname(__DIR__) . '/src/AiClientFactory.php';
require_once dirname(__DIR__) . '/src/html.php';

function demo_mode_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$previousAppEnv = getenv('APP_ENV');
$previousDemoMode = getenv('DEMO_MODE');

try {
    putenv('APP_ENV=demo');
    putenv('DEMO_MODE=1');

    demo_mode_assert(DemoMode::isRequested(), 'Demo environment should be recognized as requested');
    demo_mode_assert(DemoMode::isEnabled(), 'Demo mode should require matching environment and flag');
    DemoMode::assertDatabaseConfiguration(DemoMode::DATABASE);

    $wrongDatabaseRejected = false;
    try {
        DemoMode::assertDatabaseConfiguration('personal_accounting');
    } catch (RuntimeException) {
        $wrongDatabaseRejected = true;
    }
    demo_mode_assert($wrongDatabaseRejected, 'Demo mode must reject a non-demo database before connecting');

    $aiBlocked = false;
    try {
        (new AiClientFactory())->create(['provider' => 'gemini']);
    } catch (AiParseException $exception) {
        $aiBlocked = $exception->errorCode() === 'demo_ai_disabled';
    }
    demo_mode_assert($aiBlocked, 'Demo mode must block external AI clients before network access');

    ob_start();
    render_demo_banner();
    $banner = (string) ob_get_clean();
    demo_mode_assert(
        str_contains($banner, '本機互動展示')
        && str_contains($banner, '獨立 Demo DB')
        && str_contains($banner, 'demo-mode-banner'),
        'Demo banner should explain the isolated interactive environment'
    );

    foreach ([
        'public/quick_entry.php' => "DemoMode::guardPublicEndpoint('快速記帳')",
        'public/quick_entry_api.php' => "DemoMode::guardPublicEndpoint('Shortcut API', true)",
        'public/db-test.php' => "DemoMode::guardPublicEndpoint('資料庫診斷頁')",
        'scripts/demo_reset.php' => 'DemoMode::isEnabled()',
    ] as $path => $expected) {
        $content = file_get_contents(dirname(__DIR__) . '/' . $path);
        demo_mode_assert(is_string($content) && str_contains($content, $expected), $path . ' should keep its demo safety gate');
    }

    putenv('DEMO_MODE=0');
    demo_mode_assert(DemoMode::isRequested(), 'APP_ENV=demo should keep the fail-closed request state');
    demo_mode_assert(!DemoMode::isEnabled(), 'Incomplete demo configuration must not be enabled');

    $incompleteConfigurationRejected = false;
    try {
        DemoMode::assertDatabaseConfiguration(DemoMode::DATABASE);
    } catch (RuntimeException) {
        $incompleteConfigurationRejected = true;
    }
    demo_mode_assert($incompleteConfigurationRejected, 'Incomplete demo configuration must fail closed');
} finally {
    $previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previousAppEnv);
    $previousDemoMode === false ? putenv('DEMO_MODE') : putenv('DEMO_MODE=' . $previousDemoMode);
}

echo "DemoModeTest passed\n";
