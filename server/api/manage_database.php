<?php
/**
 * Database Management and Maintenance Script
 * Provides utilities for managing the SQLite database
 */

$dbPath = __DIR__ . '/../data/temperature.db';

// Command line argument handling
$command = isset($argv[1]) ? $argv[1] : 'info';

if (!file_exists($dbPath)) {
    echo "Error: Database not found at $dbPath\n";
    echo "Run init_database.php first to create the database.\n";
    exit(1);
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    switch ($command) {
        case 'info':
            showDatabaseInfo($db, $dbPath);
            break;
            
        case 'stats':
            showStatistics($db);
            break;
            
        case 'cleanup':
            $days = isset($argv[2]) ? intval($argv[2]) : 30;
            cleanupOldData($db, $days);
            break;
            
        case 'vacuum':
            vacuumDatabase($db);
            break;
            
        case 'export':
            $filename = isset($argv[2]) ? $argv[2] : 'export.json';
            exportToJSON($db, $filename);
            break;
            
        case 'import':
            $filename = isset($argv[2]) ? $argv[2] : null;
            if (!$filename) {
                echo "Error: Please provide JSON file path\n";
                exit(1);
            }
            importFromJSON($db, $filename);
            break;
            
        case 'migrate':
            migrateFromJSON($db);
            break;
            
        default:
            showHelp();
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}

function showHelp() {
    echo "Database Management Tool\n";
    echo "Usage: php manage_database.php [command] [options]\n\n";
    echo "Commands:\n";
    echo "  info              Show database information\n";
    echo "  stats             Show temperature statistics\n";
    echo "  cleanup [days]    Remove data older than X days (default: 30)\n";
    echo "  vacuum            Optimize database (reclaim space)\n";
    echo "  export [file]     Export data to JSON file\n";
    echo "  import [file]     Import data from JSON file\n";
    echo "  migrate           Migrate from old JSON file to SQLite\n";
}

function showDatabaseInfo($db, $dbPath) {
    echo "=================================\n";
    echo "Database Information\n";
    echo "=================================\n";
    echo "Location: $dbPath\n";
    echo "Size: " . formatBytes(filesize($dbPath)) . "\n";
    
    // Record counts
    $totalRecords = $db->query('SELECT COUNT(*) FROM temperature_data')->fetchColumn();
    $totalSamples = $db->query('SELECT COUNT(*) FROM temperature_samples')->fetchColumn();
    
    echo "\nRecord Counts:\n";
    echo "  Total Records: $totalRecords\n";
    echo "  Total Samples: $totalSamples\n";
    echo "  Avg Samples/Record: " . ($totalRecords > 0 ? round($totalSamples / $totalRecords, 1) : 0) . "\n";
    
    // Date range
    $oldest = $db->query('SELECT MIN(received_at) FROM temperature_data')->fetchColumn();
    $newest = $db->query('SELECT MAX(received_at) FROM temperature_data')->fetchColumn();
    
    if ($oldest && $newest) {
        echo "\nDate Range:\n";
        echo "  Oldest: " . date('Y-m-d H:i:s', $oldest) . "\n";
        echo "  Newest: " . date('Y-m-d H:i:s', $newest) . "\n";
        echo "  Span: " . round(($newest - $oldest) / 86400, 1) . " days\n";
    }
    
    // WAL mode check
    $journalMode = $db->query('PRAGMA journal_mode')->fetchColumn();
    echo "\nConfiguration:\n";
    echo "  Journal Mode: $journalMode\n";
}

function showStatistics($db) {
    echo "=================================\n";
    echo "Temperature Statistics\n";
    echo "=================================\n";
    
    // Overall stats
    $stmt = $db->query('
        SELECT 
            MIN(temperature) as min_temp,
            MAX(temperature) as max_temp,
            AVG(temperature) as avg_temp,
            COUNT(*) as count
        FROM temperature_samples
    ');
    $overall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "All Time:\n";
    echo "  Minimum: " . round($overall['min_temp'], 2) . "°C\n";
    echo "  Maximum: " . round($overall['max_temp'], 2) . "°C\n";
    echo "  Average: " . round($overall['avg_temp'], 2) . "°C\n";
    echo "  Samples: " . $overall['count'] . "\n";
    
    // Last 24 hours
    $cutoff = time() - 86400;
    $stmt = $db->prepare('
        SELECT 
            MIN(s.temperature) as min_temp,
            MAX(s.temperature) as max_temp,
            AVG(s.temperature) as avg_temp,
            COUNT(*) as count
        FROM temperature_samples s
        INNER JOIN temperature_data d ON s.data_id = d.id
        WHERE d.received_at >= :cutoff
    ');
    $stmt->execute([':cutoff' => $cutoff]);
    $last24h = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($last24h['count'] > 0) {
        echo "\nLast 24 Hours:\n";
        echo "  Minimum: " . round($last24h['min_temp'], 2) . "°C\n";
        echo "  Maximum: " . round($last24h['max_temp'], 2) . "°C\n";
        echo "  Average: " . round($last24h['avg_temp'], 2) . "°C\n";
        echo "  Samples: " . $last24h['count'] . "\n";
    }
}

function cleanupOldData($db, $days) {
    $cutoff = time() - ($days * 86400);
    
    echo "Cleaning up data older than $days days...\n";
    echo "Cutoff date: " . date('Y-m-d H:i:s', $cutoff) . "\n";
    
    // Count records to be deleted
    $stmt = $db->prepare('SELECT COUNT(*) FROM temperature_data WHERE received_at < :cutoff');
    $stmt->execute([':cutoff' => $cutoff]);
    $count = $stmt->fetchColumn();
    
    if ($count == 0) {
        echo "No old records to delete.\n";
        return;
    }
    
    echo "Records to delete: $count\n";
    echo "Proceed? (y/n): ";
    $confirm = trim(fgets(STDIN));
    
    if (strtolower($confirm) !== 'y') {
        echo "Cancelled.\n";
        return;
    }
    
    // Delete old records (cascades to samples)
    $stmt = $db->prepare('DELETE FROM temperature_data WHERE received_at < :cutoff');
    $stmt->execute([':cutoff' => $cutoff]);
    
    echo "✓ Deleted $count records\n";
    echo "Running VACUUM to reclaim space...\n";
    
    $db->exec('VACUUM');
    echo "✓ Database optimized\n";
}

function vacuumDatabase($db) {
    echo "Optimizing database...\n";
    $db->exec('VACUUM');
    echo "✓ Database optimized\n";
}

function exportToJSON($db, $filename) {
    echo "Exporting data to $filename...\n";
    
    // Get all data
    $stmt = $db->query('
        SELECT 
            d.received_at,
            d.device_timestamp,
            GROUP_CONCAT(
                json_object(
                    "temp", s.temperature,
                    "offset", s.offset
                )
            ) as samples
        FROM temperature_data d
        INNER JOIN temperature_samples s ON d.id = s.data_id
        GROUP BY d.id
        ORDER BY d.received_at ASC
    ');
    
    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data[] = [
            'received_at' => intval($row['received_at']),
            'device_timestamp' => intval($row['device_timestamp']),
            'samples' => json_decode('[' . $row['samples'] . ']', true)
        ];
    }
    
    file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));
    echo "✓ Exported " . count($data) . " records to $filename\n";
}

function importFromJSON($db, $filename) {
    if (!file_exists($filename)) {
        echo "Error: File not found: $filename\n";
        exit(1);
    }
    
    echo "Importing from $filename...\n";
    
    $json = file_get_contents($filename);
    $data = json_decode($json, true);
    
    if (!is_array($data)) {
        echo "Error: Invalid JSON format\n";
        exit(1);
    }
    
    echo "Found " . count($data) . " records to import\n";
    echo "Proceed? (y/n): ";
    $confirm = trim(fgets(STDIN));
    
    if (strtolower($confirm) !== 'y') {
        echo "Cancelled.\n";
        return;
    }
    
    $db->beginTransaction();
    
    try {
        $inserted = 0;
        foreach ($data as $record) {
            $stmt = $db->prepare('
                INSERT INTO temperature_data (received_at, device_timestamp)
                VALUES (:received_at, :device_timestamp)
            ');
            $stmt->execute([
                ':received_at' => $record['received_at'],
                ':device_timestamp' => $record['device_timestamp']
            ]);
            
            $dataId = $db->lastInsertId();
            
            foreach ($record['samples'] as $sample) {
                $stmt = $db->prepare('
                    INSERT INTO temperature_samples (data_id, temperature, offset)
                    VALUES (:data_id, :temperature, :offset)
                ');
                $stmt->execute([
                    ':data_id' => $dataId,
                    ':temperature' => $sample['temp'],
                    ':offset' => $sample['offset']
                ]);
            }
            
            $inserted++;
        }
        
        $db->commit();
        echo "✓ Imported $inserted records successfully\n";
        
    } catch (Exception $e) {
        $db->rollBack();
        echo "Error importing data: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function migrateFromJSON($db) {
    $jsonFile = __DIR__ . '/../data/temperature_data.json';
    
    if (!file_exists($jsonFile)) {
        echo "Error: JSON file not found at $jsonFile\n";
        exit(1);
    }
    
    echo "Migrating from JSON to SQLite...\n";
    importFromJSON($db, $jsonFile);
    
    echo "\nCreate backup of JSON file? (y/n): ";
    $confirm = trim(fgets(STDIN));
    
    if (strtolower($confirm) === 'y') {
        $backupFile = $jsonFile . '.backup.' . date('Y-m-d-His');
        rename($jsonFile, $backupFile);
        echo "✓ JSON file backed up to: $backupFile\n";
    }
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}
?>
