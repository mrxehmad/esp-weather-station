<?php

/*
 * ESP8266 ingest endpoint.
 *
 * Firmware expects:
 * - HTTP 200 and body containing "ok" on success
 * - body containing "error" on failure
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function str_clean(array $d, string $key, int $max = 64, string $default = ''): string
{
    $v = array_key_exists($key, $d) && is_scalar($d[$key])
        ? (string)$d[$key]
        : $default;

    $v = trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $v));

    if (strlen($v) > $max) {
        $v = substr($v, 0, $max);
    }

    return $v;
}

function int_clean(array $d, string $key, ?int $min = null, ?int $max = null, $default = null)
{
    if (!isset($d[$key]) || !is_numeric($d[$key])) {
        return $default;
    }

    $v = (int)$d[$key];

    if ($min !== null && $v < $min) {
        $v = $min;
    }

    if ($max !== null && $v > $max) {
        $v = $max;
    }

    return $v;
}

function float_clean(array $d, string $key, ?float $min = null, ?float $max = null, $default = null)
{
    if (!isset($d[$key]) || !is_numeric($d[$key])) {
        return $default;
    }

    $v = (float)$d[$key];

    if ($min !== null && $v < $min) {
        $v = $min;
    }

    if ($max !== null && $v > $max) {
        $v = $max;
    }

    return $v;
}

try {
    $raw = file_get_contents('php://input');

    if ($raw === false || strlen($raw) > 20000) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'invalid request body',
        ]);
        exit;
    }

    if (DEVICE_KEY !== '') {
        $sentKey = $_SERVER['HTTP_X_DEVICE_KEY'] ?? '';

        if (!hash_equals(DEVICE_KEY, $sentKey)) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'unauthorized',
            ]);
            exit;
        }
    }

    $d = json_decode($raw, true);

    if (!is_array($d)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'invalid json',
        ]);
        exit;
    }

    /*
     * Device identity.
     * New firmware sends chip_id.
     * Legacy firmware does not, so derive a stable fallback ID.
     */
    $chipRaw = trim((string)($d['chip_id'] ?? ''));

    if ($chipRaw === '') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $chipRaw = 'legacy-' . substr(md5($ip), 0, 10);
    }

    $chip = preg_replace('/[^A-Za-z0-9:_-]/', '', $chipRaw);

    if (strlen($chip) > 32) {
        $chip = substr($chip, 0, 32);
    }

    if ($chip === '') {
        $chip = 'unknown';
    }

    /*
     * Temperature:
     * new payload uses temp_c, old payload uses temperature.
     */
    $temp = null;

    if (isset($d['temp_c']) && is_numeric($d['temp_c'])) {
        $temp = (float)$d['temp_c'];
    } elseif (isset($d['temperature']) && is_numeric($d['temperature'])) {
        $temp = (float)$d['temperature'];
    }

    if ($temp !== null) {
        if ($temp < -60) {
            $temp = -60;
        }

        if ($temp > 150) {
            $temp = 150;
        }
    }

    $rssi = int_clean($d, 'rssi', -120, 0, null);

    $mac = strtoupper(str_clean($d, 'mac', 17, ''));
    $deviceName = str_clean($d, 'device', 64, '');
    $fw = str_clean($d, 'fw', 32, '');
    $sdk = str_clean($d, 'sdk', 64, '');
    $ip = str_clean($d, 'ip', 45, $_SERVER['REMOTE_ADDR'] ?? '');

    $channel = int_clean($d, 'channel', 0, 255, null);
    $bssid = strtoupper(str_clean($d, 'bssid', 17, ''));
    $wifiState = str_clean($d, 'wifi_state', 16, '');

    $adcRaw = int_clean($d, 'adc_raw', 0, 1023, null);
    $spikeRejects = int_clean($d, 'spike_rejects', 0, null, null);

    $bootCount = int_clean($d, 'boot_count', 0, null, null);
    $wifiReconnects = int_clean($d, 'wifi_reconnects', 0, null, null);
    $otaUpdates = int_clean($d, 'ota_updates', 0, null, null);

    $totalUploads = int_clean($d, 'total_uploads', 0, null, null);
    $okUploads = int_clean($d, 'ok_uploads', 0, null, null);
    $failUploads = int_clean($d, 'fail_uploads', 0, null, null);

    $consecFails = int_clean($d, 'consec_fails', 0, null, null);

    if ($consecFails === null) {
        // Legacy field
        $consecFails = int_clean($d, 'fails', 0, null, null);
    }

    $heap = int_clean($d, 'heap', 0, null, null);
    $heapMin = int_clean($d, 'heap_min', 0, null, null);

    $uptime = int_clean($d, 'uptime_s', 0, null, null);

    $resetReason = str_clean($d, 'reset_reason', 64, '');
    $lastError = str_clean($d, 'last_error', 160, '');

    $now = gmdate('Y-m-d H:i:s');

    $pdo = db();

    /*
     * Upsert device.
     * SQLite 3.24+ required for ON CONFLICT.
     */
    $deviceSql = '
        INSERT INTO devices (
            chip_id,
            mac,
            device_name,
            fw,
            sdk,
            ip,
            last_seen,
            last_temp,
            last_rssi,
            last_heap,
            heap_min,
            boot_count,
            wifi_reconnects,
            ota_updates,
            total_uploads,
            ok_uploads,
            fail_uploads,
            wifi_state,
            last_consec_fails,
            reset_reason,
            last_error
        ) VALUES (
            :chip_id,
            :mac,
            :device_name,
            :fw,
            :sdk,
            :ip,
            :last_seen,
            :last_temp,
            :last_rssi,
            :last_heap,
            :heap_min,
            :boot_count,
            :wifi_reconnects,
            :ota_updates,
            :total_uploads,
            :ok_uploads,
            :fail_uploads,
            :wifi_state,
            :last_consec_fails,
            :reset_reason,
            :last_error
        )
        ON CONFLICT(chip_id) DO UPDATE SET
            mac = COALESCE(NULLIF(excluded.mac, ""), devices.mac),
            device_name = COALESCE(NULLIF(excluded.device_name, ""), devices.device_name),
            fw = COALESCE(NULLIF(excluded.fw, ""), devices.fw),
            sdk = COALESCE(NULLIF(excluded.sdk, ""), devices.sdk),
            ip = COALESCE(NULLIF(excluded.ip, ""), devices.ip),
            last_seen = excluded.last_seen,
            last_temp = COALESCE(excluded.last_temp, devices.last_temp),
            last_rssi = COALESCE(excluded.last_rssi, devices.last_rssi),
            last_heap = COALESCE(excluded.last_heap, devices.last_heap),
            heap_min = COALESCE(excluded.heap_min, devices.heap_min),
            boot_count = COALESCE(excluded.boot_count, devices.boot_count),
            wifi_reconnects = COALESCE(excluded.wifi_reconnects, devices.wifi_reconnects),
            ota_updates = COALESCE(excluded.ota_updates, devices.ota_updates),
            total_uploads = COALESCE(excluded.total_uploads, devices.total_uploads),
            ok_uploads = COALESCE(excluded.ok_uploads, devices.ok_uploads),
            fail_uploads = COALESCE(excluded.fail_uploads, devices.fail_uploads),
            wifi_state = COALESCE(NULLIF(excluded.wifi_state, ""), devices.wifi_state),
            last_consec_fails = COALESCE(excluded.last_consec_fails, devices.last_consec_fails),
            reset_reason = COALESCE(NULLIF(excluded.reset_reason, ""), devices.reset_reason),
            last_error = excluded.last_error
    ';

    $deviceStmt = $pdo->prepare($deviceSql);

    $deviceStmt->execute([
        ':chip_id' => $chip,
        ':mac' => $mac,
        ':device_name' => $deviceName,
        ':fw' => $fw,
        ':sdk' => $sdk,
        ':ip' => $ip,
        ':last_seen' => $now,
        ':last_temp' => $temp,
        ':last_rssi' => $rssi,
        ':last_heap' => $heap,
        ':heap_min' => $heapMin,
        ':boot_count' => $bootCount,
        ':wifi_reconnects' => $wifiReconnects,
        ':ota_updates' => $otaUpdates,
        ':total_uploads' => $totalUploads,
        ':ok_uploads' => $okUploads,
        ':fail_uploads' => $failUploads,
        ':wifi_state' => $wifiState,
        ':last_consec_fails' => $consecFails,
        ':reset_reason' => $resetReason,
        ':last_error' => $lastError,
    ]);

    /*
     * Insert reading.
     */
    $rawStore = $raw === '' ? null : substr($raw, 0, 8000);

    $readingSql = '
        INSERT INTO readings (
            chip_id,
            ts,
            temp_c,
            rssi,
            channel,
            bssid,
            wifi_state,
            adc_raw,
            spike_rejects,
            consec_fails,
            total_uploads,
            ok_uploads,
            fail_uploads,
            heap,
            heap_min,
            uptime_s,
            reset_reason,
            last_error,
            raw_json
        ) VALUES (
            :chip_id,
            :ts,
            :temp_c,
            :rssi,
            :channel,
            :bssid,
            :wifi_state,
            :adc_raw,
            :spike_rejects,
            :consec_fails,
            :total_uploads,
            :ok_uploads,
            :fail_uploads,
            :heap,
            :heap_min,
            :uptime_s,
            :reset_reason,
            :last_error,
            :raw_json
        )
    ';

    $readingStmt = $pdo->prepare($readingSql);

    $readingStmt->execute([
        ':chip_id' => $chip,
        ':ts' => $now,
        ':temp_c' => $temp,
        ':rssi' => $rssi,
        ':channel' => $channel,
        ':bssid' => $bssid,
        ':wifi_state' => $wifiState,
        ':adc_raw' => $adcRaw,
        ':spike_rejects' => $spikeRejects,
        ':consec_fails' => $consecFails,
        ':total_uploads' => $totalUploads,
        ':ok_uploads' => $okUploads,
        ':fail_uploads' => $failUploads,
        ':heap' => $heap,
        ':heap_min' => $heapMin,
        ':uptime_s' => $uptime,
        ':reset_reason' => $resetReason,
        ':last_error' => $lastError,
        ':raw_json' => $rawStore,
    ]);

    $readingId = $pdo->lastInsertId();

    /*
     * Lightweight automatic cleanup.
     * Runs randomly on about 1% of requests.
     */
    if (mt_rand(1, 100) === 1) {
        $pdo->exec(
            "DELETE FROM readings WHERE ts < datetime('now', '-" . (int)RETENTION_DAYS . " day')"
        );
    }

    echo json_encode([
        'status' => 'ok',
        'reading_id' => (int)$readingId,
    ]);
} catch (Throwable $e) {
    error_log('receive.php error: ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'status' => 'error',
        'message' => 'server error',
    ]);
}
