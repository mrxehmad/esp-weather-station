<?php

require_once __DIR__ . '/includes/functions.php';

$pdo = db();

$devices = $pdo->query('
    SELECT *
    FROM devices
    ORDER BY last_seen DESC
')->fetchAll();

$pageTitle = 'Devices';

include __DIR__ . '/includes/header.php';

?>

<?php if (empty($devices)): ?>

    <div class="notice">
        No devices found yet.
    </div>

<?php else: ?>

    <div class="table-card">
        <table class="list">
            <thead>
                <tr>
                    <th>Device</th>
                    <th>FW</th>
                    <th>Temp</th>
                    <th>RSSI</th>
                    <th>Heap min</th>
                    <th>WiFi reconnects</th>
                    <th>Boots</th>
                    <th>Uploads OK / Total</th>
                    <th>Last seen</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($devices as $d): ?>
                    <?php
                    $status = device_status($d);
                    $temp = $d['last_temp'] !== null
                        ? number_format((float)$d['last_temp'], 1) . ' °C'
                        : '—';
                    ?>
                    <tr>
                        <td>
                            <a href="device.php?chip=<?php echo e(urlencode($d['chip_id'])); ?>">
                                <?php echo e(display_device_name($d)); ?>
                            </a>
                            <div class="muted">
                                <?php echo e($d['chip_id']); ?>
                            </div>
                        </td>

                        <td><?php echo e($d['fw'] ?: '—'); ?></td>
                        <td><?php echo e($temp); ?></td>
                        <td><?php echo $d['last_rssi'] !== null ? e((int)$d['last_rssi'] . ' dBm') : '—'; ?></td>
                        <td><?php echo e(format_bytes($d['heap_min'])); ?></td>
                        <td><?php echo e((string)(int)($d['wifi_reconnects'] ?? 0)); ?></td>
                        <td><?php echo e((string)(int)($d['boot_count'] ?? 0)); ?></td>

                        <td>
                            <?php
                            $ok = (int)($d['ok_uploads'] ?? 0);
                            $total = (int)($d['total_uploads'] ?? 0);
                            echo e($total > 0 ? $ok . ' / ' . $total : '—');
                            ?>
                        </td>

                        <td><?php echo e(ago($d['last_seen'])); ?></td>

                        <td>
                            <span class="pill <?php echo e($status['class']); ?>">
                                <?php echo e($status['label']); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
