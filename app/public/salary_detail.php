<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/src/auth.php";
require_once dirname(__DIR__) . "/src/database.php";
require_once dirname(__DIR__) . "/src/html.php";
require_once dirname(__DIR__) . "/src/form.php";

require_login();

$pdo = app_db();
$selectedMonth = normalize_month((string) ($_GET["month"] ?? date("Y/m")));
if ($selectedMonth === "") {
    $selectedMonth = date("Y/m");
}

$monthOptions = $pdo->query(<<<SQL
SELECT month_value FROM (
    SELECT DATE_FORMAT(work_date, "%Y/%m") AS month_value FROM overtime_logs WHERE work_date IS NOT NULL
    UNION
    SELECT DATE_FORMAT(leave_date, "%Y/%m") AS month_value FROM leave_logs WHERE leave_date IS NOT NULL
    UNION
    SELECT REPLACE(work_month, "-", "/") AS month_value FROM monthly_work_settings WHERE work_month IS NOT NULL AND work_month <> ""
    UNION
    SELECT REPLACE(salary_month, "-", "/") AS month_value FROM monthly_salary_records WHERE salary_month IS NOT NULL AND salary_month <> ""
) months
WHERE month_value REGEXP "^[0-9]{4}/[0-9]{2}$"
  AND month_value <> "0000/00"
ORDER BY month_value DESC
SQL)->fetchAll(PDO::FETCH_COLUMN);

if (!in_array(date("Y/m"), $monthOptions, true)) {
    $monthOptions[] = date("Y/m");
}
if (!in_array($selectedMonth, $monthOptions, true)) {
    $monthOptions[] = $selectedMonth;
}
rsort($monthOptions);

$settingsRows = $pdo->query(
    "SELECT setting_key, label, numeric_value, unit FROM settings WHERE is_active = 1"
)->fetchAll();
$settings = [];
foreach ($settingsRows as $row) {
    $settings[$row["setting_key"]] = $row;
}

function setting_amount(array $settings, string $key): float
{
    return (float) ($settings[$key]["numeric_value"] ?? 0);
}

function setting_label(array $settings, string $key, string $fallback): string
{
    return (string) ($settings[$key]["label"] ?? $fallback);
}

$workSetting = $pdo->prepare("SELECT expected_work_days FROM monthly_work_settings WHERE work_month = :month LIMIT 1");
$workSetting->execute(["month" => $selectedMonth]);
$expectedWorkDays = $workSetting->fetchColumn();
$workDaysSource = "每月設定";
if ($expectedWorkDays === false) {
    $expectedWorkDays = setting_amount($settings, "default_work_days");
    $workDaysSource = "預設值";
}
$expectedWorkDays = (float) $expectedWorkDays;

$likeMonth = str_replace("/", "-", $selectedMonth) . "-%";

$overtimeStatement = $pdo->prepare(
    "SELECT
        COALESCE(SUM(overtime_hours), 0) AS total_hours,
        COALESCE(SUM(LEAST(overtime_hours, 2)), 0) AS hours_134,
        COALESCE(SUM(GREATEST(overtime_hours - 2, 0)), 0) AS hours_167,
        SUM(CASE WHEN overtime_hours = 2.00 THEN 1 ELSE 0 END) AS two_hour_days,
        SUM(CASE WHEN overtime_hours = 3.00 THEN 1 ELSE 0 END) AS three_hour_days
     FROM overtime_logs
     WHERE is_deleted = 0 AND work_date LIKE :month"
);
$overtimeStatement->execute(["month" => $likeMonth]);
$overtime = $overtimeStatement->fetch() ?: [];

$leaveStatement = $pdo->prepare(
    "SELECT
        COALESCE(SUM(total_leave_days), 0) AS leave_days,
        COALESCE(SUM(leave_hours), 0) AS leave_hours,
        COALESCE(SUM(CASE WHEN leave_type = :special_leave_type THEN total_leave_days ELSE 0 END), 0) AS special_leave_days
     FROM leave_logs
     WHERE is_deleted = 0 AND leave_date LIKE :month"
);
$leaveStatement->execute([
    "month" => $likeMonth,
    "special_leave_type" => "特休",
]);
$leave = $leaveStatement->fetch() ?: [];

$selectedYear = substr($selectedMonth, 0, 4);
$yearStart = $selectedYear . "-01-01";
$monthEndDate = DateTimeImmutable::createFromFormat("!Y/m/d", $selectedMonth . "/01");
$monthEnd = $monthEndDate instanceof DateTimeImmutable
    ? $monthEndDate->modify("last day of this month")->format("Y-m-d")
    : $selectedYear . "-12-31";
