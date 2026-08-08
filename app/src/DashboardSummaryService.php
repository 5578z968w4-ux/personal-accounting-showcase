<?php

declare(strict_types=1);

final class DashboardSummaryService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, string>
     */
    public function monthOptions(string $selectedMonth, string $currentMonth): array
    {
        $months = [];

        foreach ([
            ['expenses', 'accounting_month', false, true],
            ['incomes', 'accounting_month', false, true],
            ['overtime_logs', 'work_date', true, true],
            ['leave_logs', 'leave_date', true, true],
            ['monthly_work_settings', 'work_month', false, false],
            ['monthly_salary_records', 'salary_month', false, false],
        ] as [$table, $column, $dateColumn, $excludeDeleted]) {
            $where = sprintf(
                '%s IS NOT NULL AND %s <> %s',
                $column,
                $column,
                $this->pdo->quote('')
            );
            if ($excludeDeleted) {
                $where .= ' AND is_deleted = 0';
            }

            $statement = $this->pdo->query(sprintf(
                'SELECT %s AS month_value FROM %s WHERE %s',
                $column,
                $table,
                $where
            ));

            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $value) {
                $month = $dateColumn ? substr((string) $value, 0, 7) : (string) $value;
                $month = str_replace('-', '/', $month);
                if (preg_match('/^[0-9]{4}\/[0-9]{2}$/', $month) === 1 && $month !== '0000/00') {
                    $months[$month] = true;
                }
            }
        }

        $months[$currentMonth] = true;
        if ($selectedMonth !== '') {
            $months[$selectedMonth] = true;
        }

        $options = array_keys($months);
        rsort($options);

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(string $selectedMonth, string $entryOwner = 'all'): array
    {
        $likeMonth = str_replace('/', '-', $selectedMonth) . '-%';
        $manualIncomeTotal = $this->sumAmount('incomes', $selectedMonth);
        $expenseTotal = $this->sumAmount('expenses', $selectedMonth, $entryOwner);
        $overtime = $this->overtimeSummary($likeMonth);
        $leave = $this->leaveSummary($likeMonth);
        [$expectedWorkDays, $workDaysSource] = $this->expectedWorkDays($selectedMonth);
        $salarySettings = $this->salarySettings();

        $expectedWorkDaysValue = (float) $expectedWorkDays;
        $leaveDays = (float) ($leave['leave_days'] ?? 0);
        $specialLeaveDays = (float) ($leave['special_leave_days'] ?? 0);
        $annualSpecialLeaveDays = $salarySettings['annual_special_leave_days'] ?? 0;
        $usedAnnualSpecialLeaveDays = $this->usedAnnualSpecialLeaveDays($selectedMonth);
        $fullAttendanceLeaveDays = $leaveDays;
        if ($annualSpecialLeaveDays > 0 && $usedAnnualSpecialLeaveDays <= $annualSpecialLeaveDays) {
            $fullAttendanceLeaveDays = max($leaveDays - $specialLeaveDays, 0);
        }
        $attendanceDays = max($expectedWorkDaysValue - $leaveDays, 0);
        $fullAttendanceBonus = $fullAttendanceLeaveDays > 0 ? 0 : ($salarySettings['full_attendance_bonus'] ?? 0);
        $attendanceAllowance = $attendanceDays * ($salarySettings['attendance_allowance_unit'] ?? 0);
        $overtimeHours134 = (float) ($overtime['hours_134'] ?? 0);
        $overtimeHours167 = (float) ($overtime['hours_167'] ?? 0);
        $overtime2Days = (int) ($overtime['two_hour_days'] ?? 0);
        $overtime3Days = (int) ($overtime['three_hour_days'] ?? 0);
        $salaryNetTotal = ($salarySettings['base_salary'] ?? 0)
            + $fullAttendanceBonus
            + $attendanceAllowance
            + ($overtimeHours134 * ($salarySettings['overtime_134_hourly_rate'] ?? 0))
            + ($overtimeHours167 * ($salarySettings['overtime_167_hourly_rate'] ?? 0))
            + ($overtime2Days * ($salarySettings['overtime_2h_meal_fee'] ?? 0))
            + ($overtime3Days * ($salarySettings['overtime_3h_night_snack_fee'] ?? 0))
            - ($salarySettings['labor_insurance_deduction'] ?? 0)
            - ($salarySettings['health_insurance_deduction'] ?? 0);
        $incomeTotal = $manualIncomeTotal + $salaryNetTotal;

        return [
            'manual_income_total' => $manualIncomeTotal,
            'expense_total' => $expenseTotal,
            'today' => $this->todaySummary(date('Y-m-d'), $entryOwner),
            'overtime_2_days' => $overtime2Days,
            'overtime_3_days' => $overtime3Days,
            'overtime_hours_134' => $overtimeHours134,
            'overtime_hours_167' => $overtimeHours167,
            'leave_days' => (float) ($leave['leave_days'] ?? 0),
            'leave_hours' => (float) ($leave['leave_hours'] ?? 0),
            'expected_work_days' => $expectedWorkDaysValue,
            'work_days_source' => $workDaysSource,
            'salary_net_total' => $salaryNetTotal,
            'income_total' => $incomeTotal,
            'net_total' => $incomeTotal - $expenseTotal,
            'recent_expenses' => $this->recentExpenses($entryOwner),
            'recent_incomes' => $this->recentIncomes($selectedMonth),
        ];
    }

    /**
     * @return array<string, float|int>
     */
    public function todaySummary(string $today, string $entryOwner = 'all'): array
    {
        $expenseWhere = 'is_deleted = 0 AND record_date = :today';
        $expenseParams = ['today' => $today];
        if ($this->shouldFilterEntryOwner('expenses', $entryOwner)) {
            $expenseWhere .= ' AND entry_owner = :entry_owner';
            $expenseParams['entry_owner'] = $entryOwner;
        }

        $expenseStatement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS row_count
             FROM expenses
             WHERE ' . $expenseWhere
        );
        $expenseStatement->execute($expenseParams);
        $expenses = $expenseStatement->fetch() ?: [];

        $incomeStatement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS row_count
             FROM incomes
             WHERE is_deleted = 0 AND record_date = :today'
        );
        $incomeStatement->execute(['today' => $today]);
        $incomes = $incomeStatement->fetch() ?: [];

        $overtimeStatement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(overtime_hours), 0) AS total
             FROM overtime_logs
             WHERE is_deleted = 0 AND work_date = :today'
        );
        $overtimeStatement->execute(['today' => $today]);

        $leaveStatement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(total_leave_days), 0) AS total
             FROM leave_logs
             WHERE is_deleted = 0 AND leave_date = :today'
        );
        $leaveStatement->execute(['today' => $today]);

        return [
            'expense_total' => (float) ($expenses['total'] ?? 0),
            'expense_count' => (int) ($expenses['row_count'] ?? 0),
            'income_total' => (float) ($incomes['total'] ?? 0),
            'income_count' => (int) ($incomes['row_count'] ?? 0),
            'overtime_hours' => (float) $overtimeStatement->fetchColumn(),
            'leave_days' => (float) $leaveStatement->fetchColumn(),
        ];
    }

    private function sumAmount(string $table, string $selectedMonth, string $entryOwner = 'all'): float
    {
        $where = 'is_deleted = 0 AND accounting_month = :month';
        $params = ['month' => $selectedMonth];
        if ($table === 'expenses' && $this->shouldFilterEntryOwner($table, $entryOwner)) {
            $where .= ' AND entry_owner = :entry_owner';
            $params['entry_owner'] = $entryOwner;
        }
        $statement = $this->pdo->prepare(
            sprintf('SELECT COALESCE(SUM(amount), 0) FROM %s WHERE %s', $table, $where)
        );
        $statement->execute($params);

        return (float) $statement->fetchColumn();
    }

    /**
     * @return array<string, float|int>
     */
    private function overtimeSummary(string $likeMonth): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN overtime_hours > 2 THEN 2 ELSE overtime_hours END), 0) AS hours_134,
                COALESCE(SUM(CASE WHEN overtime_hours > 2 THEN overtime_hours - 2 ELSE 0 END), 0) AS hours_167,
                COALESCE(SUM(CASE WHEN overtime_hours = 2.00 THEN 1 ELSE 0 END), 0) AS two_hour_days,
                COALESCE(SUM(CASE WHEN overtime_hours = 3.00 THEN 1 ELSE 0 END), 0) AS three_hour_days
             FROM overtime_logs
             WHERE is_deleted = 0 AND work_date LIKE :month'
        );
        $statement->execute(['month' => $likeMonth]);
        $row = $statement->fetch() ?: [];

        return [
            'hours_134' => (float) ($row['hours_134'] ?? 0),
            'hours_167' => (float) ($row['hours_167'] ?? 0),
            'two_hour_days' => (int) ($row['two_hour_days'] ?? 0),
            'three_hour_days' => (int) ($row['three_hour_days'] ?? 0),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function leaveSummary(string $likeMonth): array
    {
        $specialLeaveExpression = $this->tableHasColumn('leave_logs', 'leave_type')
            ? "COALESCE(SUM(CASE WHEN leave_type = '特休' THEN total_leave_days ELSE 0 END), 0)"
            : '0';
        $statement = $this->pdo->prepare(
            'SELECT
                COALESCE(SUM(total_leave_days), 0) AS leave_days,
                COALESCE(SUM(leave_hours), 0) AS leave_hours,
                ' . $specialLeaveExpression . ' AS special_leave_days
             FROM leave_logs
             WHERE is_deleted = 0 AND leave_date LIKE :month'
        );
        $statement->execute(['month' => $likeMonth]);
        $row = $statement->fetch() ?: [];

        return [
            'leave_days' => (float) ($row['leave_days'] ?? 0),
            'leave_hours' => (float) ($row['leave_hours'] ?? 0),
            'special_leave_days' => (float) ($row['special_leave_days'] ?? 0),
        ];
    }

    private function usedAnnualSpecialLeaveDays(string $selectedMonth): float
    {
        if (!$this->tableHasColumn('leave_logs', 'leave_type')) {
            return 0.0;
        }

        $monthStart = DateTimeImmutable::createFromFormat('!Y/m/d', $selectedMonth . '/01');
        if (!$monthStart instanceof DateTimeImmutable) {
            return 0.0;
        }

        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(total_leave_days), 0)
             FROM leave_logs
             WHERE is_deleted = 0
               AND leave_type = :leave_type
               AND leave_date BETWEEN :year_start AND :month_end'
        );
        $statement->execute([
            'leave_type' => '特休',
            'year_start' => $monthStart->format('Y') . '-01-01',
            'month_end' => $monthStart->modify('last day of this month')->format('Y-m-d'),
        ]);

        return (float) $statement->fetchColumn();
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function expectedWorkDays(string $selectedMonth): array
    {
        $workSetting = $this->pdo->prepare(
            'SELECT expected_work_days FROM monthly_work_settings WHERE work_month = :month LIMIT 1'
        );
        $workSetting->execute(['month' => $selectedMonth]);
        $expectedWorkDays = $workSetting->fetchColumn();
        if ($expectedWorkDays !== false) {
            return [(float) $expectedWorkDays, '每月設定'];
        }

        $defaultSetting = $this->pdo->prepare(
            'SELECT numeric_value FROM settings WHERE setting_key = :setting_key AND is_active = 1 LIMIT 1'
        );
        $defaultSetting->execute(['setting_key' => 'default_work_days']);

        return [(float) ($defaultSetting->fetchColumn() ?: 0), '預設值'];
    }

    /**
     * @return array<string, float>
     */
    private function salarySettings(): array
    {
        $rows = $this->pdo->query(
            'SELECT setting_key, numeric_value FROM settings WHERE is_active = 1'
        )->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['setting_key']] = (float) $row['numeric_value'];
        }

        return $settings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentExpenses(string $entryOwner = 'all'): array
    {
        $where = $this->activeLedgerWhere('expenses');
        $params = [];
        if ($this->shouldFilterEntryOwner('expenses', $entryOwner)) {
            $where .= ' AND entry_owner = :entry_owner';
            $params['entry_owner'] = $entryOwner;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, record_date, item, amount, payment_method, accounting_month, entry_owner
             FROM expenses
             WHERE ' . $where . '
             ORDER BY record_date DESC, id DESC
             LIMIT 10'
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    private function shouldFilterEntryOwner(string $table, string $entryOwner): bool
    {
        return in_array($entryOwner, ['profile_a', 'profile_b'], true)
            && $this->tableHasColumn($table, 'entry_owner');
    }

    private function activeLedgerWhere(string $table): string
    {
        $clauses = [];
        if ($this->tableHasColumn($table, 'deleted_at')) {
            $clauses[] = 'deleted_at IS NULL';
        }
        if ($this->tableHasColumn($table, 'is_deleted')) {
            $clauses[] = 'is_deleted = 0';
        }

        return $clauses === [] ? '1 = 1' : implode(' AND ', $clauses);
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $statement = $this->pdo->query(sprintf('PRAGMA table_info(%s)', $table));
            foreach ($statement->fetchAll() as $row) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }

            return false;
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $statement->execute(['table_name' => $table, 'column_name' => $column]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentIncomes(string $selectedMonth): array
    {
        $statement = $this->pdo->prepare(
            'SELECT record_date, source_name, amount, account_name, accounting_month
             FROM incomes
             WHERE is_deleted = 0 AND accounting_month = :month
             ORDER BY record_date DESC, id DESC
             LIMIT 10'
        );
        $statement->execute(['month' => $selectedMonth]);

        return $statement->fetchAll();
    }
}
