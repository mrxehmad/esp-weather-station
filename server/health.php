<?php
/**
 * Health/Alerts page - WordPress 2020 admin style
 */

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/functions.php';

$pageTitle = 'Health Status';
$currentPage = 'health';

try {
    $db = getDb();
    
    $alerts = [];
    
    // Alert: Devices offline (no data in last 30 min)
    $stmt = $db->query("
        SELECT chip_id, device_name, last_seen 
        FROM devices 
        WHERE last_seen < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ORDER BY last_seen ASC
    ");
    foreach ($stmt->fetchAll() as $row) {
        $alerts[] = [
            'severity' => 'danger',
            'message' => 'Device offline',
            'device' => $row['chip_id'] . ($row['device_name'] ? ' (' . $row['device_name'] . ')' : ''),
            'since' => formatAgo($row['last_seen'])
        ];
    }
    
    // Alert: Low heap memory (< 10000 bytes)
    $stmt = $db->query("
        SELECT d.chip_id, d.device_name, r.heap_min, r.ts
        FROM readings r
        JOIN devices d ON r.chip_id = d.chip_id
        WHERE r.heap_min < 10000
        AND r.id IN (SELECT MAX(id) FROM readings GROUP BY chip_id)
        ORDER BY r.heap_min ASC
    ");
    foreach ($stmt->fetchAll() as $row) {
        $alerts[] = [
            'severity' => 'warning',
            'message' => sprintf('Low heap memory: %s', formatBytes((int)$row['heap_min'])),
            'device' => $row['chip_id'],
            'since' => formatAgo($row['ts'])
        ];
    }
    
    // Alert: Bad reset reason (Exception, Watchdog, Fatal)
    $stmt = $db->query("
        SELECT chip_id, device_name, reset_reason, last_seen
        FROM devices
        WHERE reset_reason LIKE '%Exception%'
           OR reset_reason LIKE '%Watchdog%'
           OR reset_reason LIKE '%Fatal%'
        ORDER BY last_seen DESC
    ");
    foreach ($stmt->fetchAll() as $row) {
        $alerts[] = [
            'severity' => 'danger',
            'message' => sprintf('Critical reset: %s', e($row['reset_reason'])),
            'device' => $row['chip_id'],
            'since' => formatAgo($row['last_seen'])
        ];
    }
    
    // Alert: Consecutive fails > 0
    $stmt = $db->query("
        SELECT d.chip_id, d.device_name, r.consec_fails, r.ts
        FROM readings r
        JOIN devices d ON r.chip_id = d.chip_id
        WHERE r.consec_fails > 0
        AND r.id IN (SELECT MAX(id) FROM readings GROUP BY chip_id)
        ORDER BY r.consec_fails DESC
    ");
    foreach ($stmt->fetchAll() as $row) {
        $alerts[] = [
            'severity' => 'warning',
            'message' => sprintf('Consecutive upload failures: %d', $row['consec_fails']),
            'device' => $row['chip_id'],
            'since' => formatAgo($row['ts'])
        ];
    }
    
    // Alert: High WiFi reconnects (> 20)
    $stmt = $db->query("
        SELECT chip_id, device_name, wifi_reconnects, last_seen
        FROM devices
        WHERE wifi_reconnects > 20
        ORDER BY wifi_reconnects DESC
    ");
    foreach ($stmt->fetchAll() as $row) {
        $alerts[] = [
            'severity' => 'info',
            'message' => sprintf('High WiFi reconnects: %d', $row['wifi_reconnects']),
            'device' => $row['chip_id'],
            'since' => formatAgo($row['last_seen'])
        ];
    }
    
    // Alert: Last error not empty
    $stmt = $db->query("
        SELECT d.chip_id, d.device_name, r.last_error, r.ts
        FROM readings r
        JOIN devices d ON r.chip_id = d.chip_id
        WHERE r.last_error != '' AND r.last_error IS NOT NULL
        AND r.id IN (SELECT MAX(id) FROM readings GROUP BY chip_id)
        ORDER BY r.ts DESC
    ");
    foreach ($stmt->fetchAll() as $row) {
        $alerts[] = [
            'severity' => 'warning',
            'message' => sprintf('Device error: %s', e($row['last_error'])),
            'device' => $row['chip_id'],
            'since' => formatAgo($row['ts'])
        ];
    }
    
} catch (Throwable $e) {
    $error = $e->getMessage();
}

include __DIR__ . '/api/includes/header.php';
?>

<div class="wrap">
    <h1>Health Status</h1>
    
    <?php if (isset($error)): ?>
        <div class="alert-row alert-danger">
            <div class="alert-message">Database error: <?php echo e($error); ?></div>
        </div>
    <?php else: ?>
    
    <?php if (empty($alerts)): ?>
        <div class="alert-row alert-success">
            <div class="alert-message">✓ All systems healthy! No alerts detected.</div>
        </div>
    <?php else: ?>
        <p class="text-muted mb-2"><?php echo count($alerts); ?> alert(s) found</p>
        
        <?php foreach ($alerts as $alert): ?>
        <div class="alert-row alert-<?php echo e($alert['severity']); ?>">
            <div class="alert-message">
                <strong><?php echo e($alert['message']); ?></strong>
            </div>
            <div class="alert-meta">
                Device: <?php echo e($alert['device']); ?> | Since: <?php echo e($alert['since']); ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <?php endif; ?>
</div>

<?php include __DIR__ . '/api/includes/footer.php'; ?>
