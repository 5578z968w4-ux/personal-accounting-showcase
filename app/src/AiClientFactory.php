<?php

declare(strict_types=1);

require_once __DIR__ . '/AiClientInterface.php';
require_once __DIR__ . '/GeminiAiClient.php';
require_once __DIR__ . '/AiParseException.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/DemoMode.php';

final class AiClientFactory
{
    /** @param array<string, AiClientInterface> $overrides */
    public function __construct(private readonly array $overrides = [])
    {
    }

    /** @param array<string, mixed> $settings */
    public function create(array $settings): AiClientInterface
    {
        if (DemoMode::isEnabled()) {
            throw new AiParseException(
                '展示模式不會呼叫外部 AI。',
                'config_error',
                'demo_ai_disabled'
            );
        }

        $provider = strtolower(trim((string) ($settings['provider'] ?? '')));
        if (isset($this->overrides[$provider])) {
            return $this->overrides[$provider];
        }

        $geminiApiKey = trim((string) app_env('GEMINI_API_KEY', ''));
        if (str_starts_with($geminiApiKey, 'change_this_')) {
            $geminiApiKey = '';
        }

        return match ($provider) {
            'gemini' => new GeminiAiClient($geminiApiKey),
            default => throw new AiParseException(
                '目前只支援 Gemini Provider。',
                'config_error',
                'unsupported_provider'
            ),
        };
    }
}
