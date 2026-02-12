<?php
/**
 * Receive temperature data from ESP8266
 * Handles simple {"temperature": 20.5} payload
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$dbPath = __DIR__ . '/../data/temperature.db';

if (!file_exists($dbPath)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database not initialized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if ($data === null) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
        exit();
    }
    
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        
        $db->beginTransaction();
        
        // Handle simple temperature payload
        if (isset($data['temperature'])) {
            // Simple format: {"temperature": 20.5}
            $stmt = $db->prepare('
                INSERT INTO temperature_data (received_at, device_timestamp)
                VALUES (:received_at, :device_timestamp)
            ');
            
            $stmt->execute([
                ':received_at' => time(),
                ':device_timestamp' => time()
            ]);
            
            $dataId = $db->lastInsertId();
            
            $stmt = $db->prepare('
                INSERT INTO temperature_samples (data_id, temperature, offset)
                VALUES (:data_id, :temperature, :offset)
            ');
            
            $stmt->execute([
                ':data_id' => $dataId,
                ':temperature' => floatval($data['temperature']),
                ':offset' => 0
            ]);
            
            $db->commit();
            
            // Cleanup old data
            $cutoff = time() - (30 * 24 * 60 * 60);
            $db->exec("DELETE FROM temperature_data WHERE received_at < $cutoff");
            
            $total = $db->query('SELECT COUNT(*) FROM temperature_data')->fetchColumn();
            
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'message' => 'Temperature stored',
                'temperature' => floatval($data['temperature']),
                'total_records' => $total
            ]);
            
        } 
        // Handle batch format with samples
        elseif (isset($data['timestamp']) && isset($data['samples'])) {
            $stmt = $db->prepare('
                INSERT INTO temperature_data (received_at, device_timestamp)
                VALUES (:received_at, :device_timestamp)
            ');
            
            $stmt->execute([
                ':received_at' => time(),
                ':device_timestamp' => $data['timestamp']
            ]);
            
            $dataId = $db->lastInsertId();
            
            $stmt = $db->prepare('
                INSERT INTO temperature_samples (data_id, temperature, offset)
                VALUES (:data_id, :temperature, :offset)
            ');
            
            $sampleCount = 0;
            foreach ($data['samples'] as $sample) {
                $stmt->execute([
                    ':data_id' => $dataId,
                    ':temperature' => floatval($sample['temp']),
                    ':offset' => intval($sample['offset'])
                ]);
                $sampleCount++;
            }
            
            $db->commit();
            
            $cutoff = time() - (30 * 24 * 60 * 60);
            $db->exec("DELETE FROM temperature_data WHERE received_at < $cutoff");
            
            $total = $db->query('SELECT COUNT(*) FROM temperature_data')->fetchColumn();
            
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'message' => 'Batch data stored',
                'samples_received' => $sampleCount,
                'total_records' => $total
            ]);
        }
        else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
        }
        
    } catch (PDOException $e) {
        if (isset($db)) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
}
?>
