<?php

declare(strict_types=1);

final class AccountingMonthService
{
    public static function fromRecordDate(string $recordDate): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $recordDate, new DateTimeZone('Asia/Taipei'));
        if (!$date || $date->format('Y-m-d') !== $recordDate) {
            throw new InvalidArgumentException('invalid date');
        }

        return $date->format('Y/m');
    }

    public static function calculate(string $recordDate, int $cycleStartDay, int $cycleEndDay): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $recordDate, new DateTimeZone('Asia/Taipei'));
        if (!$date) {
            throw new InvalidArgumentException('invalid date');
        }

        $day = (int) $date->format('j');
        if ($cycleStartDay < 1 || $cycleStartDay > 31 || $cycleEndDay < 1 || $cycleEndDay > 31) {
            throw new InvalidArgumentException('invalid cycle');
        }

        if ($day >= $cycleStartDay) {
            return $date->modify('first day of next month')->format('Y/m');
        }

        return $date->format('Y/m');
    }

    /**
     * @param array<string, mixed> $paymentMethod
     */
    public static function forPaymentMethod(string $recordDate, array $paymentMethod): string
    {
        if (!self::hasBillingCycle($paymentMethod)) {
            return self::fromRecordDate($recordDate);
        }

        return self::calculate(
            $recordDate,
            (int) $paymentMethod['settlement_start_day'],
            (int) $paymentMethod['settlement_end_day']
        );
    }

    /**
     * @param array<string, mixed> $paymentMethod
     */
    public static function hasBillingCycle(array $paymentMethod): bool
    {
        $startDay = (int) ($paymentMethod['settlement_start_day'] ?? 0);
        $endDay = (int) ($paymentMethod['settlement_end_day'] ?? 0);

        if ($startDay < 1 || $startDay > 31 || $endDay < 1 || $endDay > 31) {
            return false;
        }

        return !($startDay === 1 && $endDay === 31);
    }
}
