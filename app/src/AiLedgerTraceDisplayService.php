<?php

declare(strict_types=1);

final class AiLedgerTraceDisplayService
{
    private const LEDGER_TABLE_LABELS = [
        'expenses' => '支出',
        'incomes' => '收入',
        'overtime_logs' => '加班',
        'leave_logs' => '請假',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function latestLinksByParseLogIds(array $parseLogIds): array
    {
        $ids = $this->normalizeIds($parseLogIds);
        if ($ids === []) {
            return [];
        }

        $placeholders = $this->placeholders($ids);
        $statement = $this->pdo->prepare(
            "SELECT id, ai_parse_log_id, ledger_table, ledger_id, action, source,
                    raw_input_snapshot, parsed_type_snapshot, parsed_json_snapshot, user_name, created_at
             FROM ai_ledger_links
             WHERE ai_parse_log_id IN ({$placeholders})
             ORDER BY ai_parse_log_id ASC, id DESC"
        );
        $statement->execute($ids);

        $links = [];
        foreach ($statement->fetchAll() as $row) {
            $row = $this->withDisplayTimestamps($row);
            $key = (int) $row['ai_parse_log_id'];
            if (!isset($links[$key])) {
                $links[$key] = $row;
                $links[$key]['link_count'] = 0;
            }
            $links[$key]['link_count']++;
        }

        return $links;
    }

    /** @return array<int, array<string, mixed>> */
    public function latestLinksByLedgerRows(string $ledgerTable, array $ledgerIds): array
    {
        if (!array_key_exists($ledgerTable, self::LEDGER_TABLE_LABELS)) {
            throw new InvalidArgumentException('Unsupported ledger table for AI trace display.');
        }

        $ids = $this->normalizeIds($ledgerIds);
        if ($ids === []) {
            return [];
        }

        $placeholders = $this->placeholders($ids);
        $statement = $this->pdo->prepare(
            "SELECT id, ai_parse_log_id, ledger_table, ledger_id, action, source,
                    raw_input_snapshot, parsed_type_snapshot, parsed_json_snapshot, user_name, created_at
             FROM ai_ledger_links
             WHERE ledger_table = ? AND ledger_id IN ({$placeholders})
             ORDER BY ledger_id ASC, id DESC"
        );
        $statement->execute(array_merge([$ledgerTable], $ids));

        $links = [];
        foreach ($statement->fetchAll() as $row) {
            $row = $this->withDisplayTimestamps($row);
            $key = (int) $row['ledger_id'];
            if (!isset($links[$key])) {
                $links[$key] = $row;
                $links[$key]['link_count'] = 0;
            }
            $links[$key]['link_count']++;
        }

        return $links;
    }

    /** @return array<string, mixed>|null */
    public function parseLogById(int $parseLogId): ?array
    {
        if ($parseLogId < 1) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, raw_input, ai_response, provider, model_name, parsed_type, parsed_json,
                    parse_status, error_code, error_message, duration_ms, source, user_name, created_at
             FROM ai_parse_logs
             WHERE id = ?
             LIMIT 1'
        );
        $statement->execute([$parseLogId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->withDisplayTimestamps($row) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function linksByParseLogId(int $parseLogId): array
    {
        if ($parseLogId < 1) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT id, ai_parse_log_id, ledger_table, ledger_id, action, source,
                    raw_input_snapshot, parsed_type_snapshot, parsed_json_snapshot, user_name, created_at
             FROM ai_ledger_links
             WHERE ai_parse_log_id = ?
             ORDER BY id DESC'
        );
        $statement->execute([$parseLogId]);

        return array_map(fn (array $row): array => $this->withDisplayTimestamps($row), $statement->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function linksByLedgerRow(string $ledgerTable, int $ledgerId): array
    {
        if (!array_key_exists($ledgerTable, self::LEDGER_TABLE_LABELS)) {
            throw new InvalidArgumentException('Unsupported ledger table for AI trace display.');
        }
        if ($ledgerId < 1) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT l.id, l.ai_parse_log_id, l.ledger_table, l.ledger_id, l.action, l.source,
                    l.raw_input_snapshot, l.parsed_type_snapshot, l.parsed_json_snapshot, l.user_name, l.created_at,
                    a.raw_input AS log_raw_input, a.provider, a.model_name, a.parsed_type, a.parsed_json,
                    a.parse_status, a.error_code, a.error_message, a.duration_ms, a.source AS log_source,
                    a.user_name AS log_user_name, a.created_at AS log_created_at
             FROM ai_ledger_links l
             LEFT JOIN ai_parse_logs a ON a.id = l.ai_parse_log_id
             WHERE l.ledger_table = ? AND l.ledger_id = ?
             ORDER BY l.id DESC'
        );
        $statement->execute([$ledgerTable, $ledgerId]);

        return array_map(fn (array $row): array => $this->withDisplayTimestamps($row), $statement->fetchAll());
    }

    public static function ledgerLabel(?string $ledgerTable): string
    {
        $ledgerTable = trim((string) $ledgerTable);
        return self::LEDGER_TABLE_LABELS[$ledgerTable] ?? $ledgerTable;
    }

    public static function actionLabel(?string $action): string
    {
        return match (trim((string) $action)) {
            'created' => '新增',
            'updated' => '更新',
            default => trim((string) $action),
        };
    }

    public static function sourceLabel(?string $source): string
    {
        return match (trim((string) $source)) {
            '' => '-',
            'quick_pwa' => 'Quick Entry / PWA',
            'ios_shortcut' => 'iOS Shortcut',
            'shortcut_api' => 'Shortcut API',
            'admin_ai_input' => '後台 AI 快速輸入',
            'web' => 'AI 快速輸入',
            'quick_entry_check' => 'Quick Entry 驗收腳本',
            'stage2_check' => 'AI 驗收腳本',
            default => trim((string) $source),
        };
    }

    public static function textOrDash(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : '-';
    }

    public static function dateTimeLabel(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return '-';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            return $value;
        }

        return $date->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function withDisplayTimestamps(array $row): array
    {
        foreach (['created_at', 'log_created_at'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = self::dateTimeLabel($row[$key]);
            }
        }

        return $row;
    }

    /** @return array<int, int> */
    private function normalizeIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }

        return array_values($normalized);
    }

    /** @param array<int, int> $ids */
    private function placeholders(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }
}
