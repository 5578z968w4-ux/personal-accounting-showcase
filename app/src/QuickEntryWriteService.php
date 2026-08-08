<?php

declare(strict_types=1);

require_once __DIR__ . '/AccountingMonthService.php';
require_once __DIR__ . '/AiLedgerLinkService.php';
require_once __DIR__ . '/QuickEntryValidationException.php';
require_once __DIR__ . '/EntryOwner.php';

final class QuickEntryWriteService
{
    private AiLedgerLinkService $ledgerLinkService;

    public function __construct(private readonly PDO $pdo, ?AiLedgerLinkService $ledgerLinkService = null)
    {
        $this->ledgerLinkService = $ledgerLinkService ?? new AiLedgerLinkService($pdo);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed>|null $traceContext
     * @return array<string, mixed>
     */
    public function save(
        string $type,
        array $fields,
        string $rawInput,
        ?string $userName,
        ?array $traceContext = null,
        string $source = 'quick_pwa'
    ): array {
        $source = trim($source) !== '' ? trim($source) : 'quick_pwa';
        $normalized = $this->validate($type, $fields);

        $this->pdo->beginTransaction();
        try {
            $summary = match ($type) {
                'expense' => $this->saveExpense($normalized, $rawInput, $userName, $source),
                'income' => $this->saveIncome($normalized, $rawInput, $userName, $source),
                'overtime' => $this->saveOvertime($normalized, $rawInput, $userName, $source),
                'leave' => $this->saveLeave($normalized, $rawInput, $userName, $source),
                default => throw new InvalidArgumentException('不支援的快速記帳類型。'),
            };
            $linkId = $this->createTraceLink($summary, $traceContext, $rawInput, $userName);
            if ($linkId !== null) {
                $summary['ai_ledger_link_id'] = $linkId;
            }
            $this->pdo->commit();

            return $summary;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed>|null $traceContext
     */
    private function createTraceLink(array $summary, ?array $traceContext, string $rawInput, ?string $userName): ?int
    {
        if ($traceContext === null || (int) ($traceContext['ai_parse_log_id'] ?? 0) < 1) {
            return null;
        }
        $source = (string) ($traceContext['source'] ?? 'quick_pwa');

        return $this->ledgerLinkService->create([
            'ai_parse_log_id' => (int) $traceContext['ai_parse_log_id'],
            'ledger_table' => $summary['ledger_table'] ?? '',
            'ledger_id' => $summary['ledger_id'] ?? 0,
            'action' => $summary['action'] ?? '',
            'source' => $source,
            'expected_raw_input' => $rawInput,
            'expected_parsed_type' => $summary['type'] ?? '',
            'user_name' => $userName,
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function validate(string $type, array $fields): array
    {
        $errors = [];
        $fields['category'] = trim((string) ($fields['category'] ?? '')) ?: '其他';
        try {
            $fields['entry_owner'] = EntryOwner::normalize($fields['entry_owner'] ?? null);
        } catch (InvalidArgumentException) {
            $fields['entry_owner'] = '';
            $errors['entry_owner'] = '記帳對象只允許展示對象 A或展示對象 B。';
        }

        if (($fields['entry_owner'] ?? '') === EntryOwner::PROFILE_B && in_array($type, ['income', 'overtime', 'leave'], true)) {
            throw new QuickEntryValidationException(
                '展示對象 B捷徑目前只支援支出寫入。',
                ['entry_owner' => '展示對象 B只能透過捷徑新增支出，不能新增收入、加班或請假。'],
                $type,
                $fields
            );
        }

        if ($type === 'expense') {
            $this->requireDate($fields, 'record_date', '日期', $errors);
            $this->requireText($fields, 'item', '項目', $errors);
            $this->requirePositiveNumber($fields, 'amount', '金額', $errors);
            $method = $this->activeReference('payment_methods', $fields['payment_method_id'] ?? null);
            if ($method === null) {
                $errors['payment_method_id'] = '請選擇有效的付款方式。';
            } else {
                $fields['payment_method_id'] = (int) $method['id'];
                $fields['payment_method'] = (string) $method['name'];
                $fields['accounting_month'] = AccountingMonthService::forPaymentMethod(
                    (string) $fields['record_date'],
                    $method
                );
            }
        } elseif ($type === 'income') {
            $this->requireDate($fields, 'record_date', '日期', $errors);
            $this->requireText($fields, 'source_name', '收入來源', $errors);
            $this->requirePositiveNumber($fields, 'amount', '金額', $errors);
            $accountId = $fields['account_id'] ?? null;
            if ($accountId === '' || $accountId === null || (int) $accountId === 0) {
                if (trim((string) ($fields['account_name'] ?? '')) !== '') {
                    $errors['account_id'] = '找不到收入帳戶，請選擇有效帳戶或改為未指定。';
                }
                $fields['account_id'] = null;
                $fields['account_name'] = '';
            } else {
                $account = $this->activeReference('accounts', $accountId);
                if ($account === null) {
                    $errors['account_id'] = '請選擇有效的收入帳戶。';
                } else {
                    $fields['account_id'] = (int) $account['id'];
                    $fields['account_name'] = (string) $account['name'];
                }
            }
            if ($this->validDate((string) ($fields['record_date'] ?? ''))) {
                $fields['accounting_month'] = AccountingMonthService::fromRecordDate((string) $fields['record_date']);
            }
        } elseif ($type === 'overtime') {
            $this->requireDate($fields, 'work_date', '加班日期', $errors);
            $this->requirePositiveNumber($fields, 'overtime_hours', '加班時數', $errors);
        } elseif ($type === 'leave') {
            $this->requireDate($fields, 'leave_date', '請假日期', $errors);
            $leaveType = trim((string) ($fields['leave_type'] ?? ''));
            if ($leaveType === '' || !$this->activeNameExists('leave_types', $leaveType)) {
                $errors['leave_type'] = '請選擇有效的假別。';
            }
            $leaveDays = $this->nonNegativeNumber($fields['leave_days'] ?? null);
            $leaveHours = $this->nonNegativeNumber($fields['leave_hours'] ?? null);
            if ($leaveDays === null) {
                $errors['leave_days'] = '請假天數必須是 0 或正數。';
            }
            if ($leaveHours === null) {
                $errors['leave_hours'] = '請假時數必須是 0 或正數。';
            }
            if ($leaveDays !== null && $leaveHours !== null && $leaveDays + $leaveHours <= 0) {
                $errors['leave_days'] = '請假天數與時數不可同時為 0。';
            }
            $fields['leave_days'] = $leaveDays ?? 0;
            $fields['leave_hours'] = $leaveHours ?? 0;
            $fields['total_leave_days'] = round(
                (float) $fields['leave_days'] + ((float) $fields['leave_hours'] / 8),
                2
            );
        } else {
            throw new QuickEntryValidationException(
                '無法判斷要寫入的資料類型。',
                ['type' => '請重新輸入更明確的記帳內容。'],
                $type,
                $fields
            );
        }

        if ($errors !== []) {
            throw new QuickEntryValidationException('欄位不足或設定無法比對，請修正後再送出。', $errors, $type, $fields);
        }

        return $fields;
    }

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    private function saveExpense(array $fields, string $rawInput, ?string $userName, string $source): array
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO expenses
                (record_date, item, amount, payment_method_id, payment_method, accounting_month,
                 category, raw_input, source, user_name, entry_owner)
             VALUES
                (:record_date, :item, :amount, :payment_method_id, :payment_method, :accounting_month,
                 :category, :raw_input, :source, :user_name, :entry_owner)'
        );
        $statement->execute([
            'record_date' => $fields['record_date'],
            'item' => $fields['item'],
            'amount' => $fields['amount'],
            'payment_method_id' => $fields['payment_method_id'],
            'payment_method' => $fields['payment_method'],
            'accounting_month' => $fields['accounting_month'],
            'category' => $fields['category'],
            'raw_input' => $rawInput,
            'source' => $source,
            'user_name' => $userName,
            'entry_owner' => $fields['entry_owner'],
        ]);
        $ledgerId = (int) $this->pdo->lastInsertId();

        return [
            'type' => 'expense',
            'action' => 'created',
            'ledger_table' => 'expenses',
            'ledger_id' => $ledgerId,
            'title' => (string) $fields['item'],
            'date' => (string) $fields['record_date'],
            'amount' => (float) $fields['amount'],
            'unit' => '元',
            'category' => (string) $fields['category'],
            'payment_method' => (string) $fields['payment_method'],
            'accounting_month' => (string) $fields['accounting_month'],
            'entry_owner' => EntryOwner::label($fields['entry_owner']),
            'raw_input' => $rawInput,
            'detail' => (string) $fields['payment_method'] . '／' . (string) $fields['category']
                . '／' . (string) $fields['accounting_month'],
        ];
    }

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    private function saveIncome(array $fields, string $rawInput, ?string $userName, string $source): array
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO incomes
                (record_date, source_name, amount, account_id, account_name, accounting_month,
                 category, raw_input, source, user_name)
             VALUES
                (:record_date, :source_name, :amount, :account_id, :account_name, :accounting_month,
                 :category, :raw_input, :source, :user_name)'
        );
        $statement->execute([
            'record_date' => $fields['record_date'],
            'source_name' => $fields['source_name'],
            'amount' => $fields['amount'],
            'account_id' => $fields['account_id'],
            'account_name' => $fields['account_name'],
            'accounting_month' => $fields['accounting_month'],
            'category' => $fields['category'],
            'raw_input' => $rawInput,
            'source' => $source,
            'user_name' => $userName,
        ]);
        $ledgerId = (int) $this->pdo->lastInsertId();

        return [
            'type' => 'income',
            'action' => 'created',
            'ledger_table' => 'incomes',
            'ledger_id' => $ledgerId,
            'title' => (string) $fields['source_name'],
            'date' => (string) $fields['record_date'],
            'amount' => (float) $fields['amount'],
            'unit' => '元',
            'category' => (string) $fields['category'],
            'account_name' => ((string) $fields['account_name'] !== '' ? (string) $fields['account_name'] : '未指定帳戶'),
            'accounting_month' => (string) $fields['accounting_month'],
            'raw_input' => $rawInput,
            'detail' => ((string) $fields['account_name'] !== '' ? (string) $fields['account_name'] : '未指定帳戶')
                . '／' . (string) $fields['category'] . '／' . (string) $fields['accounting_month'],
        ];
    }

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    private function saveOvertime(array $fields, string $rawInput, ?string $userName, string $source): array
    {
        $existing = $this->pdo->prepare('SELECT id FROM overtime_logs WHERE work_date = :work_date LIMIT 1');
        $existing->execute(['work_date' => $fields['work_date']]);
        $existingId = $existing->fetchColumn();

        if ($existingId !== false) {
            $statement = $this->pdo->prepare(
                'UPDATE overtime_logs
                 SET overtime_hours = :overtime_hours, raw_input = :raw_input, note = :note,
                     user_name = :user_name, source = :source, is_deleted = 0, deleted_at = NULL
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $existingId,
                'overtime_hours' => $fields['overtime_hours'],
                'raw_input' => $rawInput,
                'note' => $rawInput,
                'user_name' => $userName,
                'source' => $source,
            ]);
            $action = 'updated';
            $ledgerId = (int) $existingId;
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO overtime_logs
                    (work_date, overtime_hours, raw_input, note, user_name, source, is_deleted, deleted_at)
                 VALUES
                    (:work_date, :overtime_hours, :raw_input, :note, :user_name, :source, 0, NULL)'
            );
            $statement->execute([
                'work_date' => $fields['work_date'],
                'overtime_hours' => $fields['overtime_hours'],
                'raw_input' => $rawInput,
                'note' => $rawInput,
                'user_name' => $userName,
                'source' => $source,
            ]);
            $action = 'created';
            $ledgerId = (int) $this->pdo->lastInsertId();
        }

        return [
            'type' => 'overtime',
            'action' => $action,
            'ledger_table' => 'overtime_logs',
            'ledger_id' => $ledgerId,
            'title' => '加班',
            'date' => (string) $fields['work_date'],
            'overtime_hours' => (float) $fields['overtime_hours'],
            'amount' => (float) $fields['overtime_hours'],
            'unit' => '小時',
            'raw_input' => $rawInput,
            'detail' => '小時',
        ];
    }

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    private function saveLeave(array $fields, string $rawInput, ?string $userName, string $source): array
    {
        $existing = $this->pdo->prepare(
            'SELECT id FROM leave_logs WHERE leave_date = :leave_date AND is_deleted = 0 ORDER BY id LIMIT 1'
        );
        $existing->execute(['leave_date' => $fields['leave_date']]);
        $existingId = $existing->fetchColumn();
        $note = trim((string) ($fields['note'] ?? '')) ?: $rawInput;

        if ($existingId !== false) {
            $statement = $this->pdo->prepare(
                'UPDATE leave_logs
                 SET leave_type = :leave_type, leave_days = :leave_days, leave_hours = :leave_hours,
                     note = :note, raw_input = :raw_input, user_name = :user_name,
                     source = :source, is_deleted = 0, deleted_at = NULL
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $existingId,
                'leave_type' => $fields['leave_type'],
                'leave_days' => $fields['leave_days'],
                'leave_hours' => $fields['leave_hours'],
                'note' => $note,
                'raw_input' => $rawInput,
                'user_name' => $userName,
                'source' => $source,
            ]);
            $action = 'updated';
            $ledgerId = (int) $existingId;
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO leave_logs
                    (leave_date, leave_type, leave_days, leave_hours, note, raw_input, user_name, source)
                 VALUES
                    (:leave_date, :leave_type, :leave_days, :leave_hours, :note, :raw_input, :user_name, :source)'
            );
            $statement->execute([
                'leave_date' => $fields['leave_date'],
                'leave_type' => $fields['leave_type'],
                'leave_days' => $fields['leave_days'],
                'leave_hours' => $fields['leave_hours'],
                'note' => $note,
                'raw_input' => $rawInput,
                'user_name' => $userName,
                'source' => $source,
            ]);
            $action = 'created';
            $ledgerId = (int) $this->pdo->lastInsertId();
        }

        return [
            'type' => 'leave',
            'action' => $action,
            'ledger_table' => 'leave_logs',
            'ledger_id' => $ledgerId,
            'title' => (string) $fields['leave_type'],
            'date' => (string) $fields['leave_date'],
            'amount' => (float) $fields['total_leave_days'],
            'unit' => '天',
            'note' => trim((string) ($fields['note'] ?? '')),
            'raw_input' => $rawInput,
            'detail' => '天',
        ];
    }

    /** @param array<string, mixed> $fields @param array<string, string> $errors */
    private function requireDate(array &$fields, string $key, string $label, array &$errors): void
    {
        if (!$this->validDate(trim((string) ($fields[$key] ?? '')))) {
            $errors[$key] = $label . '格式不正確。';
        }
    }

    /** @param array<string, mixed> $fields @param array<string, string> $errors */
    private function requireText(array &$fields, string $key, string $label, array &$errors): void
    {
        $value = trim((string) ($fields[$key] ?? ''));
        if ($value === '') {
            $errors[$key] = $label . '不可空白。';
        } else {
            $fields[$key] = $value;
        }
    }

    /** @param array<string, mixed> $fields @param array<string, string> $errors */
    private function requirePositiveNumber(array &$fields, string $key, string $label, array &$errors): void
    {
        $value = $this->nonNegativeNumber($fields[$key] ?? null);
        if ($value === null || $value <= 0) {
            $errors[$key] = $label . '必須大於 0。';
        } else {
            $fields[$key] = $value;
        }
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Taipei'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function nonNegativeNumber(mixed $value): ?float
    {
        if (!is_numeric($value) || (float) $value < 0) {
            return null;
        }
        return (float) $value;
    }

    /** @return array<string, mixed>|null */
    private function activeReference(string $table, mixed $id): ?array
    {
        $columns = match ($table) {
            'payment_methods' => 'id, name, settlement_start_day, settlement_end_day',
            'accounts' => 'id, name',
            default => throw new InvalidArgumentException('不支援的參照資料表。'),
        };
        if (!is_numeric($id) || (int) $id < 1) {
            return null;
        }
        $statement = $this->pdo->prepare(
            sprintf('SELECT %s FROM `%s` WHERE id = :id AND is_active = 1 LIMIT 1', $columns, $table)
        );
        $statement->execute(['id' => (int) $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function activeNameExists(string $table, string $name): bool
    {
        if ($table !== 'leave_types') {
            return false;
        }
        $statement = $this->pdo->prepare(
            'SELECT name FROM leave_types WHERE name = :name AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['name' => $name]);

        return $statement->fetchColumn() !== false;
    }
}
