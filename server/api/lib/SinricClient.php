<?php
/**
 * Sinric Pro REST API client — forwards temperature readings to sinric.pro
 * @see https://help.sinric.pro/pages/api-guide.html
 */

class SinricClient
{
    private const API_BASE = 'https://api.sinric.pro/api/v1';

    private string $apiKey;
    private string $deviceId;
    private int $minIntervalSeconds;
    private string $statePath;

    public function __construct(
        string $apiKey,
        string $deviceId,
        int $minIntervalSeconds = 60,
        ?string $statePath = null
    ) {
        $this->apiKey = $apiKey;
        $this->deviceId = $deviceId;
        $this->minIntervalSeconds = max(1, $minIntervalSeconds);
        $this->statePath = $statePath ?? dirname(__DIR__, 2) . '/data/sinric_state.json';
    }

    /**
     * Load client from data/sinric_config.json. Returns null when disabled or misconfigured.
     */
    public static function fromConfig(?string $configPath = null): ?self
    {
        $configPath = $configPath ?? dirname(__DIR__, 2) . '/data/sinric_config.json';

        if (!file_exists($configPath)) {
            return null;
        }

        $config = json_decode(file_get_contents($configPath), true);
        if (!is_array($config) || empty($config['enabled'])) {
            return null;
        }

        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $deviceId = trim((string) ($config['device_id'] ?? ''));

        if ($apiKey === '' || $deviceId === '' || strpos($apiKey, 'YOUR_') === 0) {
            return null;
        }

        $interval = (int) ($config['min_interval_seconds'] ?? 60);
        $statePath = $config['state_path'] ?? null;

        return new self($apiKey, $deviceId, $interval, $statePath);
    }

    /**
     * Report current temperature to Sinric Pro (Temperature Sensor device).
     *
     * @param float $temperature Celsius
     * @param float $humidity    Use -1 when humidity is not available
     */
    public function sendCurrentTemperature(float $temperature, float $humidity = -1): array
    {
        if ($this->isRateLimited()) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => 'Rate limited — will send on next interval',
            ];
        }

        $valuePayload = json_encode([
            'temperature' => round($temperature, 1),
            'humidity'      => $humidity < 0 ? -1 : round($humidity, 1),
        ]);

        $body = json_encode([
            'type'   => 'event',
            'action' => 'currentTemperature',
            'value'  => $valuePayload,
        ]);

        $url = self::API_BASE . '/devices/' . rawurlencode($this->deviceId) . '/action';
        $result = $this->httpPost($url, $body);

        if (!empty($result['success'])) {
            $this->markSent();
        }

        return $result;
    }

    /**
     * Forward ESP temperature to Sinric when integration is configured.
     */
    public static function forwardFromEsp(float $temperature, ?float $humidity = null): ?array
    {
        $client = self::fromConfig();
        if ($client === null) {
            return null;
        }

        $humidityValue = -1;
        if ($humidity !== null && $humidity >= 0) {
            $humidityValue = $humidity;
        }

        return $client->sendCurrentTemperature($temperature, $humidityValue);
    }

    private function canWriteState(): bool
    {
        $dir = dirname($this->statePath);
        if (!is_dir($dir)) {
            return @mkdir($dir, 0775, true) && is_writable($dir);
        }
        if (!is_writable($dir)) {
            return false;
        }
        if (file_exists($this->statePath) && !is_writable($this->statePath)) {
            return false;
        }
        return true;
    }

    private function isRateLimited(): bool
    {
        if (!file_exists($this->statePath) || !is_readable($this->statePath)) {
            return false;
        }

        $raw = @file_get_contents($this->statePath);
        if ($raw === false) {
            return false;
        }

        $state = json_decode($raw, true);
        if (!is_array($state) || empty($state['last_sent_at'])) {
            return false;
        }

        return (time() - (int) $state['last_sent_at']) < $this->minIntervalSeconds;
    }

    private function markSent(): void
    {
        if (!$this->canWriteState()) {
            return; // Sinric event still sent; rate-limit file is optional
        }

        @file_put_contents($this->statePath, json_encode([
            'last_sent_at' => time(),
        ], JSON_PRETTY_PRINT));
    }

    private function httpPost(string $url, string $body): array
    {
        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'message' => 'PHP cURL extension is required for Sinric integration',
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-SINRIC-API-KEY: ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'message' => 'Sinric request failed: ' . $curlError,
            ];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'http_code' => $httpCode,
                'message' => 'Invalid Sinric API response',
                'raw' => substr((string) $response, 0, 200),
            ];
        }

        $decoded['http_code'] = $httpCode;
        if (!isset($decoded['success'])) {
            $decoded['success'] = $httpCode >= 200 && $httpCode < 300;
        }

        return $decoded;
    }
}
