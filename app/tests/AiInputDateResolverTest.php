<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/AiInputDateResolver.php';

function date_assert(?string $actual, ?string $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message . ': expected ' . $expected . ', got ' . $actual);
    }
}

$resolver = new AiInputDateResolver();
$now = new DateTimeImmutable('2026-06-09 14:00:00', new DateTimeZone('Asia/Taipei'));

date_assert($resolver->resolve('6/8 早餐100現金', $now), '2026-06-08', 'M/D date mismatch');
date_assert($resolver->resolve('2025/12/31 晚餐100現金', $now), '2025-12-31', 'Full date mismatch');
date_assert($resolver->resolve('昨天晚餐100現金', $now), '2026-06-08', 'Yesterday mismatch');
date_assert($resolver->resolve('前天加班2H', $now), '2026-06-07', 'Day before yesterday mismatch');
date_assert($resolver->resolve('今天早餐100現金', $now), '2026-06-09', 'Today mismatch');
date_assert($resolver->resolve('早餐100現金', $now), null, 'Missing date must remain unresolved');
date_assert($resolver->resolve('7-11 300 展示方式 C', $now), null, '7-11 store name must not be treated as date');
date_assert($resolver->resolve('2/31 早餐100現金', $now), null, 'Invalid date must be rejected');

echo "AiInputDateResolverTest passed\n";
