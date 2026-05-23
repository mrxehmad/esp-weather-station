<?php
/**
 * Set / get the update_mode toggle for the ESP8266.
 * GET  → returns current state
 * POST {"update_mode": true|false} → sets state
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$settingsPath = __DIR__ . '/../data/settings.json';

// ── Helper: read settings ────────────────────────────────────────────────
function readSettings(string $path): array {
    if (!file_exists($path)) {
        return ['update_mode' => true]; // safe default: stay awake
    }
    $raw = file_get_contents($path);
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['update_mode' => true];
}

// ── Helper: write settings ───────────────────────────────────────────────
function writeSettings(string $path, array $settings): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT)) !== false;
}

// ── GET: return current mode ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $settings = readSettings($settingsPath);
    http_response_code(200);
    echo json_encode([
        'status'      => 'success',
        'update_mode' => (bool) $settings['update_mode']
    ]);
    exit();
}

// ── POST: set mode ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data  = json_decode($input, true);

    if ($data === null || !isset($data['update_mode'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing update_mode field']);
        exit();
    }

    $settings = readSettings($settingsPath);
    $settings['update_mode'] = (bool) $data['update_mode'];

    if (!writeSettings($settingsPath, $settings)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Could not write settings file']);
        exit();
    }

    http_response_code(200);
    echo json_encode([
        'status'      => 'success',
        'update_mode' => $settings['update_mode'],
        'message'     => $settings['update_mode']
            ? 'Update mode ON — ESP will stay awake for OTA'
            : 'Update mode OFF — ESP will deep sleep after each send'
    ]);
    exit();
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
?>