$yearEnd = $selectedYear . "-12-31";
$annualLeaveStatement = $pdo->prepare(
    "SELECT COALESCE(SUM(total_leave_days), 0)
     FROM leave_logs
     WHERE is_deleted = 0
       AND leave_type = :leave_type
       AND leave_date BETWEEN :year_start AND :year_end"
);
$annualLeaveStatement->execute([
    "leave_type" => "特休",
    "year_start" => $yearStart,
    "year_end" => $yearEnd,
]);
$usedAnnualSpecialLeaveDays = (float) $annualLeaveStatement->fetchColumn();
$annualLeaveThroughMonthStatement = $pdo->prepare(
    "SELECT COALESCE(SUM(total_leave_days), 0)
     FROM leave_logs
     WHERE is_deleted = 0
       AND leave_type = :leave_type
       AND leave_date BETWEEN :year_start AND :month_end"
);
$annualLeaveThroughMonthStatement->execute([
    "leave_type" => "特休",
    "year_start" => $yearStart,
    "month_end" => $monthEnd,
]);
$usedAnnualSpecialLeaveDaysThroughMonth = (float) $annualLeaveThroughMonthStatement->fetchColumn();

$baseSalary = setting_amount($settings, "base_salary");
$fullAttendanceBonusSetting = setting_amount($settings, "full_attendance_bonus");
$attendanceAllowanceUnit = setting_amount($settings, "attendance_allowance_unit");
$overtime134Rate = setting_amount($settings, "overtime_134_hourly_rate");
$overtime167Rate = setting_amount($settings, "overtime_167_hourly_rate");
$mealFeeUnit = setting_amount($settings, "overtime_2h_meal_fee");
$nightSnackFeeUnit = setting_amount($settings, "overtime_3h_night_snack_fee");
$laborInsuranceDeduction = setting_amount($settings, "labor_insurance_deduction");
$healthInsuranceDeduction = setting_amount($settings, "health_insurance_deduction");
$annualSpecialLeaveDays = setting_amount($settings, "annual_special_leave_days");
$remainingAnnualSpecialLeaveDays = max($annualSpecialLeaveDays - $usedAnnualSpecialLeaveDays, 0);

$leaveDays = (float) ($leave["leave_days"] ?? 0);
$specialLeaveDays = (float) ($leave["special_leave_days"] ?? 0);
$fullAttendanceLeaveDays = $leaveDays;
if ($annualSpecialLeaveDays > 0 && $usedAnnualSpecialLeaveDaysThroughMonth <= $annualSpecialLeaveDays) {
    $fullAttendanceLeaveDays = max($leaveDays - $specialLeaveDays, 0);
}
$attendanceDays = max($expectedWorkDays - $leaveDays, 0);
$fullAttendanceBonus = $fullAttendanceLeaveDays > 0 ? 0 : $fullAttendanceBonusSetting;
$attendanceAllowance = $attendanceDays * $attendanceAllowanceUnit;
$overtimePay134 = (float) ($overtime["hours_134"] ?? 0) * $overtime134Rate;
$overtimePay167 = (float) ($overtime["hours_167"] ?? 0) * $overtime167Rate;
$mealFee = (float) ($overtime["two_hour_days"] ?? 0) * $mealFeeUnit;
$nightSnackFee = (float) ($overtime["three_hour_days"] ?? 0) * $nightSnackFeeUnit;
$grossSalary = $baseSalary + $fullAttendanceBonus + $attendanceAllowance + $overtimePay134 + $overtimePay167 + $mealFee + $nightSnackFee;
$deductions = $laborInsuranceDeduction + $healthInsuranceDeduction;
$netSalary = $grossSalary - $deductions;

