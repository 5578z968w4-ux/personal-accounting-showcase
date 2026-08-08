<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/AiParseLogListService.php';

function ai_parse_log_list_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE ai_parse_logs (
    id INTEGER PRIMARY KEY,
    raw_input TEXT NOT NULL,
    parsed_json TEXT NULL,
    parse_status TEXT NOT NULL,
    error_code TEXT NULL,
    error_message TEXT NULL,
    parsed_type TEXT NULL,
    duration_ms INTEGER NULL,
    source TEXT NULL,
    created_at TEXT NOT NULL
)');

$insert = $pdo->prepare(
    'INSERT INTO ai_parse_logs (
        id, raw_input, parsed_json, parse_status, error_code, error_message,
        parsed_type, duration_ms, source, created_at
     ) VALUES (
        :id, :raw_input, :parsed_json, :parse_status, :error_code, :error_message,
        :parsed_type, :duration_ms, :source, :created_at
     )'
);
for ($id = 1; $id <= 22; $id++) {
    $insert->execute([
        'id' => $id,
        'raw_input' => $id === 22 ? str_repeat('輸入', 130) : '測試輸入 ' . $id,
        'parsed_json' => json_encode(['type' => $id % 2 === 0 ? 'expense' : 'income'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'parse_status' => $id % 3 === 0 ? 'provider_error' : 'success',
        'error_code' => $id % 3 === 0 ? 'provider_failed' : null,
        'error_message' => $id % 3 === 0 ? str_repeat('錯誤訊息', 120) : null,
        'parsed_type' => $id % 2 === 0 ? 'expense' : 'income',
        'duration_ms' => 100,
        'source' => $id % 2 === 0 ? 'quick_pwa' : 'admin_ai_input',
        'created_at' => $id === 1
            ? '2026-07-27 18:00:00'
            : ($id <= 10 ? '2026-07-27 10:00:00' : '2026-07-28 10:00:00'),
    ]);
}

$service = new AiParseLogListService($pdo);
$firstPage = $service->latest([]);
ai_parse_log_list_assert(count($firstPage['rows']) === AiParseLogListService::PAGE_SIZE, 'First page must use the fixed page size');
ai_parse_log_list_assert((int) $firstPage['rows'][0]['id'] === 22, 'First page must return newest id first');
ai_parse_log_list_assert($firstPage['next_before_id'] === 3, 'Next cursor must be the final displayed id');
ai_parse_log_list_assert((int) $firstPage['rows'][0]['raw_input_is_truncated'] === 1, 'Long raw input must be marked as truncated');

$secondPage = $service->latest([], $firstPage['next_before_id']);
ai_parse_log_list_assert(array_column($secondPage['rows'], 'id') === [2, 1], 'Cursor page must return only older rows');
ai_parse_log_list_assert($secondPage['next_before_id'] === null, 'Final cursor page must not expose a next cursor');

$filtered = $service->latest([
    'source' => 'quick_pwa',
    'status' => 'success',
    'type' => 'expense',
    'date_from' => '2026-07-28',
    'date_to' => '2026-07-28',
]);
ai_parse_log_list_assert(array_column($filtered['rows'], 'id') === [22, 20, 16, 14], 'Filters must combine source, status, type, and inclusive date range');

$taipeiBoundary = $service->latest([
    'date_from' => '2026-07-28',
    'date_to' => '2026-07-28',
]);
ai_parse_log_list_assert(
    in_array(1, array_column($taipeiBoundary['rows'], 'id'), true)
    && !in_array(10, array_column($taipeiBoundary['rows'], 'id'), true),
    'Taipei date filters must use the UTC-adjusted start of day'
);

$errorRow = $service->latest(['status' => 'provider_error'])['rows'][0];
ai_parse_log_list_assert((int) $errorRow['error_message_is_truncated'] === 1, 'Long errors must be marked as truncated');
ai_parse_log_list_assert(!array_key_exists('ai_response', $errorRow), 'List query must not load raw AI response');

echo "AiParseLogListServiceTest passed\n";
