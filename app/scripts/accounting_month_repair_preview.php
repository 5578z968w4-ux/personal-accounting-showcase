<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/AccountingMonthService.php';

/**
 * @return array<string, mixed>
 */
function accounting_month_repair_preview_production_gate(PDO $pdo, array $override = []): array
{
    $appEnv = $override['APP_ENV'] ?? (string) app_env('APP_ENV', '');
    $configuredDatabase = $override['DB_DATABASE'] ?? (string) app_env('DB_DATABASE', '');
    $selectedDatabase = $override['SELECT_DATABASE'] ?? (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

    if ($appEnv !== 'production') {
        throw new RuntimeException('Accounting month preview gate failed: APP_ENV must be production.');
    }
    if ($configuredDatabase !== 'personal_accounting') {
        throw new RuntimeException('Accounting month preview gate failed: DB_DATABASE must be personal_accounting.');
    }
    if ($selectedDatabase !== 'personal_accounting') {
        throw new RuntimeException('Accounting month preview gate failed: SELECT DATABASE() must be personal_accounting.');
    }

    return [
        'APP_ENV' => $appEnv,
        'DB_DATABASE' => $configuredDatabase,
        'SELECT_DATABASE' => $selectedDatabase,
    ];
}

function accounting_month_repair_preview_identifier(string $identifier): string
{
    if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $identifier)) {
        throw new InvalidArgumentException('invalid identifier');
    }

    return $identifier;
}

