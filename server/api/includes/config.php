<?php
/**
 * Database configuration for SQLite
 * Edit these values to match your server environment
 */

// SQLite database file path (relative to this config file)
define('DB_FILE', __DIR__ . '/../../data/temperature.db');

// Optional: Shared secret for device authentication (empty = disabled)
define('DEVICE_SECRET_KEY', '');

// Data retention in days
define('RETENTION_DAYS', 90);
