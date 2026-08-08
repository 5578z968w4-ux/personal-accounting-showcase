<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';

$pdo = app_db();
$pdo->exec("SET time_zone = '+08:00'");

function table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table'
    );
    $statement->execute(['table' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
    );
    $statement->execute(['table' => $table, 'column' => $column]);
    return (int) $statement->fetchColumn() > 0;
}

function add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!column_exists($pdo, $table, $column)) {
        $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
    }
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index_name'
    );
    $statement->execute(['table' => $table, 'index_name' => $index]);
    return (int) $statement->fetchColumn() > 0;
}

function add_index(PDO $pdo, string $table, string $index, string $definition): void
{
    if (!index_exists($pdo, $table, $index)) {
        $pdo->exec(sprintf('ALTER TABLE `%s` ADD %s', $table, $definition));
    }
}

function foreign_key_exists(PDO $pdo, string $table, string $constraint): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.table_constraints
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND constraint_name = :constraint
           AND constraint_type = \'FOREIGN KEY\''
    );
    $statement->execute(['table' => $table, 'constraint' => $constraint]);
    return (int) $statement->fetchColumn() > 0;
}

function add_foreign_key(PDO $pdo, string $table, string $constraint, string $definition): void
{
    if (!foreign_key_exists($pdo, $table, $constraint)) {
        $pdo->exec(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` %s', $table, $constraint, $definition));
    }
}

$tables = [
    'settings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(80) NOT NULL,
    label VARCHAR(120) NOT NULL,
    numeric_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    unit VARCHAR(40) NULL,
    note TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'payment_methods' => <<<'SQL'
CREATE TABLE IF NOT EXISTS payment_methods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    settlement_start_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
    settlement_end_day TINYINT UNSIGNED NOT NULL DEFAULT 31,
    cycle_start_day TINYINT UNSIGNED NOT NULL,
    cycle_end_day TINYINT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'accounts' => <<<'SQL'
CREATE TABLE IF NOT EXISTS accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'expenses' => <<<'SQL'
CREATE TABLE IF NOT EXISTS expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    record_date DATE NOT NULL,
    item VARCHAR(160) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method_id INT UNSIGNED NULL,
    payment_method VARCHAR(80) NOT NULL,
    accounting_month CHAR(7) NOT NULL,
    category VARCHAR(80) NULL,
    raw_input TEXT NULL,
    ai_response MEDIUMTEXT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'manual',
    user_name VARCHAR(80) NULL,
    entry_owner VARCHAR(32) NOT NULL DEFAULT 'profile_a',
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'incomes' => <<<'SQL'
CREATE TABLE IF NOT EXISTS incomes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    record_date DATE NOT NULL,
    source_name VARCHAR(160) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    account_id INT UNSIGNED NULL,
    account_name VARCHAR(80) NOT NULL,
    accounting_month CHAR(7) NOT NULL,
    category VARCHAR(80) NULL,
    raw_input TEXT NULL,
    ai_response MEDIUMTEXT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'manual',
    user_name VARCHAR(80) NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'overtime_logs' => <<<'SQL'
CREATE TABLE IF NOT EXISTS overtime_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_date DATE NOT NULL,
    overtime_hours DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    hours_134 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    hours_167 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    meal_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    night_snack_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    note TEXT NULL,
    raw_input TEXT NULL,
    user_name VARCHAR(80) NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'leave_logs' => <<<'SQL'
CREATE TABLE IF NOT EXISTS leave_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    leave_date DATE NOT NULL,
    leave_type VARCHAR(80) NOT NULL,
    leave_days DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    leave_hours DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_leave_days DECIMAL(12,2) GENERATED ALWAYS AS (leave_days + leave_hours / 8) STORED,
    active_leave_date DATE GENERATED ALWAYS AS (CASE WHEN is_deleted = 0 THEN leave_date ELSE NULL END) STORED,
    note TEXT NULL,
    user_name VARCHAR(80) NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'monthly_salary_records' => <<<'SQL'
CREATE TABLE IF NOT EXISTS monthly_salary_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salary_month CHAR(7) NOT NULL,
    base_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    full_attendance_bonus DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    attendance_allowance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    overtime_pay_134 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    overtime_pay_167 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    meal_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    night_snack_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    labor_insurance_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    health_insurance_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gross_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    net_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'monthly_financial_snapshots' => <<<'SQL'
CREATE TABLE IF NOT EXISTS monthly_financial_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    accounting_month CHAR(7) NOT NULL,
    total_income DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_expense DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    net_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    snapshot_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'ai_parse_logs' => <<<'SQL'
CREATE TABLE IF NOT EXISTS ai_parse_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    raw_input TEXT NOT NULL,
    ai_response MEDIUMTEXT NULL,
    provider VARCHAR(40) NULL,
    model_name VARCHAR(120) NULL,
    parsed_type VARCHAR(40) NULL,
    parsed_json MEDIUMTEXT NULL,
    parse_status VARCHAR(40) NOT NULL DEFAULT 'pending',
    error_code VARCHAR(80) NULL,
    error_message TEXT NULL,
    duration_ms INT UNSIGNED NULL,
    source VARCHAR(40) NULL,
    user_name VARCHAR(80) NULL,
    entry_owner VARCHAR(32) NOT NULL DEFAULT 'profile_a',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'ai_settings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS ai_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    provider VARCHAR(40) NOT NULL DEFAULT 'local',
    model_name VARCHAR(120) NOT NULL DEFAULT '',
    temperature DECIMAL(3,2) NOT NULL DEFAULT 0.10,
    max_tokens INT UNSIGNED NOT NULL DEFAULT 1000,
    save_raw_response TINYINT(1) NOT NULL DEFAULT 0,
    allow_expense TINYINT(1) NOT NULL DEFAULT 1,
    allow_income TINYINT(1) NOT NULL DEFAULT 1,
    allow_overtime TINYINT(1) NOT NULL DEFAULT 1,
    allow_leave TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'ai_ledger_links' => <<<'SQL'
CREATE TABLE IF NOT EXISTS ai_ledger_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ai_parse_log_id BIGINT UNSIGNED NOT NULL,
    ledger_table VARCHAR(40) NOT NULL,
    ledger_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(20) NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'quick_pwa',
    raw_input_snapshot TEXT NULL,
    parsed_type_snapshot VARCHAR(40) NULL,
    parsed_json_snapshot MEDIUMTEXT NULL,
    user_name VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'import_jobs' => <<<'SQL'
CREATE TABLE IF NOT EXISTS import_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_type VARCHAR(40) NOT NULL,
    original_filename VARCHAR(255) NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    success_rows INT UNSIGNED NOT NULL DEFAULT 0,
    failed_rows INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    created_by VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'leave_types' => <<<'SQL'
CREATE TABLE IF NOT EXISTS leave_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'monthly_work_settings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS monthly_work_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_month CHAR(7) NOT NULL,
    expected_work_days DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];

foreach ($tables as $sql) {
    $pdo->exec($sql);
}

$columns = [
    'settings' => [
        'setting_key' => 'VARCHAR(80) NOT NULL',
        'label' => 'VARCHAR(120) NOT NULL',
        'numeric_value' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'unit' => 'VARCHAR(40) NULL',
        'note' => 'TEXT NULL',
        'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'sort_order' => 'INT NOT NULL DEFAULT 0',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
    'payment_methods' => [
        'name' => 'VARCHAR(80) NOT NULL',
        'settlement_start_day' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
        'settlement_end_day' => 'TINYINT UNSIGNED NOT NULL DEFAULT 31',
        'cycle_start_day' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
        'cycle_end_day' => 'TINYINT UNSIGNED NOT NULL DEFAULT 31',
        'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'sort_order' => 'INT NOT NULL DEFAULT 0',
        'note' => 'TEXT NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
    'accounts' => [
        'name' => 'VARCHAR(80) NOT NULL',
        'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'sort_order' => 'INT NOT NULL DEFAULT 0',
        'note' => 'TEXT NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
    'expenses' => [
        'record_date' => 'DATE NOT NULL',
        'item' => 'VARCHAR(160) NOT NULL',
        'amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'payment_method_id' => 'INT UNSIGNED NULL',
        'payment_method' => 'VARCHAR(80) NOT NULL DEFAULT \'\'',
        'accounting_month' => 'CHAR(7) NOT NULL DEFAULT \'0000-00\'',
        'category' => 'VARCHAR(80) NULL',
        'raw_input' => 'TEXT NULL',
        'ai_response' => 'MEDIUMTEXT NULL',
        'source' => 'VARCHAR(40) NOT NULL DEFAULT \'manual\'',
        'user_name' => 'VARCHAR(80) NULL',
        'entry_owner' => 'VARCHAR(32) NOT NULL DEFAULT \'profile_a\'',
        'is_deleted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'deleted_at' => 'DATETIME NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
    'incomes' => [
        'record_date' => 'DATE NOT NULL',
        'source_name' => 'VARCHAR(160) NOT NULL',
        'amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'account_id' => 'INT UNSIGNED NULL',
        'account_name' => 'VARCHAR(80) NOT NULL DEFAULT \'\'',
        'accounting_month' => 'CHAR(7) NOT NULL DEFAULT \'0000-00\'',
        'category' => 'VARCHAR(80) NULL',
        'raw_input' => 'TEXT NULL',
        'ai_response' => 'MEDIUMTEXT NULL',
        'source' => 'VARCHAR(40) NOT NULL DEFAULT \'manual\'',
        'user_name' => 'VARCHAR(80) NULL',
        'is_deleted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'deleted_at' => 'DATETIME NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
    'overtime_logs' => [
        'work_date' => 'DATE NOT NULL',
        'overtime_hours' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'hours_134' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'hours_167' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'meal_fee' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'night_snack_fee' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'note' => 'TEXT NULL',
        'raw_input' => 'TEXT NULL',
        'source' => 'VARCHAR(40) NOT NULL DEFAULT \'manual\'',
        'user_name' => 'VARCHAR(80) NULL',
        'is_deleted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'deleted_at' => 'DATETIME NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
    'leave_logs' => [
        'leave_date' => 'DATE NOT NULL',
        'leave_type' => 'VARCHAR(80) NOT NULL DEFAULT \'\'',
        'leave_days' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'leave_hours' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'total_leave_days' => 'DECIMAL(12,2) GENERATED ALWAYS AS (leave_days + leave_hours / 8) STORED',
        'active_leave_date' => 'DATE GENERATED ALWAYS AS (CASE WHEN is_deleted = 0 THEN leave_date ELSE NULL END) STORED',
        'note' => 'TEXT NULL',
        'raw_input' => 'TEXT NULL',
        'source' => 'VARCHAR(40) NOT NULL DEFAULT \'manual\'',
        'user_name' => 'VARCHAR(80) NULL',
        'is_deleted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'deleted_at' => 'DATETIME NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
    'monthly_salary_records' => [
        'salary_month' => 'CHAR(7) NOT NULL DEFAULT \'0000-00\'',
        'base_salary' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'full_attendance_bonus' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'attendance_allowance' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'overtime_pay_134' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'overtime_pay_167' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'meal_fee' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'night_snack_fee' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'labor_insurance_deduction' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'health_insurance_deduction' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'gross_salary' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'net_salary' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'note' => 'TEXT NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
    'monthly_financial_snapshots' => [
        'accounting_month' => 'CHAR(7) NOT NULL DEFAULT \'0000-00\'',
        'total_income' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'total_expense' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'net_amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'snapshot_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'note' => 'TEXT NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
    'ai_parse_logs' => [
        'raw_input' => 'TEXT NULL',
        'ai_response' => 'MEDIUMTEXT NULL',
        'provider' => 'VARCHAR(40) NULL',
        'model_name' => 'VARCHAR(120) NULL',
        'parsed_type' => 'VARCHAR(40) NULL',
        'parsed_json' => 'MEDIUMTEXT NULL',
        'parse_status' => 'VARCHAR(40) NOT NULL DEFAULT \'pending\'',
        'error_code' => 'VARCHAR(80) NULL',
        'error_message' => 'TEXT NULL',
        'duration_ms' => 'INT UNSIGNED NULL',
        'source' => 'VARCHAR(40) NULL',
        'user_name' => 'VARCHAR(80) NULL',
        'entry_owner' => 'VARCHAR(32) NOT NULL DEFAULT \'profile_a\'',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
    'ai_settings' => [
        'is_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'provider' => 'VARCHAR(40) NOT NULL DEFAULT \'local\'',
        'model_name' => 'VARCHAR(120) NOT NULL DEFAULT \'\'',
        'temperature' => 'DECIMAL(3,2) NOT NULL DEFAULT 0.10',
        'max_tokens' => 'INT UNSIGNED NOT NULL DEFAULT 1000',
        'save_raw_response' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'allow_expense' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'allow_income' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'allow_overtime' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'allow_leave' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
    'ai_ledger_links' => [
        'ai_parse_log_id' => 'BIGINT UNSIGNED NOT NULL',
        'ledger_table' => 'VARCHAR(40) NOT NULL',
        'ledger_id' => 'BIGINT UNSIGNED NOT NULL',
        'action' => 'VARCHAR(20) NOT NULL',
        'source' => 'VARCHAR(40) NOT NULL DEFAULT \'quick_pwa\'',
        'raw_input_snapshot' => 'TEXT NULL',
        'parsed_type_snapshot' => 'VARCHAR(40) NULL',
        'parsed_json_snapshot' => 'MEDIUMTEXT NULL',
        'user_name' => 'VARCHAR(80) NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],
    'import_jobs' => [
        'import_type' => 'VARCHAR(40) NOT NULL DEFAULT \'manual\'',
        'original_filename' => 'VARCHAR(255) NULL',
        'status' => 'VARCHAR(40) NOT NULL DEFAULT \'pending\'',
        'total_rows' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'success_rows' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'failed_rows' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'error_message' => 'TEXT NULL',
        'created_by' => 'VARCHAR(80) NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
    'leave_types' => [
        'name' => 'VARCHAR(80) NOT NULL',
        'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'sort_order' => 'INT NOT NULL DEFAULT 0',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
    'monthly_work_settings' => [
        'work_month' => 'CHAR(7) NOT NULL DEFAULT \'0000/00\'',
        'expected_work_days' => 'DECIMAL(5,2) NOT NULL DEFAULT 0.00',
        'note' => 'TEXT NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
];

foreach ($columns as $table => $tableColumns) {
    if (!table_exists($pdo, $table)) {
        continue;
    }
    foreach ($tableColumns as $column => $definition) {
        add_column($pdo, $table, $column, $definition);
    }
}

if (table_exists($pdo, 'expenses') && column_exists($pdo, 'expenses', 'entry_owner')) {
    $pdo->exec("UPDATE expenses SET entry_owner = 'profile_a' WHERE entry_owner IS NULL OR entry_owner NOT IN ('profile_a', 'profile_b')");
}
if (table_exists($pdo, 'ai_parse_logs') && column_exists($pdo, 'ai_parse_logs', 'entry_owner')) {
    $pdo->exec("UPDATE ai_parse_logs SET entry_owner = 'profile_a' WHERE entry_owner IS NULL OR entry_owner NOT IN ('profile_a', 'profile_b')");
}

if (table_exists($pdo, 'payment_methods')
    && column_exists($pdo, 'payment_methods', 'settlement_start_day')
    && column_exists($pdo, 'payment_methods', 'settlement_end_day')
    && column_exists($pdo, 'payment_methods', 'cycle_start_day')
    && column_exists($pdo, 'payment_methods', 'cycle_end_day')) {
    $pdo->exec(
        'UPDATE payment_methods
         SET settlement_start_day = cycle_start_day,
             settlement_end_day = cycle_end_day
         WHERE settlement_start_day = 1 AND settlement_end_day = 31
           AND (cycle_start_day <> 1 OR cycle_end_day <> 31)'
    );
}

add_index($pdo, 'settings', 'uniq_settings_setting_key', 'UNIQUE KEY uniq_settings_setting_key (setting_key)');
add_index($pdo, 'payment_methods', 'uniq_payment_methods_name', 'UNIQUE KEY uniq_payment_methods_name (name)');
add_index($pdo, 'accounts', 'uniq_accounts_name', 'UNIQUE KEY uniq_accounts_name (name)');
add_index($pdo, 'overtime_logs', 'uniq_overtime_logs_work_date', 'UNIQUE KEY uniq_overtime_logs_work_date (work_date)');
add_index($pdo, 'expenses', 'idx_expenses_accounting_month', 'KEY idx_expenses_accounting_month (accounting_month)');
add_index($pdo, 'expenses', 'idx_expenses_entry_owner', 'KEY idx_expenses_entry_owner (entry_owner)');
add_index($pdo, 'incomes', 'idx_incomes_accounting_month', 'KEY idx_incomes_accounting_month (accounting_month)');
add_index($pdo, 'overtime_logs', 'idx_overtime_logs_source', 'KEY idx_overtime_logs_source (source)');
add_index($pdo, 'leave_logs', 'idx_leave_logs_source', 'KEY idx_leave_logs_source (source)');
add_index($pdo, 'ai_ledger_links', 'idx_ai_ledger_links_log', 'KEY idx_ai_ledger_links_log (ai_parse_log_id)');
add_index($pdo, 'ai_ledger_links', 'idx_ai_ledger_links_ledger', 'KEY idx_ai_ledger_links_ledger (ledger_table, ledger_id)');
add_index($pdo, 'ai_ledger_links', 'idx_ai_ledger_links_source_created', 'KEY idx_ai_ledger_links_source_created (source, created_at)');
add_index($pdo, 'ai_parse_logs', 'idx_ai_parse_logs_entry_owner_created', 'KEY idx_ai_parse_logs_entry_owner_created (entry_owner, created_at)');
add_index($pdo, 'monthly_salary_records', 'uniq_monthly_salary_records_month', 'UNIQUE KEY uniq_monthly_salary_records_month (salary_month)');
add_index($pdo, 'monthly_financial_snapshots', 'uniq_monthly_financial_snapshots_month', 'UNIQUE KEY uniq_monthly_financial_snapshots_month (accounting_month)');
add_index($pdo, 'monthly_work_settings', 'uniq_monthly_work_settings_work_month', 'UNIQUE KEY uniq_monthly_work_settings_work_month (work_month)');
add_index($pdo, 'leave_types', 'uniq_leave_types_name', 'UNIQUE KEY uniq_leave_types_name (name)');

add_foreign_key(
    $pdo,
    'ai_ledger_links',
    'fk_ai_ledger_links_log',
    'FOREIGN KEY (ai_parse_log_id) REFERENCES ai_parse_logs (id) ON DELETE RESTRICT'
);

$leaveTypes = [
    ['特休', 10],
    ['事假', 20],
    ['病假', 30],
];

$insertLeaveType = $pdo->prepare(
    'INSERT INTO leave_types (name, sort_order)
     VALUES (:name, :sort_order)
     ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)'
);

foreach ($leaveTypes as [$name, $sortOrder]) {
    $insertLeaveType->execute(['name' => $name, 'sort_order' => $sortOrder]);
}

$duplicateLeaveDates = $pdo->query(
    'SELECT leave_date, COUNT(*) AS cnt
     FROM leave_logs
     WHERE is_deleted = 0
     GROUP BY leave_date
     HAVING cnt > 1
     ORDER BY leave_date'
)->fetchAll();

if ($duplicateLeaveDates) {
    echo "Duplicate leave_dates found. Resolve them before adding UNIQUE index.\n";
    foreach ($duplicateLeaveDates as $row) {
        echo $row['leave_date'] . ':' . $row['cnt'] . "\n";
    }
    exit(2);
}

add_index(
    $pdo,
    'leave_logs',
    'uniq_leave_logs_leave_date',
    'UNIQUE KEY uniq_leave_logs_leave_date (active_leave_date)'
);

$settings = [
    ['base_salary', '底薪', '0.00', '元', 10],
    ['full_attendance_bonus', '全勤獎金', '0.00', '元', 20],
    ['attendance_allowance_unit', '出勤津貼單價', '0.00', '元', 30],
    ['overtime_134_hourly_rate', '加班1.34時薪', '0.00', '元', 40],
    ['overtime_167_hourly_rate', '加班1.67時薪', '0.00', '元', 50],
    ['overtime_2h_meal_fee', '加班2H誤餐費', '0.00', '元', 60],
    ['overtime_3h_night_snack_fee', '加班3H夜點費', '0.00', '元', 70],
    ['labor_insurance_deduction', '勞保扣款', '0.00', '元', 80],
    ['health_insurance_deduction', '健保扣款', '0.00', '元', 90],
    ['annual_special_leave_days', '年度特休總天數', '0.00', '天', 100],
    ['default_work_days', '預設應工作天', '0.00', '天', 110],
];

$insertSetting = $pdo->prepare(
    'INSERT INTO settings (setting_key, label, numeric_value, unit, sort_order)
     VALUES (:setting_key, :label, :numeric_value, :unit, :sort_order)
     ON DUPLICATE KEY UPDATE label = VALUES(label), unit = VALUES(unit), sort_order = VALUES(sort_order)'
);

foreach ($settings as [$key, $label, $value, $unit, $sortOrder]) {
    $insertSetting->execute([
        'setting_key' => $key,
        'label' => $label,
        'numeric_value' => $value,
        'unit' => $unit,
        'sort_order' => $sortOrder,
    ]);
}

$pdo->exec(
    "INSERT INTO ai_settings (
        id, is_enabled, provider, model_name, temperature, max_tokens,
        save_raw_response, allow_expense, allow_income, allow_overtime, allow_leave
     ) VALUES (1, 0, 'local', '', 0.10, 1000, 0, 1, 1, 1, 1)
     ON DUPLICATE KEY UPDATE id = id"
);

$paymentMethods = [
    ['展示方式 A', 10, 9, 10],
    ['展示方式 B', 10, 9, 20],
    ['展示方式 C', 7, 6, 30],
    ['現金', 1, 31, 40],
];

$insertPaymentMethod = $pdo->prepare(
    'INSERT INTO payment_methods (name, settlement_start_day, settlement_end_day, cycle_start_day, cycle_end_day, sort_order)
     VALUES (:name, :settlement_start_day, :settlement_end_day, :cycle_start_day, :cycle_end_day, :sort_order)
     ON DUPLICATE KEY UPDATE
        settlement_start_day = VALUES(settlement_start_day),
        settlement_end_day = VALUES(settlement_end_day),
        cycle_start_day = VALUES(cycle_start_day),
        cycle_end_day = VALUES(cycle_end_day),
        sort_order = VALUES(sort_order)'
);

foreach ($paymentMethods as [$name, $startDay, $endDay, $sortOrder]) {
    $insertPaymentMethod->execute([
        'name' => $name,
        'settlement_start_day' => $startDay,
        'settlement_end_day' => $endDay,
        'cycle_start_day' => $startDay,
        'cycle_end_day' => $endDay,
        'sort_order' => $sortOrder,
    ]);
}

$accounts = [
    ['現金', 10],
    ['展示帳戶 A', 20],
    ['展示帳戶 B', 30],
    ['展示帳戶 C', 40],
];

$insertAccount = $pdo->prepare(
    'INSERT INTO accounts (name, sort_order)
     VALUES (:name, :sort_order)
     ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)'
);

foreach ($accounts as [$name, $sortOrder]) {
    $insertAccount->execute(['name' => $name, 'sort_order' => $sortOrder]);
}

$result = $pdo->query(
    "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

echo "Migration completed\n";
foreach ($result as $table) {
    echo $table . "\n";
}
