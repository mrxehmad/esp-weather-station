<?php
/**
 * Receive user tracking data from ESP8266 captive portal
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
    
    if ($data === null || !isset($data['users'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
        exit();
    }
    
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        
        // Create users table if not exists
        $db->exec('
            CREATE TABLE IF NOT EXISTS connected_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mac_address TEXT NOT NULL,
                device_name TEXT,
                email TEXT,
                phone TEXT,
                connect_time INTEGER NOT NULL,
                duration INTEGER,
                received_at INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
        
        $db->exec('CREATE INDEX IF NOT EXISTS idx_mac_address ON connected_users(mac_address)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_received_at_users ON connected_users(received_at)');
        
        $db->beginTransaction();
        
        $stmt = $db->prepare('
            INSERT INTO connected_users (mac_address, device_name, email, phone, connect_time, duration, received_at)
            VALUES (:mac, :device, :email, :phone, :connect_time, :duration, :received_at)
        ');
        
        $count = 0;
        foreach ($data['users'] as $user) {
            $stmt->execute([
                ':mac' => $user['mac'] ?? '',
                ':device' => $user['device'] ?? 'Unknown',
                ':email' => $user['email'] ?? '',
                ':phone' => $user['phone'] ?? '',
                ':connect_time' => $user['connect_time'] ?? time(),
                ':duration' => $user['duration'] ?? 0,
                ':received_at' => time()
            ]);
            $count++;
        }
        
        $db->commit();
        
        $totalUsers = $db->query('SELECT COUNT(*) FROM connected_users')->fetchColumn();
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'User data stored',
            'users_received' => $count,
            'total_users' => $totalUsers
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
}
?>
