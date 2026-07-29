<?php
/**
 * Dashboard - Main overview page
 * WordPress 2020 admin style
 */

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/functions.php';

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

try {
    $db = getDb();
    
    // Get latest reading
    $stmt = $db->query('SELECT * FROM readings ORDER BY ts DESC LIMIT 1');
    $latestReading = $stmt->fetch();
    
    // Get device stats
    $stmt = $db->query('SELECT COUNT(*) as count FROM devices WHERE last_seen > DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
    $onlineCount = $stmt->fetch()['count'];
    
    $stmt = $db->query('SELECT COUNT(*) as count FROM devices');
    $totalDevices = $stmt->fetch()['count'];
    
    // Get 24h temperature data for chart
    $stmt = $db->query("
        SELECT temp_c, rssi, ts 
        FROM readings 
        WHERE ts > DATE_SUB(NOW(), INTERVAL 24 HOUR) 
        ORDER BY ts ASC
    ");
    $tempData24h = $stmt->fetchAll();
    
    // Calculate 24h upload success rate
    $stmt = $db->query("
        SELECT 
            SUM(total_uploads) as total,
            SUM(ok_uploads) as ok
        FROM readings 
        WHERE ts > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        AND total_uploads IS NOT NULL
    ");
    $uploadStats = $stmt->fetch();
    $successRate = $uploadStats['total'] > 0 
        ? round(($uploadStats['ok'] / $uploadStats['total']) * 100, 1) 
        : 0;
    
} catch (Throwable $e) {
    $error = $e->getMessage();
}

include __DIR__ . '/api/includes/header.php';
?>

<div class="wrap">
    <h1>Dashboard</h1>
    
    <?php if (isset($error)): ?>
        <div class="alert-row alert-danger">
            <div class="alert-message">Database error: <?php echo e($error); ?></div>
        </div>
    <?php else: ?>
    
    <!-- Stat Cards -->
    <div class="stat-cards">
        <div class="stat-card">
            <div class="stat-label">Latest Temperature</div>
            <div class="stat-value">
                <?php echo $latestReading ? number_format($latestReading['temp_c'], 1) : '--'; ?>
            </div>
            <div class="stat-unit">°C</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-label">Devices Online</div>
            <div class="stat-value"><?php echo $onlineCount; ?> / <?php echo $totalDevices; ?></div>
            <div class="stat-unit">Last 30 min</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-label">Current RSSI</div>
            <div class="stat-value">
                <?php echo $latestReading && $latestReading['rssi'] ? $latestReading['rssi'] : '--'; ?>
            </div>
            <div class="stat-unit">dBm</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-label">24h Upload Success</div>
            <div class="stat-value"><?php echo number_format($successRate, 1); ?>%</div>
            <div class="stat-unit">Last 24 hours</div>
        </div>
    </div>
    
    <!-- Temperature Chart -->
    <div class="card">
        <div class="card-title">Temperature - Last 24 Hours</div>
        <div class="chart-container">
            <canvas id="tempChart"></canvas>
        </div>
    </div>
    
    <!-- RSSI Chart -->
    <div class="card">
        <div class="card-title">RSSI Signal Strength - Last 24 Hours</div>
        <div class="chart-container small">
            <canvas id="rssiChart"></canvas>
        </div>
    </div>
    
    <?php if ($latestReading && !empty($latestReading['last_error'])): ?>
    <!-- Last Error Alert -->
    <div class="alert-row alert-warning">
        <div class="alert-message">Last Device Error</div>
        <div class="alert-meta"><?php echo e($latestReading['last_error']); ?></div>
    </div>
    <?php endif; ?>
    
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($tempData24h)): ?>
    const tempLabels = [];
    const tempData = [];
    const rssiData = [];
    
    <?php foreach ($tempData24h as $row): ?>
    tempLabels.push('<?php echo e(formatChartLabel($row['ts'])); ?>');
    tempData.push(<?php echo (float)$row['temp_c']; ?>);
    rssiData.push(<?php echo (int)($row['rssi'] ?? 0); ?>);
    <?php endforeach; ?>
    
    createTempChart('tempChart', tempLabels, tempData);
    createRssiChart('rssiChart', tempLabels, rssiData);
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/api/includes/footer.php'; ?>
