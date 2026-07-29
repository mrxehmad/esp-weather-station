<?php
/**
 * Database configuration for MySQL/MariaDB
 * Edit these values to match your server environment
 */

// Database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'temperature_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Optional: Shared secret for device authentication (empty = disabled)
define('DEVICE_SECRET_KEY', '');

// Data retention in days
define('RETENTION_DAYS', 90);

// PDO options for secure connections
function getDbOptions(): array
{
    return [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
}
