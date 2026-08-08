<?php

declare(strict_types=1);

require_once __DIR__ . '/AiParseService.php';
require_once __DIR__ . '/AiInputDateResolver.php';
require_once __DIR__ . '/QuickEntryWriteService.php';
require_once __DIR__ . '/EntryOwner.php';

final class QuickEntryApiRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $statusCode = 400
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}

final class QuickEntryApiService
{
    public const SOURCE = 'ios_shortcut';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?object $parseService = null,
        private readonly ?QuickEntryWriteService $writer = null
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function handle(array $payload, array $settings, ?string $userName): array
    {
        $text = $this->textFromPayload($payload);
        $clientRequestId = $this->clientRequestIdFromPayload($payload);
        $entryOwner = $this->entryOwnerFromPayload($payload);
        $parser = $this->parseService ?? $this->defaultParseService();
        $writer = $this->writer ?? new QuickEntryWriteService($this->pdo);

        if (!method_exists($parser, 'preview')) {
            throw new RuntimeException('Quick Entry API parser is unavailable.');
        }

        $parsed = $parser->preview($text, 'auto', $settings, self::SOURCE, $userName, $entryOwner);
        $parsedType = (string) ($parsed['type'] ?? '');
        $fields = is_array($parsed['fields'] ?? null) ? $parsed['fields'] : [];
        $fields['entry_owner'] = $entryOwner;

        if ($entryOwner === EntryOwner::PROFILE_B && $parsedType !== 'expense') {
            throw new QuickEntryValidationException(
                '展示對象 B捷徑目前只支援支出寫入。',
                ['entry_owner' => '展示對象 B只能透過捷徑新增支出，不能新增收入、加班或請假。'],
                $parsedType,
                $fields
            );
        }

        $summary = $writer->save(
            $parsedType,
            $fields,
            $text,
            $userName,
            [
                'ai_parse_log_id' => (int) ($parsed['ai_parse_log_id'] ?? 0),
                'source' => self::SOURCE,
            ],
            self::SOURCE
        );

        $response = [
            'ok' => true,
            'message' => '寫入成功。',
            'summary' => $this->publicSummary($summary),
            'error' => null,
        ];
        if ($clientRequestId !== null) {
            $response['client_request_id'] = $clientRequestId;
        }

        return $response;
    }

    private function defaultParseService(): AiParseService
    {
        return new AiParseService(
            $this->pdo,
            new AiClientFactory(),
            new AiPromptBuilder(),
            new AiResponseValidator(),
            new AiBusinessValidator($this->pdo),
            new AiInputDateResolver()
        );
    }

    /** @param array<string, mixed> $payload */
    private function textFromPayload(array $payload): string
    {
        if (!array_key_exists('text', $payload) || !is_string($payload['text'])) {
            throw new QuickEntryApiRequestException('請提供 text 欄位。', 'missing_text');
        }

        $text = trim($payload['text']);
        if ($text === '') {
            throw new QuickEntryApiRequestException('text 不可空白。', 'empty_text');
        }
        if (strlen($text) > 8000) {
            throw new QuickEntryApiRequestException('text 不得超過 2000 個字。', 'text_too_long');
        }

        return $text;
    }

    /** @param array<string, mixed> $payload */
    private function clientRequestIdFromPayload(array $payload): ?string
    {
        if (!array_key_exists('client_request_id', $payload) || $payload['client_request_id'] === null) {
            return null;
        }
        if (!is_string($payload['client_request_id']) && !is_int($payload['client_request_id'])) {
            throw new QuickEntryApiRequestException('client_request_id 格式不正確。', 'invalid_client_request_id');
        }

        $clientRequestId = trim((string) $payload['client_request_id']);
        if ($clientRequestId === '') {
            return null;
        }
        if (strlen($clientRequestId) > 100) {
            throw new QuickEntryApiRequestException('client_request_id 不得超過 100 個字。', 'client_request_id_too_long');
        }

        return $clientRequestId;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function publicSummary(array $summary): array
    {
        $allowedKeys = [
            'type',
            'action',
            'title',
            'date',
            'overtime_hours',
            'amount',
            'unit',
            'category',
            'payment_method',
            'account_name',
            'accounting_month',
            'entry_owner',
            'note',
            'raw_input',
        ];
        $public = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $summary)) {
                $public[$key] = $summary[$key];
            }
        }

        return $public;
    }

    /** @param array<string, mixed> $payload */
    private function entryOwnerFromPayload(array $payload): string
    {
        try {
            return EntryOwner::normalize($payload['entry_owner'] ?? null);
        } catch (InvalidArgumentException) {
            throw new QuickEntryApiRequestException(
                'entry_owner 只允許 profile_a、profile_b、展示對象 A、展示對象 B。',
                'invalid_entry_owner'
            );
        }
    }
}
