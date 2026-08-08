<?php

declare(strict_types=1);

final class ExpenseKeywordAnalyticsService
{
    public const PERIOD_ACCOUNTING_MONTH = 'accounting_month';
    public const PERIOD_RECORD_DATE = 'record_date';
    public const CATEGORY_ALL = 'all';
    public const CATEGORY_UNCATEGORIZED = '__uncategorized__';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *     period_type: string,
     *     period_from: string,
     *     period_to: string,
     *     keyword: string,
     *     entry_owner: string,
     *     payment_method_id: int,
     *     category: string,
     *     total_amount: float,
     *     row_count: int,
     *     average_amount: float,
     *     rows: array<int, array<string, mixed>>
     * }
     */
    public function search(
        string $periodType,
        string $periodFrom,
        string $periodTo,
        string $keyword = '',
        string $entryOwner = 'all',
        int $paymentMethodId = 0,
        string $category = self::CATEGORY_ALL,
        int $limit = 100,
        int $offset = 0
    ): array {
        [$periodType, $periodFrom, $periodTo] = $this->normalizePeriod($periodType, $periodFrom, $periodTo);
        $keyword = trim($keyword);
        if (!in_array($entryOwner, ['all', 'profile_a', 'profile_b'], true)) {
            $entryOwner = 'all';
        }
        $paymentMethodId = max(0, $paymentMethodId);
        $category = trim($category);
        if ($category === '') {
            $category = self::CATEGORY_ALL;
        }
        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        [$where, $params] = $this->expenseWhere(
            $periodType,
            $periodFrom,
            $periodTo,
            $keyword,
            $entryOwner,
            $paymentMethodId,
            $category
        );

        $summaryStatement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS total_amount, COUNT(*) AS row_count
             FROM expenses
             WHERE ' . $where
        );
        $summaryStatement->execute($params);
        $summary = $summaryStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $rowCount = (int) ($summary['row_count'] ?? 0);
        $totalAmount = (float) ($summary['total_amount'] ?? 0);

        $rowsStatement = $this->pdo->prepare(
            'SELECT id, record_date, item, amount, payment_method_id, payment_method, accounting_month, category, entry_owner
             FROM expenses
             WHERE ' . $where . '
             ORDER BY record_date DESC, id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $rowsStatement->execute($params);

        return [
            'period_type' => $periodType,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'keyword' => $keyword,
            'entry_owner' => $entryOwner,
            'payment_method_id' => $paymentMethodId,
            'category' => $category,
            'total_amount' => $totalAmount,
            'row_count' => $rowCount,
            'average_amount' => $rowCount > 0 ? $totalAmount / $rowCount : 0.0,
            'rows' => $rowsStatement->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /** @return list<string> */
    public function categoryOptions(string $periodType, string $periodFrom, string $periodTo): array
    {
        [$periodType, $periodFrom, $periodTo] = $this->normalizePeriod($periodType, $periodFrom, $periodTo);
        [$periodWhere, $params] = $this->periodWhere($periodType, $periodFrom, $periodTo);

        $statement = $this->pdo->prepare(
            'SELECT DISTINCT category
             FROM expenses
             WHERE ' . $periodWhere . '
               AND is_deleted = 0
               AND (deleted_at IS NULL OR deleted_at = \'\')
             ORDER BY category'
        );
        $statement->execute($params);

        $categories = [];
        $hasUncategorized = false;
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $category) {
            $category = trim((string) $category);
            if ($category === '') {
                $hasUncategorized = true;
                continue;
            }
            $categories[$category] = true;
        }

        $options = array_keys($categories);
        if ($hasUncategorized) {
            $options[] = self::CATEGORY_UNCATEGORIZED;
        }

        return $options;
    }

    /**
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function expenseWhere(
        string $periodType,
        string $periodFrom,
        string $periodTo,
        string $keyword,
        string $entryOwner,
        int $paymentMethodId,
        string $category
    ): array {
        [$periodWhere, $params] = $this->periodWhere($periodType, $periodFrom, $periodTo);
        $where = [
            $periodWhere,
            'is_deleted = 0',
            "(deleted_at IS NULL OR deleted_at = '')",
        ];

        if ($entryOwner !== 'all') {
            $where[] = 'entry_owner = :entry_owner';
            $params['entry_owner'] = $entryOwner;
        }

        if ($paymentMethodId > 0) {
            $where[] = 'payment_method_id = :payment_method_id';
            $params['payment_method_id'] = $paymentMethodId;
        }

        if ($category === self::CATEGORY_UNCATEGORIZED) {
            $where[] = "(category IS NULL OR TRIM(category) = '')";
        } elseif ($category !== self::CATEGORY_ALL) {
            $where[] = 'TRIM(category) = :category';
            $params['category'] = $category;
        }

        if ($keyword !== '') {
            $where[] = '(item LIKE :item_keyword OR category LIKE :category_keyword)';
            $params['item_keyword'] = '%' . $keyword . '%';
            $params['category_keyword'] = '%' . $keyword . '%';
        }

        return [implode("\n            AND ", $where), $params];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function periodWhere(string $periodType, string $periodFrom, string $periodTo): array
    {
        $column = $periodType === self::PERIOD_RECORD_DATE ? 'record_date' : 'accounting_month';

        return [
            $column . ' BETWEEN :period_from AND :period_to',
            [
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
            ],
        ];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function normalizePeriod(string $periodType, string $periodFrom, string $periodTo): array
    {
        if (!in_array($periodType, [self::PERIOD_ACCOUNTING_MONTH, self::PERIOD_RECORD_DATE], true)) {
            $periodType = self::PERIOD_ACCOUNTING_MONTH;
        }

        $valid = $periodType === self::PERIOD_RECORD_DATE
            ? $this->validDate($periodFrom) && $this->validDate($periodTo)
            : preg_match('/^[0-9]{4}\/(0[1-9]|1[0-2])$/', $periodFrom) === 1
                && preg_match('/^[0-9]{4}\/(0[1-9]|1[0-2])$/', $periodTo) === 1;
        if (!$valid) {
            throw new InvalidArgumentException('Invalid analytics period.');
        }

        if ($periodFrom > $periodTo) {
            [$periodFrom, $periodTo] = [$periodTo, $periodFrom];
        }

        return [$periodType, $periodFrom, $periodTo];
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Taipei'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
