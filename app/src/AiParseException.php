<?php

declare(strict_types=1);

final class AiParseException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $parseStatus,
        private readonly string $errorCode,
        private readonly ?string $rawResponse = null,
        private readonly int $durationMs = 0
    ) {
        parent::__construct($message);
    }

    public function parseStatus(): string
    {
        return $this->parseStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function rawResponse(): ?string
    {
        return $this->rawResponse;
    }

    public function durationMs(): int
    {
        return $this->durationMs;
    }
}
