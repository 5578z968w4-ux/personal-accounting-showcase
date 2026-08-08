<?php

declare(strict_types=1);

require_once __DIR__ . '/accounting_month_repair_preview.php';

/**
 * @return array{confirm: bool, help: bool}
 */
function accounting_month_repair_apply_parse_args(array $argv): array
{
    $options = [
        'confirm' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--confirm-production-accounting-month-repair') {
            $options['confirm'] = true;
        }
    }

    return $options;
}

function accounting_month_repair_apply_usage(): void
{
    fwrite(STDOUT, "Accounting month production repair\n\n");
    fwrite(STDOUT, "Usage:\n");
    fwrite(
        STDOUT,
        "  APP_ENV=production DB_DATABASE=personal_accounting php app/scripts/accounting_month_repair_apply.php --confirm-production-accounting-month-repair\n\n"
    );
    fwrite(STDOUT, "Options:\n");
    fwrite(STDOUT, "  --confirm-production-accounting-month-repair  Required. Without this flag the tool refuses to run.\n");
}

function accounting_month_repair_apply_column_exists(PDO $pdo, string $table, string $column): bool
{
    return accounting_month_repair_preview_column_exists($pdo, $table, $column);
}

/**
 * @return list<array<string, mixed>>
 */
function accounting_month_repair_apply_payment_methods(PDO $pdo): array
{
    $columns = ['id', 'name', 'settlement_start_day', 'settlement_end_day'];
    foreach (['cycle_start_day', 'cycle_end_day', 'is_active', 'deleted_at'] as $optionalColumn) {
        if (accounting_month_repair_apply_column_exists($pdo, 'payment_methods', $optionalColumn)) {
            $columns[] = $optionalColumn;
        }
    }

    $statement = $pdo->query(
        'SELECT ' . implode(', ', array_map('accounting_month_repair_preview_identifier', $columns)) . '
         FROM payment_methods
         ORDER BY id'
    );

    return $statement->fetchAll();
}

/**
 * @return array<string, mixed>
 */
function accounting_month_repair_apply_unique_cash_method(PDO $pdo): array
{
    $columns = ['id', 'name', 'settlement_start_day', 'settlement_end_day'];
    foreach (['cycle_start_day', 'cycle_end_day'] as $optionalColumn) {
        if (accounting_month_repair_apply_column_exists($pdo, 'payment_methods', $optionalColumn)) {
            $columns[] = $optionalColumn;
        }
    }

    $statement = $pdo->prepare(
        'SELECT ' . implode(', ', array_map('accounting_month_repair_preview_identifier', $columns)) . '
         FROM payment_methods
         WHERE name = :name'
    );
    $statement->execute(['name' => '現金']);
    $rows = $statement->fetchAll();

    if (count($rows) === 0) {
        throw new RuntimeException('Accounting month repair refused: cash payment method not found.');
    }
    if (count($rows) > 1) {
        throw new RuntimeException('Accounting month repair refused: multiple cash payment methods found.');
    }

    return $rows[0];
}

/**
 * @return array<string, mixed>
 */
