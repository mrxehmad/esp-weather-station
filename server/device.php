<?php
/**
 * Device detail page - WordPress 2020 admin style
 */

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/functions.php';

$pageTitle = 'Device Details';
$currentPage = 'devices';

$chipId = $_GET['chip'] ?? '';

if (empty($chipId)) {
    header('Location: devices.php');
    exit;
}

try {
    $db = getDb();
    
    // Get device info
    $stmt = $db->prepare('SELECT * FROM devices WHERE chip_id = :chip_id');
    $stmt->execute([':chip_id' => $chipId]);
    $device = $stmt->fetch();
    
    if (!$device) {
        header('Location: devices.php');
        exit;
    }
    
    // Get latest reading with raw JSON
    $stmt = $db->prepare('SELECT * FROM readings WHERE chip_id = :chip_id ORDER BY ts DESC LIMIT 1');
    $stmt->execute([':chip_id' => $chipId]);
    $latestReading = $stmt->fetch();
    
    // Get 7 days of data for charts
    $stmt = $db->prepare("
        SELECT temp_c, rssi, heap, heap_min, ts 
        FROM readings 
        WHERE chip_id = :chip_id 
        AND ts > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY ts ASC
    ");
    $stmt->execute([':chip_id' => $chipId]);
    $chartData = $stmt->fetchAll();
    
    // Calculate stats
    $totalUploads = $latestReading['total_uploads'] ?? 0;
    $okUploads = $latestReading['ok_uploads'] ?? 0;
    $failUploads = $latestReading['fail_uploads'] ?? 0;
    $uploadRate = $totalUploads > 0 ? round(($okUploads / $totalUploads) * 100, 1) : 0;
    
} catch (Throwable $e) {
    $error = $e->getMessage();
}

include __DIR__ . '/api/includes/header.php';
?>

<div class="wrap">
    <h1>
        <a href="devices.php" style="text-decoration:none;">←</a>
        Device: <?php echo e($device['chip_id']); ?>
    </h1>
    
    <?php if (isset($error)): ?>
        <div class="alert-row alert-danger">
            <div class="alert-message">Database error: <?php echo e($error); ?></div>
        </div>
    <?php else: ?>
    
    <!-- Identity Card -->
    <div class="card">
        <div class="card-title">Device Identity</div>
        <table style="width:100%;border-collapse:collapse;">
            <tr>
                <td style="padding:8px;font-weight:600;width:150px;">Chip ID:</td>
                <td style="padding:8px;"><?php echo e($device['chip_id']); ?></td>
                <td style="padding:8px;font-weight:600;width:150px;">MAC:</td>
                <td style="padding:8px;"><?php echo e($device['mac'] ?? '-'); ?></td>
            </tr>
            <tr>
                <td style="padding:8px;font-weight:600;">IP Address:</td>
                <td style="padding:8px;"><?php echo e($device['ip'] ?? '-'); ?></td>
                <td style="padding:8px;font-weight:600;">Channel:</td>
                <td style="padding:8px;"><?php echo $latestReading['channel'] ?? '-'; ?></td>
            </tr>
            <tr>
                <td style="padding:8px;font-weight:600;">Firmware:</td>
                <td style="padding:8px;"><?php echo e($device['fw'] ?? '-'); ?></td>
                <td style="padding:8px;font-weight:600;">SDK:</td>
                <td style="padding:8px;"><?php echo e($device['sdk'] ?? '-'); ?></td>
            </tr>
            <tr>
                <td style="padding:8px;font-weight:600;">BSSID:</td>
                <td style="padding:8px;"><?php echo e($latestReading['bssid'] ?? '-'); ?></td>
                <td style="padding:8px;font-weight:600;">WiFi State:</td>
                <td style="padding:8px;"><?php echo e($latestReading['wifi_state'] ?? '-'); ?></td>
            </tr>
            <tr>
                <td style="padding:8px;font-weight:600;">Reset Reason:</td>
                <td style="padding:8px;" colspan="3"><?php echo e($device['reset_reason'] ?? '-'); ?></td>
            </tr>
        </table>
    </div>
    
    <!-- Stat Cards -->
    <div class="stat-cards">
        <div class="stat-card">
            <div class="stat-label">Total Uploads</div>
            <div class="stat-value"><?php echo number_format($totalUploads); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Successful</div>
            <div class="stat-value text-success"><?php echo number_format($okUploads); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Failed</div>
            <div class="stat-value text-danger"><?php echo number_format($failUploads); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Success Rate</div>
            <div class="stat-value"><?php echo $uploadRate; ?>%</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">OTA Updates</div>
            <div class="stat-value"><?php echo $device['ota_updates'] ?? '0'; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Spike Rejects</div>
            <div class="stat-value"><?php echo $latestReading['spike_rejects'] ?? '0'; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Uptime</div>
            <div class="stat-value"><?php echo $latestReading['uptime_s'] ? floor($latestReading['uptime_s'] / 3600) . 'h ' . floor(($latestReading['uptime_s'] % 3600) / 60) . 'm' : '-'; ?></div>
        </div>
    </div>
    
    <!-- Temperature Chart -->
    <div class="card">
        <div class="card-title">Temperature - Last 7 Days</div>
        <div class="chart-container">
            <canvas id="tempChart"></canvas>
        </div>
    </div>
    
    <!-- RSSI Chart -->
    <div class="card">
        <div class="card-title">RSSI - Last 7 Days</div>
        <div class="chart-container small">
            <canvas id="rssiChart"></canvas>
        </div>
    </div>
    
    <!-- Heap Chart -->
    <div class="card">
        <div class="card-title">Free Heap Memory - Last 7 Days</div>
        <div class="chart-container small">
            <canvas id="heapChart"></canvas>
        </div>
    </div>
    
    <!-- Raw JSON -->
    <?php if ($latestReading && !empty($latestReading['raw_json'])): ?>
    <div class="card">
        <div class="card-title">Latest Raw JSON Payload</div>
        <pre><code><?php 
            $json = json_decode($latestReading['raw_json'], true);
            echo e(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); 
        ?></code></pre>
    </div>
    <?php endif; ?>
    
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($chartData)): ?>
    const labels = [];
    const tempData = [];
    const rssiData = [];
    const heapData = [];
    
    <?php foreach ($chartData as $row): ?>
    labels.push('<?php echo e(formatChartLabel($row['ts'], true)); ?>');
    tempData.push(<?php echo (float)$row['temp_c']; ?>);
    rssiData.push(<?php echo (int)($row['rssi'] ?? 0); ?>);
    heapData.push(<?php echo (int)($row['heap'] ?? $row['heap_min'] ?? 0); ?>);
    <?php endforeach; ?>
    
    createTempChart('tempChart', labels, tempData);
    createRssiChart('rssiChart', labels, rssiData);
    createHeapChart('heapChart', labels, heapData);
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/api/includes/footer.php'; ?>
