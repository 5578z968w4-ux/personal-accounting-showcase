<?php

declare(strict_types=1);

function valid_date_string(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value, new DateTimeZone('Asia/Taipei'));
    return $date !== false && $date->format('Y-m-d') === $value;
}

function normalize_month(string $value): string
{
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
        return str_replace('-', '/', $value);
    }
    if (preg_match('/^\d{4}\/\d{2}$/', $value) === 1) {
        return $value;
    }
    return '';
}

function month_for_input(string $value): string
{
    return str_replace('/', '-', $value);
}

function month_from_date(string $recordDate): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $recordDate, new DateTimeZone('Asia/Taipei'));
    if (!$date) {
        throw new InvalidArgumentException('invalid date');
    }

    return $date->format('Y/m');
}

function valid_money_string(string $value): bool
{
    return preg_match('/^\d{1,10}(\.\d{1,2})?$/', trim($value)) === 1;
}

function valid_hours_string(string $value): bool
{
    return preg_match('/^\d{1,3}(\.\d{1,2})?$/', trim($value)) === 1;
}
