<?php
/**
 * Receive temperature data from ESP8266
 *
 * Firmware payload (every ~10 min):
 *   {"temperature":21.70,"rssi":-62,"boot_count":12,"fails":0}
 *
 * Required response (HTTP 200):
 *   {"status":"success","update_mode":false,...}
 */

require_once __DIR__ . '/lib/SinricClient.php';
require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/esp_receive.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$dbPath = databasePath();

if (!file_exists($dbPath)) {
    http_response_code(500);
    echo json_encode([
        'status'      => 'error',
        'message'     => 'Database not initialized. Run api/init_database.php',
        'update_mode' => true,
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON', 'update_mode' => true]);
    exit();
}

try {
    $db = openDatabaseReadWrite();
    ensureTemperatureSchema($db);
    $telemetry = parseEspTelemetry($data);

    if (isset($data['temperature'])) {
        $db->beginTransaction();

        $temperature = floatval($data['temperature']);
        $deviceTs    = isset($data['timestamp']) ? intval($data['timestamp']) : time();

        insertEspReading($db, $temperature, $deviceTs, $telemetry);
        $db->commit();

        pruneOldTemperatureData($db);

        $total    = (int) $db->query('SELECT COUNT(*) FROM temperature_data')->fetchColumn();
        $humidity = isset($data['humidity']) ? floatval($data['humidity']) : null;

        http_response_code(200);
        echo json_encode(buildEspSuccessResponse($temperature, $total, 'Temperature stored', [
            '_humidity' => $humidity,
            'device'    => array_filter($telemetry, static fn($v) => $v !== null),
        ]));

    } elseif (isset($data['timestamp']) && isset($data['samples']) && is_array($data['samples'])) {
        $db->beginTransaction();

        $stmt = $db->prepare('
            INSERT INTO temperature_data (received_at, device_timestamp)
            VALUES (:received_at, :device_timestamp)
        ');
        $stmt->execute([
            ':received_at'      => time(),
            ':device_timestamp' => intval($data['timestamp']),
        ]);

        $dataId = (int) $db->lastInsertId();

        $stmt = $db->prepare('
            INSERT INTO temperature_samples (data_id, temperature, offset)
            VALUES (:data_id, :temperature, :offset)
        ');

        $sampleCount = 0;
        foreach ($data['samples'] as $sample) {
            $stmt->execute([
                ':data_id'     => $dataId,
                ':temperature' => floatval($sample['temp']),
                ':offset'      => intval($sample['offset'] ?? 0),
            ]);
            $sampleCount++;
        }

        $db->commit();
        pruneOldTemperatureData($db);

        $total       = (int) $db->query('SELECT COUNT(*) FROM temperature_data')->fetchColumn();
        $lastSample  = end($data['samples']);
        $temperature = floatval($lastSample['temp']);
        $humidity    = isset($data['humidity']) ? floatval($data['humidity']) : null;

        http_response_code(200);
        echo json_encode(buildEspSuccessResponse($temperature, $total, 'Batch data stored', [
            '_humidity'        => $humidity,
            'samples_received' => $sampleCount,
        ]));

    } else {
        http_response_code(400);
        echo json_encode([
            'status'      => 'error',
            'message'     => 'Missing temperature or samples',
            'update_mode' => true,
        ]);
    }

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code(500);
    $msg = $e->getMessage();
    if ($e instanceof PDOException && stripos($msg, 'readonly') !== false) {
        $msg = 'Database not writable. Run: sudo bash scripts/fix-permissions.sh';
    }

    echo json_encode([
        'status'      => 'error',
        'message'     => $msg,
        'update_mode' => true,
    ]);
}
