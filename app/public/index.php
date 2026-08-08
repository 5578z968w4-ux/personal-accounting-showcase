<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: /login.php?return_to=' . rawurlencode('/dashboard.php'));
    exit;
}

header('Location: /dashboard.php');
exit;
