<?php
/**
 * Helper functions for formatting and utilities
 */

/**
 * Format a timestamp as relative time (e.g., "4 min ago")
 * @param string $datetime MySQL datetime string
 * @return string
 */
function formatAgo(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return 'Unknown';
    }
    
    $diff = time() - $ts;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $ts);
    }
}

/**
 * Format bytes to human readable
 * @param int $bytes
 * @return string
 */
function formatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    } elseif ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    } else {
        return round($bytes / 1048576, 1) . ' MB';
    }
}

/**
 * Determine device status based on last_seen and telemetry
 * @param array $device Device row from database
 * @return array ['status' => string, 'class' => string]
 */
function getDeviceStatus(array $device): array
{
    $lastSeen = strtotime($device['last_seen'] ?? '1970-01-01');
    $now = time();
    $offlineThreshold = 30 * 60; // 30 minutes
    
    $consecFails = (int)($device['consec_fails'] ?? 0);
    $heapMin = (int)($device['heap_min'] ?? PHP_INT_MAX);
    
    // Check if offline first
    if (($now - $lastSeen) > $offlineThreshold) {
        return ['status' => 'Offline', 'class' => 'offline'];
    }
    
    // Check for degraded conditions
    if ($consecFails > 0 || $heapMin < 10000) {
        return ['status' => 'Degraded', 'class' => 'degraded'];
    }
    
    return ['status' => 'Online', 'class' => 'online'];
}

/**
 * Sanitize temperature value
 * @param mixed $value
 * @return float|null
 */
function sanitizeTemperature($value): ?float
{
    if (!is_numeric($value)) {
        return null;
    }
    $temp = (float)$value;
    // Clamp to valid range
    return max(-60.0, min(150.0, $temp));
}

/**
 * Sanitize RSSI value
 * @param mixed $value
 * @return int|null
 */
function sanitizeRssi($value): ?int
{
    if (!is_numeric($value)) {
        return null;
    }
    $rssi = (int)$value;
    // Clamp to valid range
    return max(-120, min(0, $rssi));
}

/**
 * Truncate string to max length
 * @param string $str
 * @param int $maxLen
 * @return string
 */
function truncateString(string $str, int $maxLen): string
{
    if (strlen($str) <= $maxLen) {
        return $str;
    }
    return substr($str, 0, $maxLen);
}

/**
 * Cast to integer safely
 * @param mixed $value
 * @param int $default
 * @return int
 */
function safeInt($value, int $default = 0): int
{
    if (is_numeric($value)) {
        return (int)$value;
    }
    return $default;
}

/**
 * Get upload success rate percentage
 * @param int $total
 * @param int $ok
 * @return float
 */
function getUploadSuccessRate(int $total, int $ok): float
{
    if ($total <= 0) {
        return 0.0;
    }
    return round(($ok / $total) * 100, 1);
}

/**
 * Escape output for HTML
 * @param string $str
 * @return string
 */
function e(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
