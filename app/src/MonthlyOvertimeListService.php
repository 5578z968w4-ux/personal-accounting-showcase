<?php

declare(strict_types=1);

final class MonthlyOvertimeListService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByMonth(string $month): array
    {
        $month = $this->normalizeMonth($month);

        $statement = $this->pdo->prepare(
            'SELECT id, work_date, overtime_hours, user_name, raw_input, source
             FROM overtime_logs
             WHERE is_deleted = 0
               AND work_date LIKE :month
             ORDER BY work_date ASC, id ASC'
        );
        $statement->execute(['month' => $this->monthLikePattern($month)]);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $workDate = (string) ($row['work_date'] ?? '');
            $hours = (float) ($row['overtime_hours'] ?? 0);
            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'work_date' => $workDate,
                'overtime_hours' => $hours,
                'user_name' => (string) ($row['user_name'] ?? ''),
                'raw_input' => (string) ($row['raw_input'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
                'display_date' => $this->formatDate($workDate),
                'weekday_text' => $this->formatWeekday($workDate),
                'hours_text' => $this->formatHours($hours),
                'display_line' => $this->formatDate($workDate) . ' ' . $this->formatWeekday($workDate) . '　' . $this->formatHours($hours) . ' 小時',
            ];
        }

        return $result;
    }

    public function totalHoursByMonth(string $month): float
    {
        $month = $this->normalizeMonth($month);
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(overtime_hours), 0) AS total_hours
             FROM overtime_logs
             WHERE is_deleted = 0
               AND work_date LIKE :month'
        );
        $statement->execute(['month' => $this->monthLikePattern($month)]);

        return (float) ($statement->fetchColumn() ?? 0);
    }

    /**
     * @return list<string>
     */
    public function availableMonths(string $fallbackMonth, ?string $selectedMonth = null): array
    {
        $months = [
            $this->normalizeMonth($fallbackMonth) => true,
        ];

        if ($selectedMonth !== null) {
            $months[$this->normalizeMonth($selectedMonth)] = true;
        }

        $statement = $this->pdo->query(
            'SELECT work_date
             FROM overtime_logs
             WHERE is_deleted = 0
             ORDER BY work_date DESC'
        );
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $workDate) {
            $workDate = (string) $workDate;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate) === 1) {
                $months[str_replace('-', '/', substr($workDate, 0, 7))] = true;
            }
        }

        $result = array_keys($months);
        rsort($result);

        return $result;
    }

    public function monthQueryValue(string $month): string
    {
        return str_replace('/', '-', $this->normalizeMonth($month));
    }

    public function formatHours(float $hours): string
    {
        $formatted = number_format($hours, 2, '.', '');

        return str_ends_with($formatted, '.00')
            ? number_format($hours, 1, '.', '')
            : rtrim(rtrim($formatted, '0'), '.');
    }

    private function normalizeMonth(string $month): string
    {
        $month = trim($month);
        if ($month === '') {
            return date('Y/m');
        }

        if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return str_replace('-', '/', $month);
        }

        if (preg_match('/^\d{4}\/\d{2}$/', $month) === 1) {
            return $month;
        }

        return date('Y/m');
    }

    private function monthLikePattern(string $month): string
    {
        return str_replace('/', '-', $month) . '-%';
    }

    private function formatDate(string $workDate): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $workDate, new DateTimeZone('Asia/Taipei'));
        if (!$date) {
            return $workDate;
        }

        return $date->format('y.m.d');
    }

    private function formatWeekday(string $workDate): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $workDate, new DateTimeZone('Asia/Taipei'));
        if (!$date) {
            return '星期日';
        }

        $weekdays = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'];

        return $weekdays[(int) $date->format('w')];
    }
}