function accounting_month_repair_preview_column_exists(PDO $pdo, string $table, string $column): bool
{
    $table = accounting_month_repair_preview_identifier($table);
    $column = accounting_month_repair_preview_identifier($column);

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $statement = $pdo->query('PRAGMA table_info(' . $table . ')');
        foreach ($statement->fetchAll() as $row) {
            if (($row['name'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $statement->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

/**
 * @return array{where: string, description: list<string>, has_item: bool}
 */
function accounting_month_repair_preview_expense_metadata(PDO $pdo): array
{
    $conditions = [];
    $description = [];

    if (accounting_month_repair_preview_column_exists($pdo, 'expenses', 'deleted_at')) {
        $conditions[] = 'e.deleted_at IS NULL';
        $description[] = 'deleted_at IS NULL';
    }

    if (accounting_month_repair_preview_column_exists($pdo, 'expenses', 'is_deleted')) {
        $conditions[] = 'e.is_deleted = 0';
        $description[] = 'is_deleted = 0';
    }

    return [
        'where' => count($conditions) > 0 ? implode(' AND ', $conditions) : '1 = 1',
        'description' => count($description) > 0 ? $description : ['no soft-delete columns detected'],
        'has_item' => accounting_month_repair_preview_column_exists($pdo, 'expenses', 'item'),
    ];
}

/**
 * @param array<string, array<string, mixed>> $target
 */
function accounting_month_repair_preview_add_group(array &$target, string $key, array $base, float $amount): void
{
    if (!isset($target[$key])) {
        $target[$key] = $base + [
            'count' => 0,
            'amount_total' => 0.0,
        ];
    }

    $target[$key]['count']++;
    $target[$key]['amount_total'] += $amount;
}

/**
 * @return array<string, mixed>
 */
function accounting_month_repair_preview(PDO $pdo, int $limit = 50): array
{
    $metadata = accounting_month_repair_preview_expense_metadata($pdo);
    $itemSelect = $metadata['has_item'] ? 'e.item' : 'NULL AS item';
    $statement = $pdo->query(
        'SELECT e.id, e.record_date, e.amount, e.payment_method, e.accounting_month, e.source, ' . $itemSelect . ',
                pm.name AS payment_method_name, pm.settlement_start_day, pm.settlement_end_day
         FROM expenses e
         LEFT JOIN payment_methods pm ON pm.id = e.payment_method_id
         WHERE ' . $metadata['where'] . '
         ORDER BY e.record_date DESC, e.id DESC'
    );

    $mismatches = [];
    $summary = [];
    $byPaymentMethod = [];
    $byMonth = [];
    $checked = 0;
    $mismatchAmountTotal = 0.0;

    foreach ($statement->fetchAll() as $row) {
        $checked++;
        $method = [
            'settlement_start_day' => $row['settlement_start_day'] ?? 0,
            'settlement_end_day' => $row['settlement_end_day'] ?? 0,
        ];
        $expected = AccountingMonthService::forPaymentMethod((string) $row['record_date'], $method);
        $current = (string) $row['accounting_month'];
        if ($expected === $current) {
            continue;
        }

        $paymentMethod = (string) ($row['payment_method_name'] ?: $row['payment_method']);
        $amount = (float) $row['amount'];
        $mismatchAmountTotal += $amount;

        accounting_month_repair_preview_add_group(
            $summary,
            $paymentMethod . '|' . $current . '|' . $expected,
            [
                'payment_method' => $paymentMethod,
                'current_accounting_month' => $current,
                'expected_accounting_month' => $expected,
            ],
            $amount
        );
        accounting_month_repair_preview_add_group(
            $byPaymentMethod,
            $paymentMethod,
            ['payment_method' => $paymentMethod],
            $amount
        );
        accounting_month_repair_preview_add_group(
            $byMonth,
            $current . '|' . $expected,
            [
                'current_accounting_month' => $current,
                'expected_accounting_month' => $expected,
            ],
            $amount
        );

        if (count($mismatches) < $limit) {
            $item = (string) ($row['item'] ?? '');
            $mismatches[] = [
                'id' => (int) $row['id'],
                'record_date' => (string) $row['record_date'],
                'payment_method' => $paymentMethod,
                'amount' => $amount,
                'current_accounting_month' => $current,
                'expected_accounting_month' => $expected,
                'item_length' => function_exists('mb_strlen') ? mb_strlen($item, 'UTF-8') : strlen($item),
                'source' => (string) ($row['source'] ?? ''),
            ];
        }
    }

    return [
        'active_expenses_count' => $checked,
        'checked' => $checked,
        'mismatch_count' => array_sum(array_column($summary, 'count')),
        'mismatch_amount_total' => $mismatchAmountTotal,
        'summary' => array_values($summary),
        'mismatch_by_payment_method' => array_values($byPaymentMethod),
        'mismatch_by_month' => array_values($byMonth),
        'sample_limit' => $limit,
        'samples' => $mismatches,
        'active_filters' => $metadata['description'],
        'readonly' => true,
    ];
}

function accounting_month_repair_preview_print(array $result, array $gate = []): void
{
    fwrite(STDOUT, "Accounting month repair preview (read-only)\n");
    fwrite(STDOUT, "readonly_mode=SELECT-only\n");
    if ($gate !== []) {
        fwrite(STDOUT, 'APP_ENV=' . $gate['APP_ENV'] . "\n");
        fwrite(STDOUT, 'DB_DATABASE=' . $gate['DB_DATABASE'] . "\n");
        fwrite(STDOUT, 'SELECT_DATABASE=' . $gate['SELECT_DATABASE'] . "\n");
    }
    fwrite(STDOUT, 'active_filters=' . implode(', ', $result['active_filters']) . "\n");
    fwrite(STDOUT, 'active_expenses_count=' . (string) $result['active_expenses_count'] . "\n");
    fwrite(STDOUT, 'mismatch_count=' . (string) $result['mismatch_count'] . "\n\n");
    fwrite(STDOUT, 'mismatch_amount_total=' . number_format((float) $result['mismatch_amount_total'], 2, '.', '') . "\n\n");

    fwrite(STDOUT, "Summary by payment method / month transition:\n");
    foreach ($result['summary'] as $row) {
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

    fwrite(STDOUT, "\nSummary by payment method:\n");
    foreach ($result['mismatch_by_payment_method'] as $row) {
        fwrite(
            STDOUT,
            sprintf(
                "- payment_method=%s count=%d amount_total=%s\n",
                $row['payment_method'],
                $row['count'],
                number_format((float) $row['amount_total'], 2, '.', '')
            )
        );
    }

    fwrite(STDOUT, "\nSummary by existing / expected accounting_month:\n");
    foreach ($result['mismatch_by_month'] as $row) {
        fwrite(
            STDOUT,
            sprintf(
                "- current=%s expected=%s count=%d amount_total=%s\n",
                $row['current_accounting_month'],
                $row['expected_accounting_month'],
                $row['count'],
                number_format((float) $row['amount_total'], 2, '.', '')
            )
        );
    }

    fwrite(STDOUT, "\nSamples:\n");
    foreach ($result['samples'] as $row) {
        fwrite(
            STDOUT,
            sprintf(
                "- id=%d date=%s payment_method=%s amount=%s current=%s expected=%s item_length=%d source=%s\n",
                $row['id'],
                $row['record_date'],
                $row['payment_method'],
                number_format((float) $row['amount'], 2, '.', ''),
                $row['current_accounting_month'],
                $row['expected_accounting_month'],
                $row['item_length'],
                $row['source']
            )
        );
    }

    if ((int) $result['mismatch_count'] === 0) {
        fwrite(STDOUT, "\nPASS: no accounting_month mismatches found.\n");
    }

    fwrite(STDOUT, "\nNo INSERT, UPDATE, DELETE, TRUNCATE, DROP, migration, import, or data repair is performed by this script.\n");
    fwrite(STDOUT, "No data was modified.\n");
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $pdo = app_db();
    $gate = accounting_month_repair_preview_production_gate($pdo);
    accounting_month_repair_preview_print(accounting_month_repair_preview($pdo), $gate);
}
