<?php
/**
 * Devices list page - WordPress 2020 admin style
 */

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/functions.php';

$pageTitle = 'Devices';
$currentPage = 'devices';

try {
    $db = getDb();
    
    // Get all devices with their latest status
    $stmt = $db->query('
        SELECT d.*, 
               (SELECT consec_fails FROM readings WHERE chip_id = d.chip_id ORDER BY ts DESC LIMIT 1) as consec_fails
        FROM devices d
        ORDER BY d.last_seen DESC
    ');
    $devices = $stmt->fetchAll();
    
} catch (Throwable $e) {
    $error = $e->getMessage();
}

include __DIR__ . '/api/includes/header.php';
?>

<div class="wrap">
    <h1>Devices</h1>
    
    <?php if (isset($error)): ?>
        <div class="alert-row alert-danger">
            <div class="alert-message">Database error: <?php echo e($error); ?></div>
        </div>
    <?php else: ?>
    
    <table class="wp-list-table">
        <thead>
            <tr>
                <th>Device</th>
                <th>Firmware</th>
                <th>Temp</th>
                <th>RSSI</th>
                <th>Heap (min)</th>
                <th>WiFi Reconnects</th>
                <th>Boot Count</th>
                <th>Last Seen</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($devices)): ?>
            <tr>
                <td colspan="10" style="text-align:center;padding:40px;">No devices registered yet</td>
            </tr>
            <?php else: ?>
                <?php foreach ($devices as $device): 
                    $status = getDeviceStatus($device);
                ?>
                <tr>
                    <td>
                        <strong><?php echo e($device['chip_id']); ?></strong><br>
                        <small class="text-muted"><?php echo e($device['device_name'] ?? ''); ?></small>
                    </td>
                    <td><?php echo e($device['fw'] ?? '-'); ?></td>
                    <td><?php echo $device['last_temp'] ? number_format($device['last_temp'], 1) . ' °C' : '-'; ?></td>
                    <td><?php echo $device['last_rssi'] ? $device['last_rssi'] . ' dBm' : '-'; ?></td>
                    <td><?php echo $device['heap_min'] ? formatBytes($device['heap_min']) : '-'; ?></td>
                    <td><?php echo $device['wifi_reconnects'] ?? '0'; ?></td>
                    <td><?php echo $device['boot_count'] ?? '0'; ?></td>
                    <td><?php echo formatAgo($device['last_seen']); ?></td>
                    <td>
                        <span class="status-pill status-<?php echo e($status['class']); ?>">
                            <?php echo e($status['status']); ?>
                        </span>
                    </td>
                    <td>
                        <a href="device.php?chip=<?php echo urlencode($device['chip_id']); ?>" class="button">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <?php endif; ?>
</div>

<?php include __DIR__ . '/api/includes/footer.php'; ?>