$items = [];
if (isset($settings["base_salary"])) {
    $items[] = [setting_label($settings, "base_salary", "底薪"), $baseSalary, "薪資設定"];
}
if (isset($settings["full_attendance_bonus"])) {
    $items[] = [setting_label($settings, "full_attendance_bonus", "全勤獎金"), $fullAttendanceBonus, $fullAttendanceLeaveDays > 0 ? "有需扣全勤的請假，試算為 0" : "薪資設定"];
}
if (isset($settings["attendance_allowance_unit"])) {
    $items[] = [setting_label($settings, "attendance_allowance_unit", "出勤津貼"), $attendanceAllowance, format_number_clean($attendanceDays) . " 天 × " . format_number_clean($attendanceAllowanceUnit)];
}
if (isset($settings["overtime_134_hourly_rate"])) {
    $items[] = ["加班 1.34", $overtimePay134, format_number_clean($overtime["hours_134"] ?? 0) . " H × " . format_number_clean($overtime134Rate)];
}
if (isset($settings["overtime_167_hourly_rate"])) {
    $items[] = ["加班 1.67", $overtimePay167, format_number_clean($overtime["hours_167"] ?? 0) . " H × " . format_number_clean($overtime167Rate)];
}
if (isset($settings["overtime_2h_meal_fee"])) {
    $items[] = [setting_label($settings, "overtime_2h_meal_fee", "加班2H誤餐費"), $mealFee, format_number_clean($overtime["two_hour_days"] ?? 0) . " 天 × " . format_number_clean($mealFeeUnit)];
}
if (isset($settings["overtime_3h_night_snack_fee"])) {
    $items[] = [setting_label($settings, "overtime_3h_night_snack_fee", "加班3H夜點費"), $nightSnackFee, format_number_clean($overtime["three_hour_days"] ?? 0) . " 天 × " . format_number_clean($nightSnackFeeUnit)];
}
if (isset($settings["labor_insurance_deduction"])) {
    $items[] = [setting_label($settings, "labor_insurance_deduction", "勞保扣款"), -$laborInsuranceDeduction, "薪資設定扣款"];
}
if (isset($settings["health_insurance_deduction"])) {
    $items[] = [setting_label($settings, "health_insurance_deduction", "健保扣款"), -$healthInsuranceDeduction, "薪資設定扣款"];
}
if (isset($settings["annual_special_leave_days"])) {
    $items[] = ["年度剩餘特休", $remainingAnnualSpecialLeaveDays, $selectedYear . " 年度共 " . format_number_clean($annualSpecialLeaveDays) . " 天，已休 " . format_number_clean($usedAnnualSpecialLeaveDays) . " 天"];
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>薪資明細</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="page">
        <div class="page-header">
            <div>
                <h1>薪資明細</h1>
                <p>依薪資設定、當月加班與請假資料試算，不會寫入薪資紀錄。</p>
            </div>
            <nav class="nav">
                <a href="/dashboard.php">主控台</a>
                <a href="/settings.php">薪資設定</a>
                <a href="/monthly_work_settings.php">每月工作天</a>
            </nav>
        </div>

        <section class="form-panel dashboard-month-panel">
            <form class="grid-form month-form" method="get">
                <label>月份
                    <select name="month" required onchange="this.form.submit()">
                        <?php foreach ($monthOptions as $month): ?>
                            <option value="<?= h($month) ?>" <?= $month === $selectedMonth ? "selected" : "" ?>><?= h($month) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="month-submit" type="submit">切換月份</button>
            </form>
        </section>

        <section class="summary-grid">
            <div class="summary-card primary"><span>試算實領</span><strong><?= h(format_number_clean($netSalary)) ?></strong></div>
            <div class="summary-card"><span>應發合計</span><strong><?= h(format_number_clean($grossSalary)) ?></strong></div>
            <div class="summary-card"><span>扣款合計</span><strong><?= h(format_number_clean($deductions)) ?></strong></div>
            <div class="summary-card"><span>應工作天（<?= h($workDaysSource) ?>）</span><strong><?= h(format_number_clean($expectedWorkDays)) ?></strong></div>
            <?php if (isset($settings["annual_special_leave_days"])): ?>
                <div class="summary-card"><span><?= h($selectedYear) ?> 剩餘特休</span><strong><?= h(format_number_clean($remainingAnnualSpecialLeaveDays)) ?></strong></div>
            <?php endif; ?>
        </section>

        <section class="table-panel record-panel">
            <div class="section-title-row">
                <h2><?= h($selectedMonth) ?> 薪資項目</h2>
                <span class="muted">試算明細</span>
            </div>
            <div class="record-list salary-list">
                <?php if ($items === []): ?>
                    <article class="record-card salary-item">
                        <div class="record-main"><strong>目前沒有啟用中的薪資項目設定。</strong></div>
                    </article>
                <?php endif; ?>
                <?php foreach ($items as [$label, $amount, $note]): ?>
                    <article class="record-card salary-item">
                        <div class="record-main">
                            <div class="record-title">
                                <strong><?= h($label) ?></strong>
                                <span><?= h($note) ?></span>
                            </div>
                            <div class="record-amount <?= $amount < 0 ? "expense-amount" : "income-amount" ?>"><?= h(format_number_clean($amount)) ?></div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
    <?php render_mobile_nav("more"); ?>
</body>
</html>
