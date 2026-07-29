<?php
/**
 * Database connection handler
 * Returns a PDO instance for MySQL/MariaDB
 */

require_once __DIR__ . '/config.php';

/**
 * Get database connection
 * @return PDO
 * @throws PDOException
 */
function getDb(): PDO
{
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_NAME,
            DB_CHARSET
        );
        
        $pdo = new PDO($dsn, DB_USER, DB_PASS, getDbOptions());
    }
    
    return $pdo;
}

/**
 * Initialize database schema (run once)
 * @return void
 */
function initSchema(): void
{
    $db = getDb();
    
    // Create devices table
    $db->exec('
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    
    // Create readings table
    $db->exec('
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}
