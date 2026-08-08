<?php

declare(strict_types=1);

interface AiClientInterface
{
    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $responseSchema
     * @return array{text: string, raw_response: string, duration_ms: int}
     */
    public function generate(string $prompt, array $settings, array $responseSchema): array;
}
