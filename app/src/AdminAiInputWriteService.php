<?php

declare(strict_types=1);

require_once __DIR__ . '/QuickEntryWriteService.php';

final class AdminAiInputAlreadyWrittenException extends RuntimeException
{
    /** @param array<string, mixed> $link */
    public function __construct(private readonly array $link)
    {
        parent::__construct('這筆 AI 預覽已完成記帳，未重複新增。');
    }

    /** @return array<string, mixed> */
    public function link(): array
    {
        return $this->link;
    }
}

final class AdminAiInputWriteService
{
    public const SOURCE = 'admin_ai_input';

    private QuickEntryWriteService $writer;

    public function __construct(private readonly PDO $pdo, ?QuickEntryWriteService $writer = null)
    {
        $this->writer = $writer ?? new QuickEntryWriteService($pdo);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function confirm(
        int $aiParseLogId,
        string $type,
        array $fields,
        string $rawInput,
        ?string $userName
    ): array {
        if ($aiParseLogId < 1) {
            throw new QuickEntryValidationException(
                '確認記帳前需要先完成 AI 解析預覽，請重新解析後再試。',
                ['ai_parse_log_id' => '缺少 AI 預覽紀錄。'],
                $type,
                $fields
            );
        }

        $existingLink = $this->latestLink($aiParseLogId);
        if ($existingLink !== null) {
            throw new AdminAiInputAlreadyWrittenException($existingLink);
        }

        return $this->writer->save(
            $type,
            $fields,
            $rawInput,
            $userName,
            [
                'ai_parse_log_id' => $aiParseLogId,
                'source' => self::SOURCE,
            ],
            self::SOURCE
        );
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    public function saveParsed(array $parsed, string $rawInput, ?string $userName): array
    {
        return $this->confirm(
            (int) ($parsed['ai_parse_log_id'] ?? 0),
            (string) ($parsed['type'] ?? ''),
            is_array($parsed['fields'] ?? null) ? $parsed['fields'] : [],
            $rawInput,
            $userName
        );
    }

    /** @return array<string, mixed>|null */
    public function latestLink(int $aiParseLogId): ?array
    {
        if ($aiParseLogId < 1) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, ai_parse_log_id, ledger_table, ledger_id, action, source, created_at
             FROM ai_ledger_links
             WHERE ai_parse_log_id = :ai_parse_log_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute(['ai_parse_log_id' => $aiParseLogId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }
}