function accounting_month_repair_apply_update_cash_method(PDO $pdo): array
{
    $cash = accounting_month_repair_apply_unique_cash_method($pdo);
    $before = [
        'id' => (int) $cash['id'],
        'name' => (string) $cash['name'],
        'settlement_start_day' => (int) $cash['settlement_start_day'],
        'settlement_end_day' => (int) $cash['settlement_end_day'],
        'cycle_start_day' => array_key_exists('cycle_start_day', $cash) ? (int) $cash['cycle_start_day'] : null,
        'cycle_end_day' => array_key_exists('cycle_end_day', $cash) ? (int) $cash['cycle_end_day'] : null,
    ];

    $set = [
        'settlement_start_day = :settlement_start_day',
        'settlement_end_day = :settlement_end_day',
    ];
    $hasCycleStartDay = accounting_month_repair_apply_column_exists($pdo, 'payment_methods', 'cycle_start_day');
    $hasCycleEndDay = accounting_month_repair_apply_column_exists($pdo, 'payment_methods', 'cycle_end_day');
    if ($hasCycleStartDay) {
        $set[] = 'cycle_start_day = :cycle_start_day';
    }
    if ($hasCycleEndDay) {
        $set[] = 'cycle_end_day = :cycle_end_day';
    }
    if (accounting_month_repair_apply_column_exists($pdo, 'payment_methods', 'updated_at')) {
        $set[] = 'updated_at = CURRENT_TIMESTAMP';
    }

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'UPDATE payment_methods
             SET ' . implode(', ', $set) . '
             WHERE id = :id
               AND name = :name'
        );
        $params = [
            'settlement_start_day' => 1,
            'settlement_end_day' => 31,
            'id' => $before['id'],
            'name' => '現金',
        ];
        if ($hasCycleStartDay) {
            $params['cycle_start_day'] = 1;
        }
        if ($hasCycleEndDay) {
            $params['cycle_end_day'] = 31;
        }
        $statement->execute($params);

        $after = accounting_month_repair_apply_unique_cash_method($pdo);
        if ((int) $after['settlement_start_day'] !== 1 || (int) $after['settlement_end_day'] !== 31) {
            throw new RuntimeException('Accounting month repair failed: cash payment method verification failed.');
        }

        $pdo->commit();

        return [
            'before' => $before,
            'after' => [
                'id' => (int) $after['id'],
                'name' => (string) $after['name'],
                'settlement_start_day' => (int) $after['settlement_start_day'],
                'settlement_end_day' => (int) $after['settlement_end_day'],
                'cycle_start_day' => array_key_exists('cycle_start_day', $after) ? (int) $after['cycle_start_day'] : null,
                'cycle_end_day' => array_key_exists('cycle_end_day', $after) ? (int) $after['cycle_end_day'] : null,
            ],
            'updated' => (
                $before['settlement_start_day'] !== 1
                || $before['settlement_end_day'] !== 31
                || ($before['cycle_start_day'] !== null && $before['cycle_start_day'] !== 1)
                || ($before['cycle_end_day'] !== null && $before['cycle_end_day'] !== 31)
            ),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @return array<string, int>
 */
function accounting_month_repair_apply_active_source_counts(PDO $pdo): array
{
    $metadata = accounting_month_repair_preview_expense_metadata($pdo);
    $statement = $pdo->query(
        'SELECT COALESCE(NULLIF(source, \'\'), \'(blank)\') AS source_name, COUNT(*) AS row_count
         FROM expenses e
         WHERE ' . $metadata['where'] . '
         GROUP BY COALESCE(NULLIF(source, \'\'), \'(blank)\')
         ORDER BY source_name'
    );

    $counts = [];
    foreach ($statement->fetchAll() as $row) {
        $counts[(string) $row['source_name']] = (int) $row['row_count'];
    }

    return $counts;
}

/**
 * @return array<string, mixed>
 */
function accounting_month_repair_apply_expenses(PDO $pdo, ?callable $afterEachUpdate = null): array
{
    $before = accounting_month_repair_preview($pdo, PHP_INT_MAX);
    if ((int) $before['mismatch_count'] === 0) {
        return [
            'before' => $before,
            'updated_count' => 0,
            'updated_amount_total' => 0.0,
            'updated_groups' => [],
            'after' => $before,
        ];
    }

    $set = ['accounting_month = :accounting_month'];
    if (accounting_month_repair_apply_column_exists($pdo, 'expenses', 'updated_at')) {
        $set[] = 'updated_at = CURRENT_TIMESTAMP';
    }

    $updatedCount = 0;
    $updatedAmountTotal = 0.0;
    $updatedGroups = [];
    $statement = $pdo->prepare(
        'UPDATE expenses
         SET ' . implode(', ', $set) . '
         WHERE id = :id'
    );

    $pdo->beginTransaction();
    try {
        foreach ($before['samples'] as $row) {
            $statement->execute([
                'accounting_month' => $row['expected_accounting_month'],
                'id' => $row['id'],
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Accounting month repair failed: unexpected expense update count.');
            }

            $updatedCount++;
            $amount = (float) $row['amount'];
            $updatedAmountTotal += $amount;
            accounting_month_repair_preview_add_group(
                $updatedGroups,
                $row['payment_method'] . '|' . $row['current_accounting_month'] . '|' . $row['expected_accounting_month'],
                [
                    'payment_method' => $row['payment_method'],
                    'current_accounting_month' => $row['current_accounting_month'],
                    'expected_accounting_month' => $row['expected_accounting_month'],
                ],
                $amount
            );

            if ($afterEachUpdate !== null) {
                $afterEachUpdate($row);
            }
        }

        if ($updatedCount !== (int) $before['mismatch_count']) {
            throw new RuntimeException('Accounting month repair refused: sample limit did not include all mismatches.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'before' => $before,
        'updated_count' => $updatedCount,
        'updated_amount_total' => $updatedAmountTotal,
        'updated_groups' => array_values($updatedGroups),
        'after' => accounting_month_repair_preview($pdo, 50),
    ];
}

/**
 * @param array<string, string>|null $gateOverride
 * @return array<string, mixed>
 */
function accounting_month_repair_apply_run(PDO $pdo, bool $confirmed, ?array $gateOverride = null): array
{
    if (!$confirmed) {
        throw new RuntimeException('Accounting month repair refused: missing --confirm-production-accounting-month-repair.');
    }

    $gate = accounting_month_repair_preview_production_gate($pdo, $gateOverride ?? []);
    $paymentMethodsBefore = accounting_month_repair_apply_payment_methods($pdo);
    $sourceCountsBefore = accounting_month_repair_apply_active_source_counts($pdo);
    $cashRepair = accounting_month_repair_apply_update_cash_method($pdo);
    $previewAfterCash = accounting_month_repair_preview($pdo, 50);
    $expenseRepair = accounting_month_repair_apply_expenses($pdo);
    $sourceCountsAfter = accounting_month_repair_apply_active_source_counts($pdo);

    if ($sourceCountsBefore !== $sourceCountsAfter) {
        throw new RuntimeException('Accounting month repair failed: active expense source counts changed unexpectedly.');
    }

    return [
        'gate' => $gate,
        'payment_methods_before' => $paymentMethodsBefore,
        'cash_repair' => $cashRepair,
        'preview_after_cash' => $previewAfterCash,
        'expense_repair' => $expenseRepair,
        'source_counts_before' => $sourceCountsBefore,
        'source_counts_after' => $sourceCountsAfter,
        'payment_methods_after' => accounting_month_repair_apply_payment_methods($pdo),
        'final_preview' => accounting_month_repair_preview($pdo, 50),
    ];
}

/**
 * @param array<string, mixed> $result
 */
function accounting_month_repair_apply_print(array $result): void
{
    fwrite(STDOUT, "Accounting month production repair\n");
    fwrite(STDOUT, "mode=confirmed-production-repair\n");
    fwrite(STDOUT, 'APP_ENV=' . $result['gate']['APP_ENV'] . "\n");
    fwrite(STDOUT, 'DB_DATABASE=' . $result['gate']['DB_DATABASE'] . "\n");
    fwrite(STDOUT, 'SELECT_DATABASE=' . $result['gate']['SELECT_DATABASE'] . "\n\n");

    fwrite(STDOUT, "Payment methods before:\n");
    foreach ($result['payment_methods_before'] as $row) {
        fwrite(
            STDOUT,
            sprintf(
                "- id=%d name=%s settlement_start_day=%d settlement_end_day=%d cycle_start_day=%s cycle_end_day=%s is_active=%s deleted_at=%s\n",
                (int) $row['id'],
                (string) $row['name'],
                (int) $row['settlement_start_day'],
                (int) $row['settlement_end_day'],
                array_key_exists('cycle_start_day', $row) ? (string) $row['cycle_start_day'] : 'n/a',
                array_key_exists('cycle_end_day', $row) ? (string) $row['cycle_end_day'] : 'n/a',
                array_key_exists('is_active', $row) ? (string) $row['is_active'] : 'n/a',
                array_key_exists('deleted_at', $row) ? (string) ($row['deleted_at'] ?? 'NULL') : 'n/a'
            )
        );
    }

    fwrite(
        STDOUT,
        sprintf(
            "\nCash repair: before=%d~%d cycle=%s~%s after=%d~%d cycle=%s~%s updated=%s\n",
            $result['cash_repair']['before']['settlement_start_day'],
            $result['cash_repair']['before']['settlement_end_day'],
            $result['cash_repair']['before']['cycle_start_day'] ?? 'n/a',
            $result['cash_repair']['before']['cycle_end_day'] ?? 'n/a',
            $result['cash_repair']['after']['settlement_start_day'],
            $result['cash_repair']['after']['settlement_end_day'],
            $result['cash_repair']['after']['cycle_start_day'] ?? 'n/a',
            $result['cash_repair']['after']['cycle_end_day'] ?? 'n/a',
            $result['cash_repair']['updated'] ? 'yes' : 'no'
        )
    );

    fwrite(STDOUT, "\nPreview after cash payment-method repair:\n");
    fwrite(STDOUT, 'active_expenses_count=' . (string) $result['preview_after_cash']['active_expenses_count'] . "\n");
    fwrite(STDOUT, 'mismatch_count=' . (string) $result['preview_after_cash']['mismatch_count'] . "\n");
    fwrite(
        STDOUT,
        'mismatch_amount_total=' . number_format((float) $result['preview_after_cash']['mismatch_amount_total'], 2, '.', '') . "\n"
    );

    fwrite(STDOUT, "\nExpense repair:\n");
    fwrite(STDOUT, 'updated_count=' . (string) $result['expense_repair']['updated_count'] . "\n");
    fwrite(
        STDOUT,
        'updated_amount_total=' . number_format((float) $result['expense_repair']['updated_amount_total'], 2, '.', '') . "\n"
    );
    foreach ($result['expense_repair']['updated_groups'] as $row) {
        fwrite(
            STDOUT,
            sprintf(
                "- payment_method=%s current=%s expected=%s count=%d amount_total=%s\n",
                $row['payment_method'],
                $row['current_accounting_month'],
                $row['expected_accounting_month'],
                $row['count'],
                number_format((float) $row['amount_total'], 2, '.', '')
            )
        );
    }

    fwrite(STDOUT, "\nFinal readonly verification:\n");
    fwrite(STDOUT, 'active_expenses_count=' . (string) $result['final_preview']['active_expenses_count'] . "\n");
    fwrite(STDOUT, 'remaining_mismatch_count=' . (string) $result['final_preview']['mismatch_count'] . "\n");
    fwrite(
        STDOUT,
        'remaining_mismatch_amount_total=' . number_format((float) $result['final_preview']['mismatch_amount_total'], 2, '.', '') . "\n"
    );
    fwrite(STDOUT, 'source_counts_before=' . json_encode($result['source_counts_before'], JSON_UNESCAPED_UNICODE) . "\n");
    fwrite(STDOUT, 'source_counts_after=' . json_encode($result['source_counts_after'], JSON_UNESCAPED_UNICODE) . "\n");
    fwrite(STDOUT, "No data was deleted. No import, migration, schema change, or soft-deleted expense repair was performed.\n");
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = accounting_month_repair_apply_parse_args($argv);
    if ($options['help']) {
        accounting_month_repair_apply_usage();
        exit(0);
    }

    $pdo = app_db();
    accounting_month_repair_apply_print(accounting_month_repair_apply_run($pdo, $options['confirm']));
}
