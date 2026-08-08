<?php

declare(strict_types=1);

require_once __DIR__ . '/html.php';

/** @return array<string, string> */
function ai_log_summary_rows(?string $parsedJson, ?string $fallbackType = null): array
{
    $parsedJson = trim((string) $parsedJson);
    if ($parsedJson === '') {
        return [];
    }

    try {
        $data = json_decode($parsedJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }

    if (!is_array($data)) {
        return [];
    }

    $type = trim((string) ($data['type'] ?? $fallbackType ?? ''));
    $fields = $data['fields'] ?? [];
    if (!is_array($fields)) {
        $fields = [];
    }

    $rows = [];
    ai_log_add_row($rows, '類型', ai_log_type_label($type));

    return match ($type) {
        'expense' => ai_log_expense_rows($rows, $fields),
        'income' => ai_log_income_rows($rows, $fields),
        'overtime' => ai_log_overtime_rows($rows, $fields),
        'leave' => ai_log_leave_rows($rows, $fields),
        default => $rows,
    };
}

function ai_log_type_label(?string $type): string
{
    return match (trim((string) $type)) {
        'expense' => '支出',
        'income' => '收入',
        'overtime' => '加班',
        'leave' => '請假',
        default => trim((string) $type),
    };
}

function ai_log_source_label(?string $source): string
{
    return match (trim((string) $source)) {
        '' => '-',
        'quick_pwa' => 'Quick Entry / PWA',
            'ios_shortcut' => 'iOS Shortcut',
            'shortcut_api' => 'Shortcut API',
            'admin_ai_input' => '後台 AI 快速輸入',
            'web' => 'AI 快速輸入',
        'quick_entry_check' => 'Quick Entry 驗收腳本',
        'stage2_check' => 'AI 驗收腳本',
        default => trim((string) $source),
    };
}

/** @param array<string, string> $rows @param array<string, mixed> $fields @return array<string, string> */
function ai_log_expense_rows(array $rows, array $fields): array
{
    ai_log_add_row($rows, '日期', $fields['record_date'] ?? null);
    ai_log_add_row($rows, '名稱', $fields['item'] ?? null);
    ai_log_add_amount_row($rows, '金額', $fields['amount'] ?? null, '元');
    ai_log_add_row($rows, '分類', $fields['category'] ?? null);
    ai_log_add_row($rows, '付款方式', $fields['payment_method'] ?? null);
    ai_log_add_row($rows, '帳單月份', $fields['accounting_month'] ?? null);

    return $rows;
}

/** @param array<string, string> $rows @param array<string, mixed> $fields @return array<string, string> */
function ai_log_income_rows(array $rows, array $fields): array
{
    ai_log_add_row($rows, '日期', $fields['record_date'] ?? null);
    ai_log_add_row($rows, '名稱', $fields['source_name'] ?? null);
    ai_log_add_amount_row($rows, '金額', $fields['amount'] ?? null, '元');
    ai_log_add_row($rows, '分類', $fields['category'] ?? null);
    ai_log_add_row(
        $rows,
        '帳戶',
        trim((string) ($fields['account_name'] ?? '')) !== '' ? $fields['account_name'] : '未指定帳戶'
    );
    ai_log_add_row($rows, '帳單月份', $fields['accounting_month'] ?? null);

    return $rows;
}

/** @param array<string, string> $rows @param array<string, mixed> $fields @return array<string, string> */
function ai_log_overtime_rows(array $rows, array $fields): array
{
    ai_log_add_row($rows, '日期', $fields['work_date'] ?? null);
    ai_log_add_amount_row($rows, '時數', $fields['overtime_hours'] ?? null, '小時');

    return $rows;
}

/** @param array<string, string> $rows @param array<string, mixed> $fields @return array<string, string> */
function ai_log_leave_rows(array $rows, array $fields): array
{
    ai_log_add_row($rows, '日期', $fields['leave_date'] ?? null);
    ai_log_add_row($rows, '假別', $fields['leave_type'] ?? null);
    ai_log_add_amount_row($rows, '天數', ai_log_leave_total_days($fields), '天');
    if (is_numeric($fields['leave_hours'] ?? null) && (float) $fields['leave_hours'] !== 0.0) {
        ai_log_add_amount_row($rows, '時數', $fields['leave_hours'], '小時');
    }
    ai_log_add_row($rows, '備註', $fields['note'] ?? null);

    return $rows;
}

/** @param array<string, string> $rows */
function ai_log_add_row(array &$rows, string $label, mixed $value): void
{
    $value = trim((string) ($value ?? ''));
    if ($value !== '') {
        $rows[$label] = $value;
    }
}

/** @param array<string, string> $rows */
function ai_log_add_amount_row(array &$rows, string $label, mixed $value, string $unit): void
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return;
    }

    $rows[$label] = format_number_clean($value) . ' ' . $unit;
}

/** @param array<string, mixed> $fields */
function ai_log_leave_total_days(array $fields): float|int|string|null
{
    if (isset($fields['total_leave_days']) && is_numeric($fields['total_leave_days'])) {
        return $fields['total_leave_days'];
    }

    $days = $fields['leave_days'] ?? null;
    $hours = $fields['leave_hours'] ?? null;
    if (!is_numeric($days) && !is_numeric($hours)) {
        return null;
    }

    return round((float) ($days ?? 0) + ((float) ($hours ?? 0) / 8), 2);
}
