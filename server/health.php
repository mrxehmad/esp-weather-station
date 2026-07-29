<?php

require_once __DIR__ . '/includes/functions.php';

$pdo = db();

$devices = $pdo->query('
    SELECT *
    FROM devices
    ORDER BY last_seen DESC
')->fetchAll();

$alerts = [];

foreach ($devices as $d) {
    $status = device_status($d);
    $name = display_device_name($d);
    $chip = $d['chip_id'];

    if ($status['class'] === 'offline') {
        $alerts[] = [
            'class' => 'danger',
            'title' => 'Device offline',
            'device' => $name,
            'chip' => $chip,
            'detail' => 'Last seen ' . ago($d['last_seen']),
        ];
    }

    $fails = (int)($d['last_consec_fails'] ?? 0);

    if ($fails > 0) {
        $alerts[] = [
            'class' => $fails >= 5 ? 'danger' : 'warn',
            'title' => 'Consecutive upload failures',
            'device' => $name,
            'chip' => $chip,
            'detail' => $fails . ' consecutive failures reported by device',
        ];
    }

    if ($d['heap_min'] !== null && $d['heap_min'] !== '' && (int)$d['heap_min'] < 10000) {
        $alerts[] = [
            'class' => 'danger',
            'title' => 'Low heap',
            'device' => $name,
            'chip' => $chip,
            'detail' => 'heap_min = ' . format_bytes($d['heap_min']),
        ];
    }

    if (!empty($d['last_error'])) {
        $alerts[] = [
            'class' => 'warn',
            'title' => 'Device reported an error',
            'device' => $name,
            'chip' => $chip,
            'detail' => $d['last_error'],
        ];
    }

    $resetReason = (string)($d['reset_reason'] ?? '');

    if (preg_match('/exception|watchdog|fatal|panic/i', $resetReason)) {
        $alerts[] = [
            'class' => 'danger',
            'title' => 'Suspicious reset reason',
            'device' => $name,
            'chip' => $chip,
            'detail' => 'reset_reason = ' . $resetReason,
        ];
    }

    $wifiReconnects = (int)($d['wifi_reconnects'] ?? 0);

    if ($wifiReconnects > 20) {
        $alerts[] = [
            'class' => 'warn',
            'title' => 'High WiFi reconnect count',
            'device' => $name,
            'chip' => $chip,
            'detail' => $wifiReconnects . ' reconnects since power-on',
        ];
    }
}

$pageTitle = 'Health';

include __DIR__ . '/includes/header.php';

?>

<?php if (empty($devices)): ?>

    <div class="notice">
        No devices found yet.
    </div>

<?php elseif (empty($alerts)): ?>

    <div class="alert-item ok">
        <strong>All devices look healthy.</strong>
    </div>

<?php else: ?>

    <?php foreach ($alerts as $alert): ?>
        <div class="alert-item <?php echo e($alert['class']); ?>">
            <strong><?php echo e($alert['title']); ?></strong>
            —
            <a href="device.php?chip=<?php echo e(urlencode($alert['chip'])); ?>">
                <?php echo e($alert['device']); ?>
            </a>

            <div class="muted">
                <?php echo e($alert['detail']); ?>
            </div>
        </div>
    <?php endforeach; ?>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
