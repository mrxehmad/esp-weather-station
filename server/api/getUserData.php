<?php
/**
 * Get user tracking data and analytics
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$dbPath = __DIR__ . '/../data/temperature.db';

$hours = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 1000;

if ($hours < 1) $hours = 24;
if ($limit < 1) $limit = 1000;

if (!file_exists($dbPath)) {
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database not found',
        'data' => [],
        'stats' => null
    ]);
    exit();
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if table exists
    $tableCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='connected_users'")->fetch();
    
    if (!$tableCheck) {
        http_response_code(404);
        echo json_encode([
            'status' => 'success',
            'message' => 'No user data yet',
            'data' => [],
            'stats' => [
                'total_users' => 0,
                'users_with_email' => 0,
                'users_with_phone' => 0,
                'avg_duration' => 0
            ]
        ]);
        exit();
    }
    
    $cutoffTime = time() - ($hours * 3600);
    
    // Get user data
    $stmt = $db->prepare('
        SELECT 
            mac_address,
            device_name,
            email,
            phone,
            connect_time,
            duration,
            received_at
        FROM connected_users
        WHERE received_at >= :cutoff_time
        ORDER BY received_at DESC
        LIMIT :limit
    ');
    
    $stmt->bindValue(':cutoff_time', $cutoffTime, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate statistics
    $totalUsers = $db->query('SELECT COUNT(*) FROM connected_users')->fetchColumn();
    $usersWithEmail = $db->query("SELECT COUNT(*) FROM connected_users WHERE email != ''")->fetchColumn();
    $usersWithPhone = $db->query("SELECT COUNT(*) FROM connected_users WHERE phone != ''")->fetchColumn();
    $avgDuration = $db->query('SELECT AVG(duration) FROM connected_users WHERE duration > 0')->fetchColumn();
    
    // Recent connections (last 24h)
    $recentCount = $db->query("SELECT COUNT(*) FROM connected_users WHERE received_at >= " . (time() - 86400))->fetchColumn();
    
    // Unique MAC addresses
    $uniqueMACs = $db->query('SELECT COUNT(DISTINCT mac_address) FROM connected_users')->fetchColumn();
    
    $stats = [
        'total_users' => intval($totalUsers),
        'unique_devices' => intval($uniqueMACs),
        'users_with_email' => intval($usersWithEmail),
        'users_with_phone' => intval($usersWithPhone),
        'avg_duration' => round(floatval($avgDuration), 1),
        'connections_24h' => intval($recentCount)
    ];
    
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'data' => $users,
        'stats' => $stats,
        'filter' => [
            'hours' => $hours,
            'from' => date('Y-m-d H:i:s', $cutoffTime),
            'to' => date('Y-m-d H:i:s')
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
