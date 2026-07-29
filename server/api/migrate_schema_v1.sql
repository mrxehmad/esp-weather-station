-- MySQL/MariaDB Schema Migration for ESP8266 Temperature Station (Schema v1)
-- Run this script to create the required tables

-- Usage: mysql -u username -p database_name < migrate_schema_v1.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- Devices table
-- Stores device identity and latest status
-- ============================================
CREATE TABLE IF NOT EXISTS devices (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chip_id         VARCHAR(16) NOT NULL,
    mac             VARCHAR(17),
    device_name     VARCHAR(64),
    fw              VARCHAR(32),
    sdk             VARCHAR(64),
    ip              VARCHAR(45),
    last_seen       DATETIME,
    last_temp       FLOAT,
    last_rssi       SMALLINT,
    boot_count      INT UNSIGNED,
    wifi_reconnects INT UNSIGNED,
    ota_updates     INT UNSIGNED,
    heap_min        INT UNSIGNED,
    reset_reason    VARCHAR(64),
    last_error      VARCHAR(160),
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chip (chip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Readings table
-- Stores individual temperature readings with full telemetry
-- ============================================
CREATE TABLE IF NOT EXISTS readings (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chip_id       VARCHAR(16) NOT NULL,
    ts            DATETIME DEFAULT CURRENT_TIMESTAMP,
    temp_c        FLOAT,
    rssi          SMALLINT,
    channel       TINYINT,
    bssid         VARCHAR(17),
    wifi_state    VARCHAR(16),
    adc_raw       SMALLINT UNSIGNED,
    spike_rejects INT UNSIGNED,
    consec_fails  INT UNSIGNED,
    total_uploads INT UNSIGNED,
    ok_uploads    INT UNSIGNED,
    fail_uploads  INT UNSIGNED,
    heap          INT UNSIGNED,
    heap_min      INT UNSIGNED,
    uptime_s      INT UNSIGNED,
    reset_reason  VARCHAR(64),
    last_error    VARCHAR(160),
    raw_json      TEXT NULL,
    KEY idx_chip_ts (chip_id, ts),
    KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- Data retention cron job
-- Add this to your crontab to automatically prune old data:
-- 
-- 0 2 * * * mysql -u username -p'password' database_name -e "DELETE FROM readings WHERE ts < NOW() - INTERVAL 90 DAY"
--
-- Or run manually:
-- DELETE FROM readings WHERE ts < NOW() - INTERVAL 90 DAY;
-- ============================================

-- ============================================
-- Sample queries for testing
-- ============================================

-- Check table structure:
-- DESCRIBE devices;
-- DESCRIBE readings;

-- Count records:
-- SELECT 'devices' as tbl, COUNT(*) as cnt FROM devices
-- UNION ALL
-- SELECT 'readings' as tbl, COUNT(*) as cnt FROM readings;

-- Latest reading per device:
-- SELECT d.chip_id, d.last_temp, d.last_rssi, d.last_seen
-- FROM devices d
-- ORDER BY d.last_seen DESC;

-- Temperature trend for a specific device (last 24h):
-- SELECT ts, temp_c, rssi FROM readings
-- WHERE chip_id = 'YOUR_CHIP_ID'
-- AND ts > DATE_SUB(NOW(), INTERVAL 24 HOUR)
-- ORDER BY ts ASC;
