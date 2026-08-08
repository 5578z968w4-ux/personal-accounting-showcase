<?php

declare(strict_types=1);

final class AiInputDateResolver
{
    public function resolve(string $inputText, ?DateTimeImmutable $now = null): ?string
    {
        $timezone = new DateTimeZone('Asia/Taipei');
        $now ??= new DateTimeImmutable('now', $timezone);

        if (preg_match('/(?<!\d)(\d{4})[\/.-](\d{1,2})[\/.-](\d{1,2})(?!\d)/u', $inputText, $matches) === 1) {
            return $this->validDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        if (preg_match('/(?<!\d)(\d{1,2})[\/.](\d{1,2})(?!\d)/u', $inputText, $matches) === 1) {
            return $this->validDate(
                (int) $now->format('Y'),
                (int) $matches[1],
                (int) $matches[2]
            );
        }

        if (str_contains($inputText, '前天')) {
            return $now->modify('-2 days')->format('Y-m-d');
        }
        if (str_contains($inputText, '昨天') || str_contains($inputText, '昨日')) {
            return $now->modify('-1 day')->format('Y-m-d');
        }
        if (str_contains($inputText, '今天') || str_contains($inputText, '今日')) {
            return $now->format('Y-m-d');
        }

        return null;
    }

    private function validDate(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
