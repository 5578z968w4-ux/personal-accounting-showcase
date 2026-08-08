<?php

declare(strict_types=1);

function valid_login_return_to(string $returnTo): bool
{
    return preg_match('#^/[a-zA-Z0-9/_-]+\.php(\?[a-zA-Z0-9%=&_.~+/\-]*)?$#', $returnTo) === 1;
}

function require_login(?string $returnTo = null): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        if ($returnTo === null) {
            $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
            $returnTo = valid_login_return_to($requestUri) ? $requestUri : $scriptName;
        }

        $location = '/login.php';
        if ($returnTo !== '' && valid_login_return_to($returnTo)) {
            $location .= '?return_to=' . rawurlencode($returnTo);
        }
        header('Location: ' . $location);
        exit;
    }
}
