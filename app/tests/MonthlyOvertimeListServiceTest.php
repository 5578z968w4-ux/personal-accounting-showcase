<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/MonthlyOvertimeListService.php';

function overtime_list_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE overtime_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    work_date TEXT UNIQUE,
    overtime_hours REAL,
    raw_input TEXT,
    note TEXT,
    user_name TEXT,
    source TEXT,
    is_deleted INTEGER DEFAULT 0,
    deleted_at TEXT
)');

$pdo->exec("INSERT INTO overtime_logs (work_date, overtime_hours, raw_input, note, user_name, source, is_deleted)
    VALUES ('2026-07-01', 2.0, '7月1日', 'note', 'tester', 'manual', 0)");
$pdo->exec("INSERT INTO overtime_logs (work_date, overtime_hours, raw_input, note, user_name, source, is_deleted)
    VALUES ('2026-06-30', 9.0, '6月30日', 'note', 'tester', 'manual', 0)");
$pdo->exec("INSERT INTO overtime_logs (work_date, overtime_hours, raw_input, note, user_name, source, is_deleted)
    VALUES ('2026-07-03', 1.5, '7月3日', 'note', 'tester', 'manual', 0)");
$pdo->exec("INSERT INTO overtime_logs (work_date, overtime_hours, raw_input, note, user_name, source, is_deleted)
    VALUES ('2026-08-01', 4.0, '8月1日', 'note', 'tester', 'manual', 0)");
$pdo->exec("INSERT INTO overtime_logs (work_date, overtime_hours, raw_input, note, user_name, source, is_deleted)
    VALUES ('2026-07-10', 2.0, '7月10日', 'note', 'tester', 'manual', 1)");

$service = new MonthlyOvertimeListService($pdo);
$rows = $service->listByMonth('2026/07');

overtime_list_assert(count($rows) === 2, 'Current month overtime rows should be exactly 2');
overtime_list_assert($rows[0]['work_date'] === '2026-07-01', 'Current month sorting should be date ascending by work_date');
overtime_list_assert($rows[1]['work_date'] === '2026-07-03', 'Current month sorting should keep same-day rows in id order');
overtime_list_assert($rows[0]['display_date'] === '26.07.01', 'Overtime date display format should be yy.mm.dd');
overtime_list_assert($rows[1]['weekday_text'] === '星期五', 'Weekday label for 2026-07-03 should be 星期五');
overtime_list_assert($rows[1]['display_line'] === '26.07.03 星期五　1.5 小時', 'Overtime row text format mismatch.');
overtime_list_assert($service->formatHours(1) === '1.0', 'Hours format should keep one decimal place for integer values.');

$totalJuly = $service->totalHoursByMonth('2026/07');
overtime_list_assert(abs($totalJuly - 3.5) < 0.0001, 'Current month total hours mismatch');

$augustRows = $service->listByMonth('2026-08');
overtime_list_assert(count($augustRows) === 1, 'Month query value YYYY-MM should switch the overtime list');
overtime_list_assert($augustRows[0]['work_date'] === '2026-08-01', 'Selected month should list August overtime data');
$totalAugust = $service->totalHoursByMonth('2026-08');
overtime_list_assert(abs($totalAugust - 4.0) < 0.0001, 'Selected month total hours mismatch');

$availableMonths = $service->availableMonths('2026/07');
overtime_list_assert($availableMonths === ['2026/08', '2026/07', '2026/06'], 'Available overtime months should come from active rows plus fallback month');
overtime_list_assert($service->monthQueryValue('2026/08') === '2026-08', 'Month query value should use YYYY-MM');

$nonCurrentRows = $service->listByMonth('2026/09');
overtime_list_assert($nonCurrentRows === [], 'Non current month data should not be listed');
$emptyTotal = $service->totalHoursByMonth('2026/09');
overtime_list_assert($emptyTotal === 0.0, 'No rows month should have zero total hours');

$selectedEmptyMonths = $service->availableMonths('2026/07', '2026/09');
overtime_list_assert(in_array('2026/09', $selectedEmptyMonths, true), 'Selected empty month should remain available in the month menu');

echo "MonthlyOvertimeListServiceTest passed\n";
