<?php

require_once __DIR__ . '/db.php';

date_default_timezone_set(TIMEZONE);

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function ago(?string $utc): string
{
    if (!$utc) {
        return 'never';
    }

    $ts = strtotime($utc . ' UTC');

    if (!$ts) {
        return $utc;
    }

    $diff = max(0, time() - $ts);

    if ($diff < 60) {
        return $diff . 's ago';
    }

    if ($diff < 3600) {
        return floor($diff / 60) . 'm ago';
    }

    if ($diff < 86400) {
        return floor($diff / 3600) . 'h ago';
    }

    return floor($diff / 86400) . 'd ago';
}

function format_bytes($bytes): string
{
    if ($bytes === null || $bytes === '') {
        return '—';
    }

    $bytes = (float)$bytes;

    if ($bytes < 1024) {
        return round($bytes) . ' B';
    }

    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return round($bytes / 1048576, 1) . ' MB';
}

function format_uptime($seconds): string
{
    if ($seconds === null || $seconds === '') {
        return '—';
    }

    $s = (int)$seconds;

    if ($s < 0) {
        $s = 0;
    }

    return sprintf(
        '%dd %02dh %02dm %02ds',
        intdiv($s, 86400),
        intdiv($s, 3600) % 24,
        intdiv($s, 60) % 60,
        $s % 60
    );
}

function device_status(?array $device): array
{
    if (!$device || empty($device['last_seen'])) {
        return [
            'class' => 'offline',
            'label' => 'Offline',
        ];
    }

    $last = strtotime($device['last_seen'] . ' UTC');
    $online = $last && (time() - $last) <= 1800; // 30 minutes

    if (!$online) {
        return [
            'class' => 'offline',
            'label' => 'Offline',
        ];
    }

    $fails = (int)($device['last_consec_fails'] ?? 0);
    $heapMin = ($device['heap_min'] !== null && $device['heap_min'] !== '')
        ? (int)$device['heap_min']
        : 999999;
    $err = trim((string)($device['last_error'] ?? ''));

    if ($fails > 0 || $heapMin < 10000 || $err !== '') {
        return [
            'class' => 'degraded',
            'label' => 'Degraded',
        ];
    }

    return [
        'class' => 'online',
        'label' => 'Online',
    ];
}

function upload_rate(?array $device): string
{
    if (!$device) {
        return '—';
    }

    $ok = (int)($device['ok_uploads'] ?? 0);
    $total = (int)($device['total_uploads'] ?? 0);

    if ($total <= 0) {
        return '—';
    }

    return round(($ok / $total) * 100, 1) . '%';
}

function display_device_name(array $device): string
{
    if (!empty($device['device_name'])) {
        return $device['device_name'];
    }

    return $device['chip_id'];
}
