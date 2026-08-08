<?php

declare(strict_types=1);

final class AiLedgerLinkService
{
    private const LEDGER_TABLE_BY_PARSED_TYPE = [
        'expense' => 'expenses',
        'income' => 'incomes',
        'overtime' => 'overtime_logs',
        'leave' => 'leave_logs',
    ];

    private const ACTIONS = [
        'created',
        'updated',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $aiParseLogId = (int) ($data['ai_parse_log_id'] ?? 0);
        $ledgerTable = trim((string) ($data['ledger_table'] ?? ''));
        $ledgerId = (int) ($data['ledger_id'] ?? 0);
        $action = trim((string) ($data['action'] ?? ''));
        $source = trim((string) ($data['source'] ?? ''));
        $expectedRawInput = (string) ($data['expected_raw_input'] ?? '');
        $expectedParsedType = trim((string) ($data['expected_parsed_type'] ?? ''));

        if ($aiParseLogId < 1) {
            throw new InvalidArgumentException('AI parse log id is required for ledger link.');
        }
        if (!in_array($ledgerTable, self::LEDGER_TABLE_BY_PARSED_TYPE, true)) {
            throw new InvalidArgumentException('Unsupported ledger table for AI ledger link.');
        }
        if ($ledgerId < 1) {
            throw new InvalidArgumentException('Ledger id is required for AI ledger link.');
        }
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Unsupported AI ledger link action.');
        }
        if ($source === '') {
            throw new InvalidArgumentException('AI ledger link source is required.');
        }
        if (!isset(self::LEDGER_TABLE_BY_PARSED_TYPE[$expectedParsedType])) {
            throw new InvalidArgumentException('Expected parsed type is required for AI ledger link.');
        }
        if (self::LEDGER_TABLE_BY_PARSED_TYPE[$expectedParsedType] !== $ledgerTable) {
            throw new InvalidArgumentException('AI ledger link parsed type does not match ledger table.');
        }

        $parseLog = $this->verifiedParseLog($aiParseLogId, $source, $expectedRawInput, $expectedParsedType);

        $statement = $this->pdo->prepare(
            'INSERT INTO ai_ledger_links (
                ai_parse_log_id, ledger_table, ledger_id, action, source,
                raw_input_snapshot, parsed_type_snapshot, parsed_json_snapshot, user_name
             ) VALUES (
                :ai_parse_log_id, :ledger_table, :ledger_id, :action, :source,
                :raw_input_snapshot, :parsed_type_snapshot, :parsed_json_snapshot, :user_name
             )'
        );
        $statement->execute([
            'ai_parse_log_id' => $aiParseLogId,
            'ledger_table' => $ledgerTable,
            'ledger_id' => $ledgerId,
            'action' => $action,
            'source' => $source,
            'raw_input_snapshot' => $parseLog['raw_input'],
            'parsed_type_snapshot' => $parseLog['parsed_type'],
            'parsed_json_snapshot' => $parseLog['parsed_json'],
            'user_name' => $parseLog['user_name'] ?? ($data['user_name'] ?? null),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function verifiedParseLog(
        int $aiParseLogId,
        string $source,
        string $expectedRawInput,
        string $expectedParsedType
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT raw_input, parsed_type, parsed_json, parse_status, source, user_name
             FROM ai_parse_logs
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $aiParseLogId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new InvalidArgumentException('AI parse log not found for ledger link.');
        }
        if ((string) ($row['parse_status'] ?? '') !== 'success') {
            throw new InvalidArgumentException('AI parse log must be successful for ledger link.');
        }
        if ((string) ($row['source'] ?? '') !== $source) {
            throw new InvalidArgumentException('AI parse log source mismatch for ledger link.');
        }
        if ((string) ($row['raw_input'] ?? '') !== $expectedRawInput) {
            throw new InvalidArgumentException('AI parse log raw input mismatch for ledger link.');
        }
        if ((string) ($row['parsed_type'] ?? '') !== $expectedParsedType) {
            throw new InvalidArgumentException('AI parse log parsed type mismatch for ledger link.');
        }

        $parsedJson = trim((string) ($row['parsed_json'] ?? ''));
        if ($parsedJson === '') {
            throw new InvalidArgumentException('AI parse log parsed JSON is required for ledger link.');
        }

        try {
            $parsed = json_decode($parsedJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('AI parse log parsed JSON is invalid for ledger link.');
        }

        if (!is_array($parsed) || (string) ($parsed['type'] ?? '') !== $expectedParsedType) {
            throw new InvalidArgumentException('AI parse log parsed JSON type mismatch for ledger link.');
        }

        return $row;
    }
}
