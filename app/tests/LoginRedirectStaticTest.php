<?php

declare(strict_types=1);

function login_redirect_static_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$auth = file_get_contents($root . '/src/auth.php');
$login = file_get_contents($root . '/public/login.php');
$index = file_get_contents($root . '/public/index.php');
$quickEntry = file_get_contents($root . '/public/quick_entry.php');
$quickEntryApi = file_get_contents($root . '/public/quick_entry_api.php');

login_redirect_static_assert(is_string($auth), 'auth.php should be readable');
login_redirect_static_assert(is_string($login), 'login.php should be readable');
login_redirect_static_assert(is_string($index), 'index.php should be readable');
login_redirect_static_assert(is_string($quickEntry), 'quick_entry.php should be readable');
login_redirect_static_assert(is_string($quickEntryApi), 'quick_entry_api.php should be readable');

login_redirect_static_assert(
    str_contains($login, "\$returnTo = '/dashboard.php';"),
    'login default return target should be dashboard.php'
);
login_redirect_static_assert(
    str_contains($auth, 'valid_login_return_to') && str_contains($auth, "\$_SERVER['REQUEST_URI']"),
    'require_login should preserve the requested protected page and safe query string'
);
login_redirect_static_assert(
    str_contains($auth, "rawurlencode(\$returnTo)"),
    'require_login should pass a validated return target to login.php'
);
login_redirect_static_assert(
    str_contains($index, "rawurlencode('/dashboard.php')") && str_contains($index, "Location: /dashboard.php"),
    'index.php should route login/default access to dashboard.php'
);
login_redirect_static_assert(
    !str_contains($quickEntry, 'require_login('),
    'quick_entry.php must remain login-free'
);
login_redirect_static_assert(
    !str_contains($quickEntryApi, 'require_login(') && str_contains($quickEntryApi, "Content-Type: application/json"),
    'quick_entry_api.php must remain login-free JSON API'
);

echo "LoginRedirectStaticTest passed\n";
