<?php
/**
 * Create SQLite database and tables (run once on server).
 * CLI: php api/init_database.php
 * Web: https://your-host/api/init_database.php (remove or protect after use)
 */

require_once __DIR__ . '/lib/database.php';

$isCli = php_sapi_name() === 'cli';
$dbPath = databasePath();

try {
    $db = openDatabaseReadWrite();
    ensureTemperatureSchema($db);

    $payload = [
        'status'  => 'success',
        'message' => 'Database initialized',
        'path'    => $dbPath,
        'writable' => is_writable($dbPath) && is_writable(dataDirectory()),
    ];

    if ($isCli) {
        echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    $payload = ['status' => 'error', 'message' => $e->getMessage()];

    if ($isCli) {
        fwrite(STDERR, $payload['message'] . PHP_EOL);
        exit(1);
    }

    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode($payload, JSON_PRETTY_PRINT);
}
