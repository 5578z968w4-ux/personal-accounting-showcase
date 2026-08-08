<?php

declare(strict_types=1);

require_once __DIR__ . '/AiParserInterface.php';

final class ScaffoldAiParser implements AiParserInterface
{
    public function parse(string $inputText, string $requestedType, array $settings): array
    {
        $type = in_array($requestedType, ['expense', 'income', 'overtime', 'leave'], true)
            ? $requestedType
            : 'unknown';

        return [
            'status' => 'preview_only',
            'type' => $type,
            'provider' => (string) ($settings['provider'] ?? 'local'),
            'model' => (string) ($settings['model_name'] ?? ''),
            'confidence' => null,
            'fields' => $this->emptyFields($type),
            'raw_input' => $inputText,
            'warnings' => [
                '第一階段尚未呼叫外部 AI API，以下為預覽格式骨架。',
                '此預覽不會寫入收入、支出、加班或請假資料。',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyFields(string $type): array
    {
        return match ($type) {
            'expense' => [
                'record_date' => null,
                'item' => null,
                'amount' => null,
                'payment_method' => null,
                'category' => null,
                'accounting_month' => null,
            ],
            'income' => [
                'record_date' => null,
                'source_name' => null,
                'amount' => null,
                'account_name' => null,
                'category' => null,
                'accounting_month' => null,
            ],
            'overtime' => [
                'work_date' => null,
                'overtime_hours' => null,
            ],
            'leave' => [
                'leave_date' => null,
                'leave_type' => null,
                'leave_days' => null,
                'leave_hours' => null,
                'note' => null,
            ],
            default => [],
        };
    }
}
