<?php

declare(strict_types=1);

require_once __DIR__ . '/AccountingMonthService.php';
require_once __DIR__ . '/AiParseException.php';

final class AiBusinessValidator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, list<string>> */
    public function referenceData(): array
    {
        return [
            'payment_methods' => $this->activeNames('payment_methods'),
            'accounts' => $this->activeNames('accounts'),
            'leave_types' => $this->activeNames('leave_types'),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{fields: array<string, mixed>, warnings: list<string>}
     */
    public function validate(string $type, array $fields, string $source = 'web'): array
    {
        return match ($type) {
            'expense' => $this->expense($fields),
            'income' => $this->income($fields),
            'overtime' => $this->overtime($fields, $source),
            'leave' => $this->leave($fields, $source),
            default => throw new AiParseException('無法驗證未知資料類型。', 'validation_failed', 'invalid_type'),
        };
    }

    /** @param array<string, mixed> $fields @return array{fields: array<string, mixed>, warnings: list<string>} */
    private function expense(array $fields): array
    {
        $paymentMethod = trim((string) ($fields['payment_method'] ?? ''));
        $usedDefaultCash = $paymentMethod === '';
        if ($usedDefaultCash) {
            $paymentMethod = '現金';
        }

        $statement = $this->pdo->prepare(
            'SELECT id, name, settlement_start_day, settlement_end_day
             FROM payment_methods WHERE name = :name AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['name' => $paymentMethod]);
        $method = $statement->fetch();
        $warnings = [];

        if (!$method) {
            if ($usedDefaultCash) {
                throw new AiParseException(
                    '找不到啟用中的「現金」付款方式，請先確認後台付款方式設定。',
                    'validation_failed',
                    'missing_cash_payment_method'
                );
            }
            $fields['payment_method_id'] = null;
            $fields['accounting_month'] = null;
            $warnings[] = '找不到付款方式，請確認後台設定。';
        } else {
            $fields['payment_method_id'] = (int) $method['id'];
            $fields['payment_method'] = (string) $method['name'];
            $fields['accounting_month'] = AccountingMonthService::forPaymentMethod(
                (string) $fields['record_date'],
                $method
            );
        }

        return ['fields' => $fields, 'warnings' => $warnings];
    }

    /** @param array<string, mixed> $fields @return array{fields: array<string, mixed>, warnings: list<string>} */
    private function income(array $fields): array
    {
        $fields['accounting_month'] = AccountingMonthService::fromRecordDate((string) $fields['record_date']);
        $fields['account_id'] = null;
        $warnings = [];

        if ($fields['account_name'] !== '') {
            $statement = $this->pdo->prepare(
                'SELECT id, name FROM accounts WHERE name = :name AND is_active = 1 LIMIT 1'
            );
            $statement->execute(['name' => $fields['account_name']]);
            $account = $statement->fetch();
            if ($account) {
                $fields['account_id'] = (int) $account['id'];
                $fields['account_name'] = (string) $account['name'];
            } else {
                $warnings[] = '找不到收入帳戶，預覽保留名稱但不會建立帳戶。';
            }
        }

        return ['fields' => $fields, 'warnings' => $warnings];
    }

    /** @param array<string, mixed> $fields @return array{fields: array<string, mixed>, warnings: list<string>} */
    private function overtime(array $fields, string $source): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM overtime_logs WHERE work_date = :work_date AND is_deleted = 0'
        );
        $statement->execute(['work_date' => $fields['work_date']]);
        $warnings = [];
        if ((int) $statement->fetchColumn() > 0) {
            $warnings[] = in_array($source, ['quick_pwa', 'ios_shortcut', 'admin_ai_input'], true)
                ? '該日期已有加班紀錄；寫入時會更新既有資料。'
                : '該日期已有加班紀錄；本階段只提示，不會更新資料。';
        }

        return ['fields' => $fields, 'warnings' => $warnings];
    }

    /** @param array<string, mixed> $fields @return array{fields: array<string, mixed>, warnings: list<string>} */
    private function leave(array $fields, string $source): array
    {
        $warnings = [];
        if ($fields['leave_type'] === '') {
            $warnings[] = '未指定假別，請在後續確認特休、事假或病假。';
        } else {
            $typeStatement = $this->pdo->prepare(
                'SELECT name FROM leave_types WHERE name = :name AND is_active = 1 LIMIT 1'
            );
            $typeStatement->execute(['name' => $fields['leave_type']]);
            if (!$typeStatement->fetchColumn()) {
                $warnings[] = '找不到啟用中的假別，請確認後台設定。';
            }
        }

        $conflictStatement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM leave_logs WHERE leave_date = :leave_date AND is_deleted = 0'
        );
        $conflictStatement->execute(['leave_date' => $fields['leave_date']]);
        if ((int) $conflictStatement->fetchColumn() > 0) {
            $warnings[] = in_array($source, ['quick_pwa', 'ios_shortcut', 'admin_ai_input'], true)
                ? '該日期已有請假紀錄；寫入時會更新既有資料。'
                : '該日期已有請假紀錄；本階段只提示，不會更新資料。';
        }

        $fields['total_leave_days'] = round(
            (float) $fields['leave_days'] + ((float) $fields['leave_hours'] / 8),
            2
        );

        return ['fields' => $fields, 'warnings' => $warnings];
    }

    /** @return list<string> */
    private function activeNames(string $table): array
    {
        $allowedTables = ['payment_methods', 'accounts', 'leave_types'];
        if (!in_array($table, $allowedTables, true)) {
            return [];
        }

        return $this->pdo->query(
            sprintf('SELECT name FROM `%s` WHERE is_active = 1 ORDER BY sort_order, id', $table)
        )->fetchAll(PDO::FETCH_COLUMN);
    }
}
