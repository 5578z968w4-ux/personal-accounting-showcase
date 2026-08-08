<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/AiResponseValidator.php';

function validator_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$validator = new AiResponseValidator();
$leave = $validator->validate(json_encode([
    'type' => 'leave',
    'leave_date' => '2026-06-09',
    'leave_type' => '特休',
    'leave_days' => 1,
    'leave_hours' => null,
    'note' => '',
], JSON_UNESCAPED_UNICODE), 'auto');

validator_assert($leave['type'] === 'leave', 'Leave type mismatch');
validator_assert($leave['fields']['leave_days'] === 1.0, 'Leave days mismatch');
validator_assert($leave['fields']['leave_hours'] === 0.0, 'Null leave hours must normalize to zero');

$unspecifiedLeaveType = $validator->validate(json_encode([
    'type' => 'leave',
    'leave_date' => '2026-06-10',
    'leave_type' => null,
    'leave_days' => null,
    'leave_hours' => 2,
    'note' => '',
], JSON_UNESCAPED_UNICODE), 'leave');
validator_assert($unspecifiedLeaveType['fields']['leave_type'] === '', 'Missing leave type must remain empty');
validator_assert($unspecifiedLeaveType['fields']['leave_hours'] === 2.0, 'Leave hours mismatch');

$emptyCategory = $validator->validate(json_encode([
    'type' => 'expense',
    'record_date' => '2026-06-09',
    'item' => '未知項目',
    'amount' => 100,
    'payment_method' => '現金',
    'category' => '',
], JSON_UNESCAPED_UNICODE), 'expense');
validator_assert($emptyCategory['fields']['category'] === '其他', 'Empty category must normalize to other');

$invalidCategory = $validator->validate(json_encode([
    'type' => 'expense',
    'record_date' => '2026-06-09',
    'item' => '未知項目',
    'amount' => 100,
    'payment_method' => '現金',
    'category' => '遊戲主機',
], JSON_UNESCAPED_UNICODE), 'expense');
validator_assert($invalidCategory['fields']['category'] === '其他', 'Invalid category must normalize to other');

foreach ([
    ['7-11', '其他', '餐飲'],
    ['PS5', '娛樂', '3C'],
    ['早餐', '', '餐飲'],
    ['高鐵', '生活', '交通'],
    ['年終獎金', '其他', '薪資'],
] as [$item, $aiCategory, $expectedCategory]) {
    $result = $validator->validate(json_encode([
        'type' => 'expense',
        'record_date' => '2026-06-09',
        'item' => $item,
        'amount' => 100,
        'payment_method' => '現金',
        'category' => $aiCategory,
    ], JSON_UNESCAPED_UNICODE), 'expense');
    validator_assert(
        $result['fields']['category'] === $expectedCategory,
        $item . ' category mismatch'
    );
}

foreach ([
    [1, 1.0],
    ['1', 1.0],
    ['1.0', 1.0],
    ['1元', 1.0],
    ['NT$1', 1.0],
    ['1,234.50元', 1234.5],
] as [$amount, $expectedAmount]) {
    $result = $validator->validate(json_encode([
        'type' => 'expense',
        'record_date' => '2026-06-09',
        'item' => '測試支出',
        'amount' => $amount,
        'payment_method' => '現金',
        'category' => '其他',
    ], JSON_UNESCAPED_UNICODE), 'expense');
    validator_assert($result['fields']['amount'] === $expectedAmount, 'Amount normalization mismatch');
}

$invalidNumberRejected = false;
try {
    $validator->validate(json_encode([
        'type' => 'expense',
        'record_date' => '2026-06-09',
        'item' => '測試支出',
        'amount' => '一元',
        'payment_method' => '現金',
        'category' => '其他',
    ], JSON_UNESCAPED_UNICODE), 'expense');
} catch (AiParseException $exception) {
    $invalidNumberRejected = $exception->errorCode() === 'invalid_number';
}
validator_assert($invalidNumberRejected, 'Invalid number text must still be rejected');

$clearShortcutFormat = $validator->validate(json_encode([
    'type' => 'expense',
    'record_date' => '2026-06-09',
    'item' => '早餐',
    'amount' => '1元',
    'payment_method' => '現金',
    'category' => '餐飲',
], JSON_UNESCAPED_UNICODE), 'expense');
validator_assert($clearShortcutFormat['fields']['item'] === '早餐', 'Clear shortcut item mismatch');
validator_assert($clearShortcutFormat['fields']['amount'] === 1.0, 'Clear shortcut amount mismatch');
validator_assert($clearShortcutFormat['fields']['payment_method'] === '現金', 'Clear shortcut payment method mismatch');

$legacyGasShorthand = $validator->validate(json_encode([
    'type' => 'expense',
    'record_date' => '2026-06-09',
    'item' => '早餐',
    'amount' => 80,
    'payment_method' => '現金',
    'category' => '餐飲',
], JSON_UNESCAPED_UNICODE), 'expense');
validator_assert($legacyGasShorthand['fields']['item'] === '早餐', 'Legacy GAS shorthand item mismatch');
validator_assert($legacyGasShorthand['fields']['amount'] === 80.0, 'Legacy GAS shorthand amount mismatch');
validator_assert($legacyGasShorthand['fields']['payment_method'] === '現金', 'Legacy GAS shorthand payment method mismatch');

$aiAccountingMonthIgnored = $validator->validate(json_encode([
    'type' => 'expense',
    'record_date' => '2026-06-29',
    'item' => '早餐',
    'amount' => 80,
    'payment_method' => '現金',
    'accounting_month' => '2026/07',
    'category' => '餐飲',
], JSON_UNESCAPED_UNICODE), 'expense');
validator_assert(
    !array_key_exists('accounting_month', $aiAccountingMonthIgnored['fields']),
    'AI accounting_month must not be trusted by AiResponseValidator'
);

$missingAmountRejected = false;
try {
    $validator->validate(json_encode([
        'type' => 'expense',
        'record_date' => '2026-06-09',
        'item' => '測試支出 1',
        'amount' => null,
        'payment_method' => '現金',
        'category' => '其他',
    ], JSON_UNESCAPED_UNICODE), 'expense');
} catch (AiParseException $exception) {
    $missingAmountRejected = $exception->errorCode() === 'invalid_number'
        && str_contains($exception->getMessage(), 'AI 未解析到金額')
        && str_contains($exception->getMessage(), '早餐 1元 現金');
}
validator_assert($missingAmountRejected, 'Missing amount must return an actionable amount hint');

$invalidDateRejected = false;
try {
    $validator->validate(json_encode([
        'type' => 'overtime',
        'work_date' => '2026-02-31',
        'overtime_hours' => 2,
    ]), 'overtime');
} catch (AiParseException $exception) {
    $invalidDateRejected = $exception->errorCode() === 'invalid_date';
}
validator_assert($invalidDateRejected, 'Invalid date must be rejected');

echo "AiResponseValidatorTest passed\n";
