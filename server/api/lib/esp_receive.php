<?php
/**
 * Helpers for ESP8266 POST /api/receive.php (matches firmware JSON contract).
 */

function getUpdateMode(): bool
{
    $settingsPath = dirname(__DIR__, 2) . '/data/settings.json';
    if (file_exists($settingsPath)) {
        $s = json_decode(file_get_contents($settingsPath), true);
        if (is_array($s) && array_key_exists('update_mode', $s)) {
            return (bool) $s['update_mode'];
        }
    }
    return false; // match firmware default: modem sleep / power saving
}

function parseEspTelemetry(array $data): array
{
    return [
        'rssi' => isset($data['rssi']) ? intval($data['rssi']) : null,
        'boot_count' => isset($data['boot_count']) ? intval($data['boot_count']) : null,
        'consecutive_fails' => isset($data['fails'])
            ? intval($data['fails'])
            : (isset($data['consecutive_fails']) ? intval($data['consecutive_fails']) : null),
    ];
}

function insertEspReading(PDO $db, float $temperature, int $deviceTimestamp, array $telemetry): int
{
    ensureDeviceTelemetryColumns($db);

    if (hasTelemetryColumns($db)) {
        $stmt = $db->prepare('
            INSERT INTO temperature_data (
                received_at, device_timestamp, rssi, boot_count, consecutive_fails
            ) VALUES (
                :received_at, :device_timestamp, :rssi, :boot_count, :fails
            )
        ');
        $stmt->execute([
            ':received_at'      => time(),
            ':device_timestamp' => $deviceTimestamp,
            ':rssi'             => $telemetry['rssi'],
            ':boot_count'       => $telemetry['boot_count'],
            ':fails'            => $telemetry['consecutive_fails'],
        ]);
    } else {
        $stmt = $db->prepare('
            INSERT INTO temperature_data (received_at, device_timestamp)
            VALUES (:received_at, :device_timestamp)
        ');
        $stmt->execute([
            ':received_at'      => time(),
            ':device_timestamp' => $deviceTimestamp,
        ]);
    }

    $dataId = (int) $db->lastInsertId();

    $stmt = $db->prepare('
        INSERT INTO temperature_samples (data_id, temperature, offset)
        VALUES (:data_id, :temperature, :offset)
    ');
    $stmt->execute([
        ':data_id'     => $dataId,
        ':temperature' => $temperature,
        ':offset'      => 0,
    ]);

    return $dataId;
}

function pruneOldTemperatureData(PDO $db, int $days = 30): void
{
    $cutoff = time() - ($days * 24 * 60 * 60);
    $db->exec("DELETE FROM temperature_data WHERE received_at < {$cutoff}");
}

/**
 * JSON body the ESP8266 expects (always HTTP 200 when storage succeeded).
 */
function buildEspSuccessResponse(
    float $temperature,
    int $totalRecords,
    string $message = 'Temperature stored',
    array $extra = []
): array {
    $payload = array_merge([
        'status'        => 'success',
        'message'       => $message,
        'temperature'   => $temperature,
        'total_records' => $totalRecords,
        'update_mode'   => getUpdateMode(),
        'server_time'   => time(),
    ], $extra);

    // Sinric must never break the ESP response (firmware only checks HTTP 200 + update_mode)
    try {
        $humidity = isset($extra['_humidity']) ? $extra['_humidity'] : null;
        unset($payload['_humidity']);

        $sinric = SinricClient::forwardFromEsp($temperature, $humidity);
        if ($sinric !== null) {
            $payload['sinric'] = $sinric;
        }
    } catch (Throwable $e) {
        $payload['sinric'] = [
            'success' => false,
            'message' => 'Sinric forward failed (data still stored)',
        ];
    }

    return $payload;
}
