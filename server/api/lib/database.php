<?php
/**
 * Shared SQLite helpers — read-only for dashboards, read-write for ESP ingest.
 */

function databasePath(): string
{
    return dirname(__DIR__, 2) . '/data/temperature.db';
}

function dataDirectory(): string
{
    return dirname(__DIR__, 2) . '/data';
}

/**
 * Open DB for SELECT-only endpoints (avoids WAL/journal writes on read).
 */
function openDatabaseReadOnly(): PDO
{
    $dbPath = databasePath();

    if (!file_exists($dbPath)) {
        throw new RuntimeException('Database not found. Run api/init_database.php first.');
    }

    if (!is_readable($dbPath)) {
        throw new RuntimeException('Database file is not readable by the web server.');
    }

    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::SQLITE_OPEN_READONLY => true,
    ]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA query_only = ON');

    return $db;
}

/**
 * Open DB for inserts/updates (receive.php, maintenance).
 */
function openDatabaseReadWrite(): PDO
{
    $dataDir = dataDirectory();
    $dbPath  = databasePath();

    if (!is_dir($dataDir)) {
        if (!@mkdir($dataDir, 0775, true)) {
            throw new RuntimeException('Could not create data/ directory.');
        }
    }

    if (!is_writable($dataDir)) {
        throw new RuntimeException(
            'data/ is not writable by the web server. On the server run: '
            . 'sudo chown -R www-data:www-data data && sudo chmod 775 data'
        );
    }

    if (file_exists($dbPath) && !is_writable($dbPath)) {
        throw new RuntimeException(
            'temperature.db is not writable. On the server run: '
            . 'sudo chown www-data:www-data data/temperature.db && sudo chmod 664 data/temperature.db'
        );
    }

    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');

    return $db;
}

function ensureTemperatureSchema(PDO $db): void
{
    $db->exec('
        CREATE TABLE IF NOT EXISTS temperature_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            received_at INTEGER NOT NULL,
            device_timestamp INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS temperature_samples (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            data_id INTEGER NOT NULL,
            temperature REAL NOT NULL,
            offset INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (data_id) REFERENCES temperature_data(id) ON DELETE CASCADE
        )
    ');

    $db->exec('CREATE INDEX IF NOT EXISTS idx_received_at ON temperature_data(received_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_data_id ON temperature_samples(data_id)');

    ensureDeviceTelemetryColumns($db);
}

/**
 * Optional columns sent by ESP8266 firmware (rssi, boot_count, fails).
 */
function ensureDeviceTelemetryColumns(PDO $db): void
{
    $existing = [];
    foreach ($db->query('PRAGMA table_info(temperature_data)') as $col) {
        $existing[$col['name']] = true;
    }

    $columns = [
        'rssi'              => 'INTEGER',
        'boot_count'        => 'INTEGER',
        'consecutive_fails' => 'INTEGER',
    ];

    foreach ($columns as $name => $sqlType) {
        if (!isset($existing[$name])) {
            $db->exec("ALTER TABLE temperature_data ADD COLUMN {$name} {$sqlType}");
        }
    }
}

function hasTelemetryColumns(PDO $db): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cols = $db->query('PRAGMA table_info(temperature_data)')->fetchAll(PDO::FETCH_COLUMN, 1);
    $cache = in_array('rssi', $cols, true)
        && in_array('boot_count', $cols, true)
        && in_array('consecutive_fails', $cols, true);

    return $cache;
}

function databaseErrorResponse(Throwable $e): array
{
    $message = $e->getMessage();

    if ($e instanceof PDOException && stripos($message, 'readonly') !== false) {
        $message = 'Database is read-only for the web server. '
            . 'Ensure data/ and temperature.db are owned by www-data (or your PHP user) and writable. '
            . 'Details: ' . $e->getMessage();
    }

    return [
        'status'  => 'error',
        'message' => $message,
        'data'    => [],
        'stats'   => null,
    ];
}
