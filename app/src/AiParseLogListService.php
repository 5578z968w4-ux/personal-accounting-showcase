<?php

declare(strict_types=1);

final class AiParseLogListService
{
    public const PAGE_SIZE = 20;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{source?: string, status?: string, type?: string, date_from?: string, date_to?: string} $filters
     * @return array{rows: list<array<string, mixed>>, next_before_id: int|null}
     */
    public function latest(array $filters, ?int $beforeId = null): array
    {
        $where = [];
        $params = [];

        foreach ([
            'source' => 'source',
            'status' => 'parse_status',
            'type' => 'parsed_type',
        ] as $filterKey => $column) {
            $value = trim((string) ($filters[$filterKey] ?? ''));
            if ($value === '') {
                continue;
            }

            $where[] = $column . ' = :' . $filterKey;
            $params[$filterKey] = $value;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'created_at >= :date_from';
            $params['date_from'] = $this->taipeiDateBoundaryToUtc($dateFrom);
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'created_at < :date_to';
            $params['date_to'] = (new DateTimeImmutable($dateTo, new DateTimeZone('Asia/Taipei')))
                ->modify('+1 day')
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }

        if ($beforeId !== null && $beforeId > 0) {
            $where[] = 'id < :before_id';
            $params['before_id'] = $beforeId;
        }

        $sql = 'SELECT id,
                       SUBSTR(raw_input, 1, 240) AS raw_input_preview,
                       CASE WHEN LENGTH(raw_input) > LENGTH(SUBSTR(raw_input, 1, 240)) THEN 1 ELSE 0 END AS raw_input_is_truncated,
                       SUBSTR(parsed_json, 1, 4096) AS parsed_json_preview,
                       parse_status, error_code,
                       SUBSTR(error_message, 1, 400) AS error_message_preview,
                       CASE WHEN LENGTH(error_message) > LENGTH(SUBSTR(error_message, 1, 400)) THEN 1 ELSE 0 END AS error_message_is_truncated,
                       parsed_type, duration_ms, source, created_at
                FROM ai_parse_logs';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . (self::PAGE_SIZE + 1);

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();
        $hasMore = count($rows) > self::PAGE_SIZE;
        if ($hasMore) {
            array_pop($rows);
        }

        $nextBeforeId = null;
        if ($hasMore && $rows !== []) {
            $nextBeforeId = (int) $rows[array_key_last($rows)]['id'];
        }

        return [
            'rows' => $rows,
            'next_before_id' => $nextBeforeId,
        ];
    }

    private function taipeiDateBoundaryToUtc(string $date): string
    {
        return (new DateTimeImmutable($date, new DateTimeZone('Asia/Taipei')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
