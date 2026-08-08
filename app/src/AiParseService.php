<?php

declare(strict_types=1);

require_once __DIR__ . '/AiClientFactory.php';
require_once __DIR__ . '/AiPromptBuilder.php';
require_once __DIR__ . '/AiResponseValidator.php';
require_once __DIR__ . '/AiBusinessValidator.php';
require_once __DIR__ . '/AiParseException.php';
require_once __DIR__ . '/AiInputDateResolver.php';

final class AiParseService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AiClientFactory $clientFactory,
        private readonly AiPromptBuilder $promptBuilder,
        private readonly AiResponseValidator $responseValidator,
        private readonly AiBusinessValidator $businessValidator,
        private readonly AiInputDateResolver $inputDateResolver = new AiInputDateResolver()
    ) {
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function preview(
        string $inputText,
        string $requestedType,
        array $settings,
        string $source = 'web',
        ?string $userName = null,
        string $entryOwner = 'profile_a'
    ): array {
        $inputText = trim($inputText);
        $provider = strtolower(trim((string) ($settings['provider'] ?? '')));
        $modelName = trim((string) ($settings['model_name'] ?? ''));
        $saveRawResponse = (int) ($settings['save_raw_response'] ?? 0) === 1;
        $startedAt = microtime(true);
        $clientResult = null;

        try {
            $this->validateRequest($inputText, $requestedType, $settings);
            $client = $this->clientFactory->create($settings);
            $prompt = $this->promptBuilder->build(
                $inputText,
                $requestedType,
                $this->businessValidator->referenceData()
            );
            $clientResult = $client->generate($prompt, $settings, $this->promptBuilder->responseSchema());
            $validated = $this->responseValidator->validate($clientResult['text'], $requestedType);
            $this->assertTypeAllowed($validated['type'], $settings);
            $validated['fields'] = $this->applyInputDate(
                $validated['type'],
                $validated['fields'],
                $inputText
            );
            $businessResult = $this->businessValidator->validate(
                $validated['type'],
                $validated['fields'],
                $source
            );
            $businessResult['fields']['entry_owner'] = $entryOwner;

            $preview = [
                'status' => 'success',
                'type' => $validated['type'],
                'provider' => $provider,
                'model' => $modelName,
                'confidence' => null,
                'fields' => $businessResult['fields'],
                'raw_input' => $inputText,
                'warnings' => array_merge(
                    $businessResult['warnings'],
                    [$this->previewSafetyWarning($source)]
                ),
            ];

            $parsedJson = json_encode(
                ['type' => $preview['type'], 'fields' => $preview['fields'], 'warnings' => $businessResult['warnings']],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $aiParseLogId = $this->insertLog([
                'raw_input' => $inputText,
                'ai_response' => $saveRawResponse ? $clientResult['raw_response'] : null,
                'provider' => $provider,
                'model_name' => $modelName,
                'parsed_type' => $preview['type'],
                'parsed_json' => $parsedJson,
                'parse_status' => 'success',
                'error_code' => null,
                'error_message' => null,
                'duration_ms' => $clientResult['duration_ms'],
                'source' => $source,
                'user_name' => $userName,
                'entry_owner' => $entryOwner,
            ]);
            $preview['ai_parse_log_id'] = $aiParseLogId;
            $preview['parsed_json'] = $parsedJson;

            return $preview;
        } catch (AiParseException $exception) {
            $durationMs = $exception->durationMs() > 0
                ? $exception->durationMs()
                : (is_array($clientResult) ? (int) $clientResult['duration_ms'] : 0);
            $durationMs = $durationMs > 0
                ? $durationMs
                : (int) round((microtime(true) - $startedAt) * 1000);
            $parsedJsonSnapshot = $this->parsedJsonSnapshot(
                is_array($clientResult) ? ($clientResult['text'] ?? null) : null
            );
            $this->insertLog([
                'raw_input' => $inputText,
                'ai_response' => $saveRawResponse
                    ? ($exception->rawResponse() ?? (is_array($clientResult) ? $clientResult['raw_response'] : null))
                    : null,
                'provider' => $provider,
                'model_name' => $modelName,
                'parsed_type' => $this->parsedTypeSnapshot($parsedJsonSnapshot),
                'parsed_json' => $parsedJsonSnapshot,
                'parse_status' => $exception->parseStatus(),
                'error_code' => $exception->errorCode(),
                'error_message' => $exception->getMessage(),
                'duration_ms' => $durationMs,
                'source' => $source,
                'user_name' => $userName,
                'entry_owner' => $entryOwner,
            ]);
            throw $exception;
        } catch (Throwable) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            try {
                    $this->insertLog([
                    'raw_input' => $inputText,
                    'ai_response' => null,
                    'provider' => $provider,
                    'model_name' => $modelName,
                    'parsed_type' => null,
                    'parsed_json' => null,
                    'parse_status' => 'provider_error',
                    'error_code' => 'internal_error',
                    'error_message' => 'AI 解析發生內部錯誤。',
                    'duration_ms' => $durationMs,
                    'source' => $source,
                    'user_name' => $userName,
                    'entry_owner' => $entryOwner,
                ]);
            } catch (Throwable) {
                // Preserve the safe public error even if logging is unavailable.
            }
            throw new AiParseException(
                'AI 解析發生內部錯誤，請稍後再試。',
                'provider_error',
                'internal_error',
                null,
                $durationMs
            );
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function applyInputDate(string $type, array $fields, string $inputText): array
    {
        $inputDate = $this->inputDateResolver->resolve($inputText);
        if ($inputDate === null) {
            return $fields;
        }

        $dateField = match ($type) {
            'expense', 'income' => 'record_date',
            'overtime' => 'work_date',
            'leave' => 'leave_date',
            default => null,
        };

        if ($dateField !== null) {
            $fields[$dateField] = $inputDate;
        }

        return $fields;
    }

    private function parsedJsonSnapshot(mixed $responseText): ?string
    {
        if (!is_string($responseText) || trim($responseText) === '') {
            return null;
        }

        try {
            $data = json_decode($responseText, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        try {
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    private function parsedTypeSnapshot(?string $parsedJson): ?string
    {
        if ($parsedJson === null) {
            return null;
        }

        try {
            $data = json_decode($parsedJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $type = is_array($data) ? (string) ($data['type'] ?? '') : '';
        return in_array($type, ['expense', 'income', 'overtime', 'leave'], true) ? $type : null;
    }

    private function previewSafetyWarning(string $source): string
    {
        return $source === 'admin_ai_input'
            ? '這是預覽，還沒有新增正式記錄。確認內容無誤後，按「確認記帳」即可儲存。'
            : '此預覽不會寫入收入、支出、加班或請假資料。';
    }

    /** @param array<string, mixed> $settings */
    private function validateRequest(string $inputText, string $requestedType, array $settings): void
    {
        if ($inputText === '') {
            throw new AiParseException('請輸入要解析的內容。', 'validation_failed', 'empty_input');
        }
        if (strlen($inputText) > 8000) {
            throw new AiParseException('輸入內容不得超過 2000 個字。', 'validation_failed', 'input_too_long');
        }
        if (!in_array($requestedType, ['auto', 'expense', 'income', 'overtime', 'leave'], true)) {
            throw new AiParseException('預覽類型不正確。', 'validation_failed', 'invalid_requested_type');
        }
        if ((int) ($settings['is_enabled'] ?? 0) !== 1) {
            throw new AiParseException('AI 功能尚未啟用。', 'disabled', 'ai_disabled');
        }
        if ($requestedType !== 'auto') {
            $this->assertTypeAllowed($requestedType, $settings);
        }
    }

    /** @param array<string, mixed> $settings */
    private function assertTypeAllowed(string $type, array $settings): void
    {
        if ((int) ($settings['allow_' . $type] ?? 0) !== 1) {
            throw new AiParseException('此資料類型目前未允許解析。', 'disabled', 'type_disabled');
        }
    }

    /** @param array<string, mixed> $data */
    private function insertLog(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ai_parse_logs (
                raw_input, ai_response, provider, model_name, parsed_type, parsed_json,
                parse_status, error_code, error_message, duration_ms, source, user_name, entry_owner
             ) VALUES (
                :raw_input, :ai_response, :provider, :model_name, :parsed_type, :parsed_json,
                :parse_status, :error_code, :error_message, :duration_ms, :source, :user_name, :entry_owner
             )'
        );
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }
}
