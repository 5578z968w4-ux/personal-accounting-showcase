<?php

declare(strict_types=1);

require_once __DIR__ . '/AiClientInterface.php';
require_once __DIR__ . '/AiParseException.php';

final class GeminiAiClient implements AiClientInterface
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(private readonly string $apiKey)
    {
    }

    public function generate(string $prompt, array $settings, array $responseSchema): array
    {
        if ($this->apiKey === '') {
            throw new AiParseException('Gemini API Key 尚未設定。', 'config_error', 'missing_api_key');
        }

        $model = trim((string) ($settings['model_name'] ?? ''));
        if ($model === '' || preg_match('/^[A-Za-z0-9._-]{1,120}$/', $model) !== 1) {
            throw new AiParseException('AI 模型名稱未設定或格式不正確。', 'config_error', 'invalid_model');
        }

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature' => (float) ($settings['temperature'] ?? 0.10),
                'maxOutputTokens' => (int) ($settings['max_tokens'] ?? 1000),
                'responseMimeType' => 'application/json',
                'responseSchema' => $responseSchema,
            ],
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $url = self::API_BASE . rawurlencode($model) . ':generateContent';
        $curl = curl_init($url);
        if ($curl === false) {
            throw new AiParseException('無法初始化 Gemini 連線。', 'provider_error', 'client_init_failed');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $jsonPayload,
        ]);

        $startedAt = microtime(true);
        $rawResponse = curl_exec($curl);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $curlError = curl_errno($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($rawResponse === false || $curlError !== 0) {
            $isTimeout = $curlError === CURLE_OPERATION_TIMEDOUT;
            throw new AiParseException(
                $isTimeout ? 'Gemini 連線逾時，請稍後再試。' : '無法連線 Gemini，請稍後再試。',
                $isTimeout ? 'timeout' : 'provider_error',
                $isTimeout ? 'provider_timeout' : 'provider_connection_failed',
                null,
                $durationMs
            );
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            [$message, $errorCode] = match ($httpStatus) {
                401, 403 => ['Gemini 驗證失敗，請檢查 API Key。', 'provider_auth_failed'],
                429 => ['Gemini 使用額度或請求頻率已達限制。', 'provider_rate_limited'],
                default => ['Gemini 服務回傳錯誤，請稍後再試。', 'provider_http_error'],
            };
            throw new AiParseException($message, 'provider_error', $errorCode, $rawResponse, $durationMs);
        }

        try {
            $response = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiParseException(
                'Gemini 回應格式不正確。',
                'provider_error',
                'provider_invalid_response',
                $rawResponse,
                $durationMs
            );
        }

        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new AiParseException(
                'Gemini 沒有回傳可解析內容。',
                'provider_error',
                'provider_empty_response',
                $rawResponse,
                $durationMs
            );
        }

        return [
            'text' => $text,
            'raw_response' => $rawResponse,
            'duration_ms' => $durationMs,
        ];
    }
}
