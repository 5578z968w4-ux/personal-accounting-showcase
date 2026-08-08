<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/AiLogDisplayHelper.php';

function ai_log_display_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$expenseRows = ai_log_summary_rows(json_encode([
    'type' => 'expense',
    'fields' => [
        'record_date' => '2026-06-21',
        'item' => '早餐',
        'amount' => 80,
        'category' => '餐飲',
        'payment_method' => '現金',
        'accounting_month' => '2026/07',
    ],
], JSON_UNESCAPED_UNICODE));
ai_log_display_assert($expenseRows['類型'] === '支出', 'Expense type label mismatch');
ai_log_display_assert($expenseRows['名稱'] === '早餐', 'Expense name mismatch');
ai_log_display_assert($expenseRows['金額'] === '80 元', 'Expense amount mismatch');
ai_log_display_assert($expenseRows['付款方式'] === '現金', 'Expense payment method mismatch');
ai_log_display_assert($expenseRows['帳單月份'] === '2026/07', 'Expense accounting month mismatch');

$incomeRows = ai_log_summary_rows(json_encode([
    'type' => 'income',
    'fields' => [
        'record_date' => '2026-06-21',
        'source_name' => '薪資',
        'amount' => 50000,
        'category' => '薪資',
        'account_name' => '',
        'accounting_month' => '2026/06',
    ],
], JSON_UNESCAPED_UNICODE));
ai_log_display_assert($incomeRows['類型'] === '收入', 'Income type label mismatch');
ai_log_display_assert($incomeRows['名稱'] === '薪資', 'Income name mismatch');
ai_log_display_assert($incomeRows['帳戶'] === '未指定帳戶', 'Income empty account label mismatch');

$overtimeRows = ai_log_summary_rows(json_encode([
    'type' => 'overtime',
    'fields' => [
        'work_date' => '2026-06-21',
        'overtime_hours' => 3,
    ],
], JSON_UNESCAPED_UNICODE));
ai_log_display_assert($overtimeRows['類型'] === '加班', 'Overtime type label mismatch');
ai_log_display_assert($overtimeRows['時數'] === '3 小時', 'Overtime hours mismatch');

$leaveRows = ai_log_summary_rows(json_encode([
    'type' => 'leave',
    'fields' => [
        'leave_date' => '2026-06-21',
        'leave_type' => '特休',
        'leave_days' => 0,
        'leave_hours' => 4,
        'note' => '下午',
    ],
], JSON_UNESCAPED_UNICODE));
ai_log_display_assert($leaveRows['類型'] === '請假', 'Leave type label mismatch');
ai_log_display_assert($leaveRows['天數'] === '0.5 天', 'Leave total days mismatch');
ai_log_display_assert($leaveRows['時數'] === '4 小時', 'Leave hours mismatch');

$fullDayLeaveRows = ai_log_summary_rows(json_encode([
    'type' => 'leave',
    'fields' => [
        'leave_date' => '2026-06-22',
        'leave_type' => '特休',
        'leave_days' => 1,
        'leave_hours' => 0,
    ],
], JSON_UNESCAPED_UNICODE));
ai_log_display_assert(!isset($fullDayLeaveRows['時數']), 'Zero leave hours must be hidden');

ai_log_display_assert(ai_log_summary_rows('not-json') === [], 'Invalid JSON must not render summary');
ai_log_display_assert(ai_log_source_label('quick_pwa') === 'Quick Entry / PWA', 'Quick source label mismatch');
ai_log_display_assert(ai_log_source_label('ios_shortcut') === 'iOS Shortcut', 'iOS Shortcut source label mismatch');
ai_log_display_assert(ai_log_source_label('shortcut_api') === 'Shortcut API', 'Shortcut API source label mismatch');
ai_log_display_assert(ai_log_source_label('admin_ai_input') === '後台 AI 快速輸入', 'Admin AI source label mismatch');
ai_log_display_assert(ai_log_source_label('web') === 'AI 快速輸入', 'Web source label mismatch');
ai_log_display_assert(ai_log_source_label('quick_entry_check') === 'Quick Entry 驗收腳本', 'Quick Entry check source label mismatch');
ai_log_display_assert(ai_log_source_label('') === '-', 'Empty source label mismatch');

echo "AiLogDisplayHelperTest passed\n";
