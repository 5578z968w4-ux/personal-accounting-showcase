<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/DemoMode.php';

function app_db(): PDO
{
    $host = app_env('DB_HOST', 'db');
    $port = app_env('DB_PORT', '3306');
    $database = app_env('DB_DATABASE', 'personal_accounting');
    $username = app_env('DB_USERNAME', 'personal_accounting_user');
    $password = app_env('DB_PASSWORD', '');

    DemoMode::assertDatabaseConfiguration((string) $database);

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $database
    );

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    DemoMode::assertConnectedDatabase($pdo);

    return $pdo;
}
