<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/DemoMode.php';
require_once dirname(__DIR__) . '/src/AccountingMonthService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!DemoMode::isEnabled()) {
    fwrite(STDERR, "Demo reset refused: APP_ENV=demo and DEMO_MODE=1 are required.\n");
    exit(2);
}

$pdo = app_db();
$pdo->exec("SET time_zone = '+08:00'");

$timezone = new DateTimeZone('Asia/Taipei');
$today = new DateTimeImmutable('now', $timezone);
$monthStart = $today->modify('first day of this month')->setTime(0, 0);
$month = $monthStart->format('Y/m');
$lastDay = (int) $monthStart->format('t');
$dateAt = static function (int $day) use ($monthStart, $lastDay): string {
    $safeDay = max(1, min($day, $lastDay));
    return $monthStart->setDate(
        (int) $monthStart->format('Y'),
        (int) $monthStart->format('m'),
        $safeDay
    )->format('Y-m-d');
};

$pdo->beginTransaction();

try {
    foreach ([
        'ai_ledger_links',
        'ai_parse_logs',
        'monthly_financial_snapshots',
        'monthly_salary_records',
        'expenses',
        'incomes',
        'overtime_logs',
        'leave_logs',
        'monthly_work_settings',
        'import_jobs',
    ] as $table) {
        $pdo->exec(sprintf('DELETE FROM `%s`', $table));
    }

    $pdo->exec('DELETE FROM payment_methods');
    $insertPaymentMethod = $pdo->prepare(
        'INSERT INTO payment_methods (
            name, settlement_start_day, settlement_end_day,
            cycle_start_day, cycle_end_day, is_active, sort_order, note
         ) VALUES (
            :name, :settlement_start_day, :settlement_end_day,
            :cycle_start_day, :cycle_end_day, 1, :sort_order, :note
         )'
    );
    foreach ([
        ['展示方式 A', 10, 9, 10],
        ['展示方式 B', 15, 14, 20],
        ['展示方式 C', 7, 6, 30],
        ['現金', 1, 31, 40],
    ] as [$name, $startDay, $endDay, $sortOrder]) {
        $insertPaymentMethod->execute([
            'name' => $name,
            'settlement_start_day' => $startDay,
            'settlement_end_day' => $endDay,
            'cycle_start_day' => $startDay,
            'cycle_end_day' => $endDay,
            'sort_order' => $sortOrder,
            'note' => '合成展示付款方式',
        ]);
    }

    $pdo->exec('DELETE FROM accounts');
    $insertAccount = $pdo->prepare(
        'INSERT INTO accounts (name, is_active, sort_order, note)
         VALUES (:name, 1, :sort_order, :note)'
    );
    foreach ([
        ['現金', 10],
        ['展示帳戶 A', 20],
        ['展示帳戶 B', 30],
        ['展示帳戶 C', 40],
    ] as [$name, $sortOrder]) {
        $insertAccount->execute([
            'name' => $name,
            'sort_order' => $sortOrder,
            'note' => '合成展示帳戶',
        ]);
    }

    $demoSettings = [
        'base_salary' => '52000.00',
        'full_attendance_bonus' => '2000.00',
        'attendance_allowance_unit' => '150.00',
        'overtime_134_hourly_rate' => '260.00',
        'overtime_167_hourly_rate' => '320.00',
        'overtime_2h_meal_fee' => '120.00',
        'overtime_3h_night_snack_fee' => '180.00',
        'labor_insurance_deduction' => '1250.00',
        'health_insurance_deduction' => '820.00',
        'annual_special_leave_days' => '14.00',
        'default_work_days' => '22.00',
    ];
    $updateSetting = $pdo->prepare(
        'UPDATE settings SET numeric_value = :numeric_value, note = :note WHERE setting_key = :setting_key'
    );
    foreach ($demoSettings as $settingKey => $numericValue) {
        $updateSetting->execute([
            'setting_key' => $settingKey,
            'numeric_value' => $numericValue,
            'note' => '合成展示設定',
        ]);
    }

    $pdo->exec(
        "UPDATE ai_settings
         SET is_enabled = 0,
             provider = 'local',
             model_name = '',
             save_raw_response = 0,
             allow_expense = 1,
             allow_income = 1,
             allow_overtime = 1,
             allow_leave = 1
         WHERE id = 1"
    );

    $workSetting = $pdo->prepare(
        'INSERT INTO monthly_work_settings (work_month, expected_work_days, note)
         VALUES (:work_month, :expected_work_days, :note)'
    );
    $workSetting->execute([
        'work_month' => $month,
        'expected_work_days' => '22.00',
        'note' => '本機 Demo 合成資料',
    ]);

    $paymentRows = $pdo->query(
        'SELECT id, name, settlement_start_day, settlement_end_day
         FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id'
    )->fetchAll();
    $paymentMethods = [];
    foreach ($paymentRows as $row) {
        $paymentMethods[(string) $row['name']] = $row;
    }
    if (!isset($paymentMethods['現金'], $paymentMethods['展示方式 C'])) {
        throw new RuntimeException('Demo payment method seed is incomplete.');
    }

    $accountRows = $pdo->query(
        'SELECT id, name FROM accounts WHERE is_active = 1 ORDER BY sort_order, id'
    )->fetchAll();
    $accounts = [];
    foreach ($accountRows as $row) {
        $accounts[(string) $row['name']] = $row;
    }
    if (!isset($accounts['現金'], $accounts['展示帳戶 C'])) {
        throw new RuntimeException('Demo account seed is incomplete.');
    }

    $expenseInsert = $pdo->prepare(
        'INSERT INTO expenses (
            record_date, item, amount, payment_method_id, payment_method,
            accounting_month, category, raw_input, source, user_name, entry_owner
         ) VALUES (
            :record_date, :item, :amount, :payment_method_id, :payment_method,
            :accounting_month, :category, :raw_input, :source, :user_name, :entry_owner
         )'
    );
    $expenseFixtures = [
        [$today->format('Y-m-d'), '晨間咖啡', '85.00', '現金', '餐飲', 'profile_a'],
        [$dateAt(2), '通勤月票', '1280.00', '展示方式 C', '交通', 'profile_a'],
        [$dateAt(3), '週末早午餐', '460.00', '現金', '餐飲', 'profile_b'],
        [$dateAt(4), '家庭日用品', '1260.00', '展示方式 C', '生活', 'profile_b'],
        [$dateAt(5), '雲端服務', '320.00', '展示方式 C', '3C', 'profile_a'],
        [$dateAt(6), '線上課程', '1680.00', '展示方式 C', '學習', 'profile_a'],
        [$dateAt(7), '運動用品', '980.00', '現金', '生活', 'profile_b'],
        [$dateAt(8), '技術書籍', '720.00', '現金', '學習', 'profile_a'],
    ];
    $linkedExpenseId = 0;
    foreach ($expenseFixtures as [$recordDate, $item, $amount, $methodName, $category, $entryOwner]) {
        $paymentMethod = $paymentMethods[$methodName];
        $expenseInsert->execute([
            'record_date' => $recordDate,
            'item' => $item,
            'amount' => $amount,
            'payment_method_id' => (int) $paymentMethod['id'],
            'payment_method' => $methodName,
            'accounting_month' => AccountingMonthService::forPaymentMethod($recordDate, $paymentMethod),
            'category' => $category,
            'raw_input' => '合成展示資料：' . $item,
            'source' => 'demo_seed',
            'user_name' => 'demo',
            'entry_owner' => $entryOwner,
        ]);
        if ($item === '通勤月票') {
            $linkedExpenseId = (int) $pdo->lastInsertId();
        }
    }

    $incomeInsert = $pdo->prepare(
        'INSERT INTO incomes (
            record_date, source_name, amount, account_id, account_name,
            accounting_month, category, raw_input, source, user_name
         ) VALUES (
            :record_date, :source_name, :amount, :account_id, :account_name,
            :accounting_month, :category, :raw_input, :source, :user_name
         )'
    );
    foreach ([
        [$dateAt(3), '接案收入', '6800.00', '展示帳戶 C', '額外收入'],
        [$dateAt(6), '二手物品出售', '2200.00', '現金', '其他收入'],
    ] as [$recordDate, $sourceName, $amount, $accountName, $category]) {
        $account = $accounts[$accountName];
        $incomeInsert->execute([
            'record_date' => $recordDate,
            'source_name' => $sourceName,
            'amount' => $amount,
            'account_id' => (int) $account['id'],
            'account_name' => $accountName,
            'accounting_month' => AccountingMonthService::fromRecordDate($recordDate),
            'category' => $category,
            'raw_input' => '合成展示資料：' . $sourceName,
            'source' => 'demo_seed',
            'user_name' => 'demo',
        ]);
    }

    $overtimeInsert = $pdo->prepare(
        'INSERT INTO overtime_logs (
            work_date, overtime_hours, hours_134, hours_167, meal_fee,
            night_snack_fee, note, raw_input, user_name, source
         ) VALUES (
            :work_date, :overtime_hours, :hours_134, :hours_167, :meal_fee,
            :night_snack_fee, :note, :raw_input, :user_name, :source
         )'
    );
    foreach ([
        [$dateAt(4), '2.00', '2.00', '0.00', '120.00', '0.00'],
        [$dateAt(7), '3.00', '2.00', '1.00', '120.00', '180.00'],
    ] as [$workDate, $hours, $hours134, $hours167, $mealFee, $nightSnackFee]) {
        $overtimeInsert->execute([
            'work_date' => $workDate,
            'overtime_hours' => $hours,
            'hours_134' => $hours134,
            'hours_167' => $hours167,
            'meal_fee' => $mealFee,
            'night_snack_fee' => $nightSnackFee,
            'note' => '合成展示資料',
            'raw_input' => '展示加班 ' . $hours . ' 小時',
            'user_name' => 'demo',
            'source' => 'demo_seed',
        ]);
    }

    $leaveInsert = $pdo->prepare(
        'INSERT INTO leave_logs (
            leave_date, leave_type, leave_days, leave_hours, note,
            user_name, raw_input, source
         ) VALUES (
            :leave_date, :leave_type, :leave_days, :leave_hours, :note,
            :user_name, :raw_input, :source
         )'
    );
    $leaveInsert->execute([
        'leave_date' => $dateAt(5),
        'leave_type' => '特休',
        'leave_days' => '0.50',
        'leave_hours' => '0.00',
        'note' => '合成展示資料',
        'user_name' => 'demo',
        'raw_input' => '展示特休半天',
        'source' => 'demo_seed',
    ]);

    $parsedJson = json_encode([
        'type' => 'expense',
        'fields' => [
            'record_date' => $dateAt(2),
            'item' => '通勤月票',
            'amount' => 1280,
            'payment_method' => '展示方式 C',
            'category' => '交通',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $parseLog = $pdo->prepare(
        'INSERT INTO ai_parse_logs (
            raw_input, provider, model_name, parsed_type, parsed_json,
            parse_status, duration_ms, source, user_name, entry_owner
         ) VALUES (
            :raw_input, :provider, :model_name, :parsed_type, :parsed_json,
            :parse_status, :duration_ms, :source, :user_name, :entry_owner
         )'
    );
    $parseLog->execute([
        'raw_input' => '合成展示：通勤月票 1280 展示方式 C',
        'provider' => 'mock',
        'model_name' => 'synthetic-demo',
        'parsed_type' => 'expense',
        'parsed_json' => $parsedJson,
        'parse_status' => 'success',
        'duration_ms' => 42,
        'source' => 'demo_seed',
        'user_name' => 'demo',
        'entry_owner' => 'profile_a',
    ]);
    $parseLogId = (int) $pdo->lastInsertId();

    if ($linkedExpenseId < 1 || $parseLogId < 1) {
        throw new RuntimeException('Demo trace seed failed.');
    }
    $linkInsert = $pdo->prepare(
        'INSERT INTO ai_ledger_links (
            ai_parse_log_id, ledger_table, ledger_id, action, source,
            raw_input_snapshot, parsed_type_snapshot, parsed_json_snapshot, user_name
         ) VALUES (
            :ai_parse_log_id, :ledger_table, :ledger_id, :action, :source,
            :raw_input_snapshot, :parsed_type_snapshot, :parsed_json_snapshot, :user_name
         )'
    );
    $linkInsert->execute([
        'ai_parse_log_id' => $parseLogId,
        'ledger_table' => 'expenses',
        'ledger_id' => $linkedExpenseId,
        'action' => 'created',
        'source' => 'demo_seed',
        'raw_input_snapshot' => '合成展示：通勤月票 1280 展示方式 C',
        'parsed_type_snapshot' => 'expense',
        'parsed_json_snapshot' => $parsedJson,
        'user_name' => 'demo',
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Demo reset failed: " . $exception->getMessage() . "\n");
    exit(1);
}

$counts = [];
foreach (['expenses', 'incomes', 'overtime_logs', 'leave_logs', 'ai_parse_logs', 'ai_ledger_links'] as $table) {
    $counts[$table] = (int) $pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
}

echo json_encode([
    'ok' => true,
    'database' => DemoMode::DATABASE,
    'month' => $month,
    'counts' => $counts,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
