<?php

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = dirname(DB_FILE);

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Better concurrency for SQLite
    $pdo->query('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA synchronous=NORMAL;');

    init_schema($pdo);

    return $pdo;
}

function init_schema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chip_id TEXT NOT NULL UNIQUE,
            mac TEXT DEFAULT "",
            device_name TEXT DEFAULT "",
            fw TEXT DEFAULT "",
            sdk TEXT DEFAULT "",
            ip TEXT DEFAULT "",
            last_seen TEXT,
            last_temp REAL,
            last_rssi INTEGER,
            last_heap INTEGER,
            heap_min INTEGER,
            boot_count INTEGER,
            wifi_reconnects INTEGER,
            ota_updates INTEGER,
            total_uploads INTEGER,
            ok_uploads INTEGER,
            fail_uploads INTEGER,
            wifi_state TEXT DEFAULT "",
            last_consec_fails INTEGER,
            reset_reason TEXT DEFAULT "",
            last_error TEXT DEFAULT "",
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS readings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chip_id TEXT NOT NULL,
            ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            temp_c REAL,
            rssi INTEGER,
            channel INTEGER,
            bssid TEXT,
            wifi_state TEXT,
            adc_raw INTEGER,
            spike_rejects INTEGER,
            consec_fails INTEGER,
            total_uploads INTEGER,
            ok_uploads INTEGER,
            fail_uploads INTEGER,
            heap INTEGER,
            heap_min INTEGER,
            uptime_s INTEGER,
            reset_reason TEXT,
            last_error TEXT,
            raw_json TEXT
        );
    ');

    $pdo->exec('
        CREATE INDEX IF NOT EXISTS idx_readings_chip_ts
        ON readings(chip_id, ts);
    ');

    $pdo->exec('
        CREATE INDEX IF NOT EXISTS idx_readings_ts
        ON readings(ts);
    ');
}
