<?php

declare(strict_types=1);

interface AiParserInterface
{
    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function parse(string $inputText, string $requestedType, array $settings): array;
}
