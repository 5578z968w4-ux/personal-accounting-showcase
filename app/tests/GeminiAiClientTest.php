<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/GeminiAiClient.php';

function gemini_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$missingKeyRejected = false;
try {
    (new GeminiAiClient(''))->generate('test', ['model_name' => 'test-model'], []);
} catch (AiParseException $exception) {
    $missingKeyRejected = $exception->errorCode() === 'missing_api_key';
}
gemini_assert($missingKeyRejected, 'Missing API key must be rejected before network access');

$invalidModelRejected = false;
try {
    (new GeminiAiClient('test-key'))->generate('test', ['model_name' => '../invalid'], []);
} catch (AiParseException $exception) {
    $invalidModelRejected = $exception->errorCode() === 'invalid_model';
}
gemini_assert($invalidModelRejected, 'Invalid model must be rejected before network access');

echo "GeminiAiClientTest passed\n";
