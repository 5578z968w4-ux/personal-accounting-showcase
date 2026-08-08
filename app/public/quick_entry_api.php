<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/env.php';
require_once dirname(__DIR__) . '/src/html.php';
require_once dirname(__DIR__) . '/src/QuickEntryApiService.php';
require_once dirname(__DIR__) . '/src/DemoMode.php';

/** @param array<string, mixed> $payload */
function quick_entry_api_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return array<string, mixed> */
function quick_entry_api_payload(): array
{
    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || trim($rawBody) === '') {
        throw new QuickEntryApiRequestException('請提供 JSON request body。', 'empty_body');
    }

    try {
        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new QuickEntryApiRequestException('JSON 格式不正確。', 'invalid_json');
    }

    if (!is_array($payload)) {
        throw new QuickEntryApiRequestException('JSON body 必須是 object。', 'invalid_body');
    }

    return $payload;
}

function quick_entry_api_error(
    int $status,
    string $message,
    string $code,
    ?array $details = null
): never {
    quick_entry_api_json($status, quick_entry_api_error_payload($message, $code, $details));
}

/** @return array<string, mixed> */
function quick_entry_api_error_payload(string $message, string $code, ?array $details = null): array
{
    $error = [
        'code' => $code,
        'message' => $message,
    ];
    if ($details !== null && $details !== []) {
        $error['details'] = $details;
    }

    return [
        'ok' => false,
        'message' => $message,
        'summary' => null,
        'error' => $error,
    ];
}

function quick_entry_api_main(): never
{
    DemoMode::guardPublicEndpoint('Shortcut API', true);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        quick_entry_api_error(405, '只接受 POST JSON request。', 'method_not_allowed');
    }

    try {
        $payload = quick_entry_api_payload();
        $pdo = app_db();
        $settings = $pdo->query('SELECT * FROM ai_settings WHERE id = 1')->fetch() ?: [];
        $service = new QuickEntryApiService($pdo);

        quick_entry_api_json(
            200,
            $service->handle($payload, $settings, (string) app_env('APP_LOGIN_USERNAME', ''))
        );
    } catch (QuickEntryApiRequestException $exception) {
        quick_entry_api_error($exception->statusCode(), $exception->getMessage(), $exception->errorCode());
    } catch (QuickEntryValidationException $exception) {
        quick_entry_api_error(422, $exception->getMessage(), 'business_validation_failed', [
            'fields' => $exception->fieldErrors(),
        ]);
    } catch (AiParseException $exception) {
        quick_entry_api_error(422, $exception->getMessage(), $exception->errorCode());
    } catch (Throwable) {
        quick_entry_api_error(500, safe_error_message(), 'internal_error');
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    quick_entry_api_main();
}
