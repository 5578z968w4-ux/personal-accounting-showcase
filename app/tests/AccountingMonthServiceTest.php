<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/AccountingMonthService.php';

function accounting_month_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$cash = [
    'name' => '現金',
    'settlement_start_day' => 1,
    'settlement_end_day' => 31,
];
$sampleMethod = [
    'name' => '展示方式 C',
    'settlement_start_day' => 7,
    'settlement_end_day' => 6,
];

accounting_month_assert(
    AccountingMonthService::fromRecordDate('2026-06-27') === '2026/06',
    'Record-date month should use the record date.'
);
accounting_month_assert(
    AccountingMonthService::forPaymentMethod('2026-06-27', $cash) === '2026/06',
    'Cash without a billing cycle should stay in the record-date month.'
);
accounting_month_assert(
    AccountingMonthService::forPaymentMethod('2026-06-29', $cash) === '2026/06',
    'Cash on 2026-06-29 should stay in the June transaction month.'
);
accounting_month_assert(
    AccountingMonthService::forPaymentMethod('2026-06-27', $sampleMethod) === '2026/07',
    'Credit-card billing cycle should move 2026-06-27 to 2026/07.'
);
accounting_month_assert(
    AccountingMonthService::forPaymentMethod('2026-06-29', $sampleMethod) === '2026/07',
    'Credit-card billing cycle should still move 2026-06-29 to 2026/07.'
);
accounting_month_assert(
    AccountingMonthService::forPaymentMethod('2026-06-06', $sampleMethod) === '2026/06',
    'Credit-card dates before the cycle start should stay in the current billing month.'
);
accounting_month_assert(
    AccountingMonthService::hasBillingCycle($cash) === false,
    '1-31 payment methods should be treated as no billing cycle.'
);
accounting_month_assert(
    AccountingMonthService::hasBillingCycle($sampleMethod) === true,
    'Non-calendar payment methods should be treated as billing-cycle methods.'
);

echo "AccountingMonthServiceTest passed\n";
