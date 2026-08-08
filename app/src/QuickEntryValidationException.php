<?php

declare(strict_types=1);

final class QuickEntryValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $fieldErrors
     * @param array<string, mixed> $fields
     */
    public function __construct(
        string $message,
        private readonly array $fieldErrors,
        private readonly string $entryType,
        private readonly array $fields
    ) {
        parent::__construct($message);
    }

    /** @return array<string, string> */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }

    public function entryType(): string
    {
        return $this->entryType;
    }

    /** @return array<string, mixed> */
    public function fields(): array
    {
        return $this->fields;
    }
}
