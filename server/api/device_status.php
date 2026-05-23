<?php
/**
 * Last ESP report — debug whether the sensor is reaching the server.
 * GET https://temp.ehmi.se/api/device_status.php
 */

require_once __DIR__ . '/lib/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$dbPath = databasePath();

if (!file_exists($dbPath)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Database not found']);
    exit();
}

try {
    $db = openDatabaseReadOnly();
    ensureTemperatureSchema($db);

    $hasTelemetry = hasTelemetryColumns($db);

    $select = $hasTelemetry
        ? 'd.received_at, d.device_timestamp, d.rssi, d.boot_count, d.consecutive_fails, s.temperature'
        : 'd.received_at, d.device_timestamp, s.temperature';

    $row = $db->query("
        SELECT {$select}
        FROM temperature_data d
        INNER JOIN temperature_samples s ON s.data_id = d.id
        ORDER BY d.received_at DESC, s.id DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'No readings yet']);
        exit();
    }

    $age = time() - (int) $row['received_at'];

    echo json_encode([
        'status'  => 'success',
        'online'  => $age < 900,
        'age_sec' => $age,
        'last_seen' => date('c', (int) $row['received_at']),
        'temperature' => floatval($row['temperature']),
        'update_mode' => (function () {
            $raw = @file_get_contents(dirname(__DIR__) . '/data/settings.json');
            $s   = is_string($raw) ? json_decode($raw, true) : null;
            return is_array($s) && array_key_exists('update_mode', $s)
                ? (bool) $s['update_mode']
                : false;
        })(),
        'device' => $hasTelemetry ? [
            'rssi'              => $row['rssi'] !== null ? (int) $row['rssi'] : null,
            'boot_count'        => $row['boot_count'] !== null ? (int) $row['boot_count'] : null,
            'consecutive_fails' => $row['consecutive_fails'] !== null ? (int) $row['consecutive_fails'] : null,
        ] : null,
        'hint' => $age > 600
            ? 'ESP sends every 10 min in modem sleep. No data in ' . $age . 's — check WiFi or use HTTPS URL in firmware.'
            : 'OK — last report ' . $age . 's ago',
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
