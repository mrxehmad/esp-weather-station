<?php
/**
 * Receive temperature data from ESP8266
 * 
 * Supports both legacy payload (schema v0) and new schema v1 payload.
 * 
 * Legacy payload: {"temperature":21.45,"rssi":-61,"boot_count":12,"fails":0}
 * Schema v1 payload: Full telemetry with device identity, wifi stats, etc.
 * 
 * Response contract (CRITICAL):
 * - HTTP 200 + body with "ok", "success", or {"status":"ok"} → SUCCESS
 * - Body containing "error" → FAILURE (device backs off)
 * - Always respond with JSON, never include "error" on success
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Device-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

// Optional: Check shared secret if configured
if (DEVICE_SECRET_KEY !== '') {
    $providedKey = $_SERVER['HTTP_X_DEVICE_KEY'] ?? '';
    if ($providedKey !== DEVICE_SECRET_KEY) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid device key']);
        exit();
    }
}

// Read and decode JSON body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit();
}

try {
    $db = getDb();
    initSchema(); // Ensure tables exist
    
    // Detect payload version
    $isV1 = isset($data['schema']) && $data['schema'] === 1;
    
    // Determine chip_id (primary device key)
    $chipId = 'LEGACY';
    if ($isV1 && !empty($data['chip_id'])) {
        $chipId = truncateString((string)$data['chip_id'], 16);
    } elseif (!empty($data['mac'])) {
        // Try to derive from MAC for legacy devices
        $chipId = 'MAC_' . strtoupper(str_replace(':', '', (string)$data['mac']));
        $chipId = substr($chipId, 0, 16);
    }
    
    // Sanitize common fields
    $tempC = sanitizeTemperature($data['temperature'] ?? $data['temp_c'] ?? null);
    $rssi = sanitizeRssi($data['rssi'] ?? null);
    
    if ($tempC === null) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing temperature']);
        exit();
    }
    
    $db->beginTransaction();
    
    // Upsert devices table (only for v1 or when we have device info)
    if ($isV1) {
        $stmt = $db->prepare('
            INSERT INTO devices (
                chip_id, mac, device_name, fw, sdk, ip, last_seen, last_temp, last_rssi,
                boot_count, wifi_reconnects, ota_updates, heap_min, reset_reason, last_error
            ) VALUES (
                :chip_id, :mac, :device_name, :fw, :sdk, :ip, :last_seen, :last_temp, :last_rssi,
                :boot_count, :wifi_reconnects, :ota_updates, :heap_min, :reset_reason, :last_error
            )
            ON DUPLICATE KEY UPDATE
                mac = VALUES(mac),
                device_name = VALUES(device_name),
                fw = VALUES(fw),
                sdk = VALUES(sdk),
                ip = VALUES(ip),
                last_seen = VALUES(last_seen),
                last_temp = VALUES(last_temp),
                last_rssi = VALUES(last_rssi),
                boot_count = VALUES(boot_count),
                wifi_reconnects = VALUES(wifi_reconnects),
                ota_updates = VALUES(ota_updates),
                heap_min = VALUES(heap_min),
                reset_reason = VALUES(reset_reason),
                last_error = VALUES(last_error)
        ');
        
        $stmt->execute([
            ':chip_id'         => $chipId,
            ':mac'             => !empty($data['mac']) ? truncateString((string)$data['mac'], 17) : null,
            ':device_name'     => !empty($data['device']) ? truncateString((string)$data['device'], 64) : null,
            ':fw'              => !empty($data['fw']) ? truncateString((string)$data['fw'], 32) : null,
            ':sdk'             => !empty($data['sdk']) ? truncateString((string)$data['sdk'], 64) : null,
            ':ip'              => !empty($data['ip']) ? truncateString((string)$data['ip'], 45) : null,
            ':last_seen'       => date('Y-m-d H:i:s'),
            ':last_temp'       => $tempC,
            ':last_rssi'       => $rssi,
            ':boot_count'      => safeInt($data['boot_count'] ?? 0),
            ':wifi_reconnects' => safeInt($data['wifi_reconnects'] ?? 0),
            ':ota_updates'     => safeInt($data['ota_updates'] ?? 0),
            ':heap_min'        => safeInt($data['heap_min'] ?? null),
            ':reset_reason'    => !empty($data['reset_reason']) ? truncateString((string)$data['reset_reason'], 64) : null,
            ':last_error'      => !empty($data['last_error']) ? truncateString((string)$data['last_error'], 160) : null,
        ]);
    } else {
        // Legacy payload - update minimal device info
        $stmt = $db->prepare('
            INSERT INTO devices (chip_id, last_seen, last_temp, last_rssi, boot_count)
            VALUES (:chip_id, :last_seen, :last_temp, :last_rssi, :boot_count)
            ON DUPLICATE KEY UPDATE
                last_seen = VALUES(last_seen),
                last_temp = VALUES(last_temp),
                last_rssi = VALUES(last_rssi),
                boot_count = VALUES(boot_count)
        ');
        
        $stmt->execute([
            ':chip_id'     => $chipId,
            ':last_seen'   => date('Y-m-d H:i:s'),
            ':last_temp'   => $tempC,
            ':last_rssi'   => $rssi,
            ':boot_count'  => safeInt($data['boot_count'] ?? 0),
        ]);
    }
    
    // Insert reading
    $rawJson = $isV1 ? json_encode($data, JSON_UNESCAPED_SLASHES) : null;
    
    $stmt = $db->prepare('
        INSERT INTO readings (
            chip_id, temp_c, rssi, channel, bssid, wifi_state, adc_raw, spike_rejects,
            consec_fails, total_uploads, ok_uploads, fail_uploads, heap, heap_min,
            uptime_s, reset_reason, last_error, raw_json
        ) VALUES (
            :chip_id, :temp_c, :rssi, :channel, :bssid, :wifi_state, :adc_raw, :spike_rejects,
            :consec_fails, :total_uploads, :ok_uploads, :fail_uploads, :heap, :heap_min,
            :uptime_s, :reset_reason, :last_error, :raw_json
        )
    ');
    
    $stmt->execute([
        ':chip_id'       => $chipId,
        ':temp_c'        => $tempC,
        ':rssi'          => $rssi,
        ':channel'       => isset($data['channel']) ? safeInt($data['channel']) : null,
        ':bssid'         => !empty($data['bssid']) ? truncateString((string)$data['bssid'], 17) : null,
        ':wifi_state'    => !empty($data['wifi_state']) ? truncateString((string)$data['wifi_state'], 16) : null,
        ':adc_raw'       => isset($data['adc_raw']) ? max(0, min(65535, safeInt($data['adc_raw']))) : null,
        ':spike_rejects' => isset($data['spike_rejects']) ? max(0, safeInt($data['spike_rejects'])) : null,
        ':consec_fails'  => safeInt($data['consec_fails'] ?? $data['fails'] ?? 0),
        ':total_uploads' => isset($data['total_uploads']) ? max(0, safeInt($data['total_uploads'])) : null,
        ':ok_uploads'    => isset($data['ok_uploads']) ? max(0, safeInt($data['ok_uploads'])) : null,
        ':fail_uploads'  => isset($data['fail_uploads']) ? max(0, safeInt($data['fail_uploads'])) : null,
        ':heap'          => isset($data['heap']) ? max(0, safeInt($data['heap'])) : null,
        ':heap_min'      => isset($data['heap_min']) ? max(0, safeInt($data['heap_min'])) : null,
        ':uptime_s'      => isset($data['uptime_s']) ? max(0, safeInt($data['uptime_s'])) : null,
        ':reset_reason'  => !empty($data['reset_reason']) ? truncateString((string)$data['reset_reason'], 64) : null,
        ':last_error'    => !empty($data['last_error']) ? truncateString((string)$data['last_error'], 160) : null,
        ':raw_json'      => $rawJson,
    ]);
    
    $readingId = (int)$db->lastInsertId();
    
    // Prune old data (retention policy)
    $db->exec('DELETE FROM readings WHERE ts < NOW() - INTERVAL ' . RETENTION_DAYS . ' DAY');
    
    $db->commit();
    
    // Success response - MUST contain "ok" or "success", NEVER "error"
    echo json_encode(['status' => 'ok', 'reading_id' => $readingId]);
    
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'db failure']);
}
