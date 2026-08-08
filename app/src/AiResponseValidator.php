<?php

declare(strict_types=1);

require_once __DIR__ . '/AiParseException.php';

final class AiResponseValidator
{
    private const CATEGORIES = [
        '餐飲',
        '交通',
        '購物',
        '3C',
        '娛樂',
        '生活',
        '醫療',
        '薪資',
        '加班',
        '其他',
    ];

    /**
     * @return array{type: string, fields: array<string, mixed>}
     */
    public function validate(string $responseText, string $requestedType): array
    {
        try {
            $data = json_decode($responseText, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiParseException(
                'AI 回傳的 JSON 格式不正確。',
                'invalid_json',
                'invalid_json',
                $responseText
            );
        }

        if (!is_array($data)) {
            throw new AiParseException('AI 回傳內容不是物件。', 'validation_failed', 'invalid_root');
        }

        $type = (string) ($data['type'] ?? '');
        if (!in_array($type, ['expense', 'income', 'overtime', 'leave'], true)) {
            throw new AiParseException('AI 回傳的資料類型不正確。', 'validation_failed', 'invalid_type');
        }
        if ($requestedType !== 'auto' && $type !== $requestedType) {
            throw new AiParseException('AI 回傳類型與指定類型不一致。', 'validation_failed', 'type_mismatch');
        }

        $fields = match ($type) {
            'expense' => $this->validateExpense($data),
            'income' => $this->validateIncome($data),
            'overtime' => $this->validateOvertime($data),
            'leave' => $this->validateLeave($data),
        };

        return ['type' => $type, 'fields' => $fields];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validateExpense(array $data): array
    {
        return [
            'record_date' => $this->date($data, 'record_date'),
            'item' => $this->requiredString($data, 'item', 160),
            'amount' => $this->positiveNumber($data, 'amount'),
            'payment_method' => $this->optionalString($data, 'payment_method', 80),
            'category' => $this->category($data, (string) ($data['item'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validateIncome(array $data): array
    {
        return [
            'record_date' => $this->date($data, 'record_date'),
            'source_name' => $this->requiredString($data, 'source_name', 160),
            'amount' => $this->positiveNumber($data, 'amount'),
            'account_name' => $this->optionalString($data, 'account_name', 80),
            'category' => $this->category($data, (string) ($data['source_name'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validateOvertime(array $data): array
    {
        return [
            'work_date' => $this->date($data, 'work_date'),
            'overtime_hours' => $this->positiveNumber($data, 'overtime_hours'),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validateLeave(array $data): array
    {
        $leaveDays = $this->optionalNonNegativeNumber($data, 'leave_days');
        $leaveHours = $this->optionalNonNegativeNumber($data, 'leave_hours');
        if ($leaveDays <= 0 && $leaveHours <= 0) {
            throw new AiParseException('請假天數或時數必須大於 0。', 'validation_failed', 'invalid_leave_duration');
        }

        return [
            'leave_date' => $this->date($data, 'leave_date'),
            'leave_type' => $this->optionalString($data, 'leave_type', 80),
            'leave_days' => $leaveDays,
            'leave_hours' => $leaveHours,
            'note' => $this->optionalString($data, 'note', 500),
        ];
    }

    /** @param array<string, mixed> $data */
    private function date(array $data, string $key): string
    {
        $value = $this->requiredString($data, $key, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Taipei'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new AiParseException('AI 回傳日期格式不正確。', 'validation_failed', 'invalid_date');
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key, int $maxLength): string
    {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value === '' || strlen($value) > $maxLength * 4) {
            throw new AiParseException('AI 回傳必要欄位不完整。', 'validation_failed', 'missing_required_field');
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    private function optionalString(array $data, string $key, int $maxLength): string
    {
        $value = trim((string) ($data[$key] ?? ''));
        if (strlen($value) > $maxLength * 4) {
            throw new AiParseException('AI 回傳文字欄位過長。', 'validation_failed', 'field_too_long');
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    private function category(array $data, string $subject): string
    {
        $subject = strtoupper(trim($subject));
        $keywordCategories = [
            '3C' => ['PS5', 'SWITCH', '手機', '電腦', '耳機', '螢幕'],
            '餐飲' => ['便利商店', '7-11', '7－11', '全家', '早餐', '午餐', '晚餐', '飲料'],
            '交通' => ['捷運', '高鐵', '油錢', '停車', '計程車'],
            '薪資' => ['薪資', '獎金'],
        ];

        foreach ($keywordCategories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($subject, $keyword)) {
                    return $category;
                }
            }
        }

        $category = $this->optionalString($data, 'category', 80);
        return in_array($category, self::CATEGORIES, true) ? $category : '其他';
    }

    /** @param array<string, mixed> $data */
    private function positiveNumber(array $data, string $key): float
    {
        $value = $this->nonNegativeNumber($data, $key);
        if ($value <= 0) {
            throw new AiParseException('AI 回傳數值必須大於 0。', 'validation_failed', 'invalid_positive_number');
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    private function nonNegativeNumber(array $data, string $key): float
    {
        $value = $data[$key] ?? null;
        if (!array_key_exists($key, $data) || $value === null || (is_string($value) && trim($value) === '')) {
            throw new AiParseException(
                $this->missingNumberMessage($key),
                'validation_failed',
                'invalid_number'
            );
        }

        $number = $this->normalizeNumber($value);
        if ($number === null) {
            throw new AiParseException('AI 回傳數值格式不正確。', 'validation_failed', 'invalid_number');
        }
        if ($number < 0 || $number > 9999999999) {
            throw new AiParseException('AI 回傳數值超出允許範圍。', 'validation_failed', 'number_out_of_range');
        }
        return round($number, 2);
    }

    private function normalizeNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim(strtr($value, [
            '０' => '0',
            '１' => '1',
            '２' => '2',
            '３' => '3',
            '４' => '4',
            '５' => '5',
            '６' => '6',
            '７' => '7',
            '８' => '8',
            '９' => '9',
            '，' => ',',
            '．' => '.',
        ]));
        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/[\s,]/u', '', $normalized);
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        $normalized = preg_replace('/^(?:NT\$|NTD|TWD|新台幣|台幣|\$)/iu', '', $normalized);
        $normalized = preg_replace('/(?:NT\$|NTD|TWD|元|圓|塊|\$)$/iu', '', is_string($normalized) ? $normalized : '');
        if (!is_string($normalized) || !preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function missingNumberMessage(string $key): string
    {
        return match ($key) {
            'amount' => 'AI 未解析到金額，請改成「早餐 1元 現金」這類格式。',
            'overtime_hours' => 'AI 未解析到加班時數，請改成「今天加班 2 小時」這類格式。',
            default => 'AI 未解析到必要數值，請補上明確數字後再送出。',
        };
    }

    /** @param array<string, mixed> $data */
    private function optionalNonNegativeNumber(array $data, string $key): float
    {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return 0.0;
        }

        return $this->nonNegativeNumber($data, $key);
    }
}
