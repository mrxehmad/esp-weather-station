<?php

require_once __DIR__ . '/includes/functions.php';

$pdo = db();

$chip = $_GET['chip'] ?? '';

if ($chip === '') {
    header('Location: devices.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM devices WHERE chip_id = ?');
$stmt->execute([$chip]);
$device = $stmt->fetch() ?: null;

$pageTitle = $device ? display_device_name($device) : 'Device not found';

$latest = null;
$rows = [];

if ($device) {
    $stmt = $pdo->prepare('
        SELECT *
        FROM readings
        WHERE chip_id = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([$device['chip_id']]);
    $latest = $stmt->fetch() ?: null;

    $stmt = $pdo->prepare("
        SELECT ts, temp_c, rssi, heap
        FROM readings
        WHERE chip_id = ?
          AND ts >= datetime('now', '-7 day')
        ORDER BY ts ASC
        LIMIT 2000
    ");
    $stmt->execute([$device['chip_id']]);
    $rows = $stmt->fetchAll();
}

include __DIR__ . '/includes/header.php';

?>

<?php if (!$device): ?>

    <div class="alert-item danger">
        Device not found.
    </div>

<?php else: ?>

    <?php $status = device_status($device); ?>

    <div class="cards">
        <div class="card">
            <div class="label">Status</div>
            <div class="value"><?php echo e($status['label']); ?></div>
            <div class="sub">
                Last seen <?php echo e(ago($device['last_seen'])); ?>
            </div>
        </div>

        <div class="card">
            <div class="label">Temperature</div>
            <div class="value">
                <?php
                $temp = $latest['temp_c'] ?? $device['last_temp'];
                echo $temp !== null ? e(number_format((float)$temp, 1) . ' °C') : '—';
                ?>
            </div>
            <div class="sub">
                ADC raw: <?php echo e((string)($latest['adc_raw'] ?? '—')); ?>
            </div>
        </div>

        <div class="card">
            <div class="label">Heap</div>
            <div class="value"><?php echo e(format_bytes($latest['heap'] ?? $device['last_heap'])); ?></div>
            <div class="sub">
                Min: <?php echo e(format_bytes($device['heap_min'])); ?>
            </div>
        </div>

        <div class="card">
            <div class="label">Uptime</div>
            <div class="value" style="font-size:22px;">
                <?php echo e(format_uptime($latest['uptime_s'] ?? null)); ?>
            </div>
            <div class="sub">
                Device reboots every 24h by design
            </div>
        </div>
    </div>

    <div class="card">
        <div class="label">Device information</div>

        <table class="list">
            <tbody>
                <tr>
                    <th style="width:220px;">Chip ID</th>
                    <td><?php echo e($device['chip_id']); ?></td>
                </tr>

                <tr>
                    <th>Device name</th>
                    <td><?php echo e($device['device_name'] ?: '—'); ?></td>
                </tr>

                <tr>
                    <th>MAC</th>
                    <td><?php echo e($device['mac'] ?: '—'); ?></td>
                </tr>

                <tr>
                    <th>IP</th>
                    <td><?php echo e($device['ip'] ?: '—'); ?></td>
                </tr>

                <tr>
                    <th>Firmware</th>
                    <td><?php echo e($device['fw'] ?: '—'); ?></td>
                </tr>

                <tr>
                    <th>SDK</th>
                    <td><?php echo e($device['sdk'] ?: '—'); ?></td>
                </tr>

                <tr>
                    <th>WiFi state</th>
                    <td><?php echo e($device['wifi_state'] ?: '—'); ?></td>
                </tr>

                <tr>
                    <th>Channel</th>
                    <td><?php echo e((string)($latest['channel'] ?? '—')); ?></td>
                </tr>

                <tr>
                    <th>BSSID</th>
                    <td><?php echo e($latest['bssid'] ?? '—'); ?></td>
                </tr>

                <tr>
                    <th>RSSI</th>
                    <td>
                        <?php echo $device['last_rssi'] !== null ? e((int)$device['last_rssi'] . ' dBm') : '—'; ?>
                    </td>
                </tr>

                <tr>
                    <th>Boot count</th>
                    <td><?php echo e((string)(int)($device['boot_count'] ?? 0)); ?></td>
                </tr>

                <tr>
                    <th>WiFi reconnects</th>
                    <td><?php echo e((string)(int)($device['wifi_reconnects'] ?? 0)); ?></td>
                </tr>

                <tr>
                    <th>OTA updates</th>
                    <td><?php echo e((string)(int)($device['ota_updates'] ?? 0)); ?></td>
                </tr>

                <tr>
                    <th>Uploads OK / Total</th>
                    <td>
                        <?php
                        $ok = (int)($device['ok_uploads'] ?? 0);
                        $total = (int)($device['total_uploads'] ?? 0);
                        echo e($total > 0 ? $ok . ' / ' . $total : '—');
                        ?>
                    </td>
                </tr>

                <tr>
                    <th>Consecutive fails</th>
                    <td><?php echo e((string)(int)($device['last_consec_fails'] ?? 0)); ?></td>
                </tr>

                <tr>
                    <th>Spike rejects</th>
                    <td><?php echo e((string)($latest['spike_rejects'] ?? 0)); ?></td>
                </tr>

                <tr>
                    <th>Reset reason</th>
                    <td><?php echo e($device['reset_reason'] ?: '—'); ?></td>
                </tr>

                <tr>
                    <th>Last error</th>
                    <td><?php echo e($device['last_error'] ?: '—'); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="chart-grid">
        <div class="card chart-card">
            <div class="label">Temperature — last 7 days</div>
            <div class="chart-wrap">
                <canvas id="tempChart"></canvas>
            </div>
        </div>

        <div class="card chart-card">
            <div class="label">RSSI — last 7 days</div>
            <div class="chart-wrap">
                <canvas id="rssiChart"></canvas>
            </div>
        </div>
    </div>

    <div class="card chart-card">
        <div class="label">Heap — last 7 days</div>
        <div class="chart-wrap">
            <canvas id="heapChart"></canvas>
        </div>
    </div>

    <?php if (!empty($latest['raw_json'])): ?>
        <div class="card">
            <div class="label">Latest raw payload</div>
            <pre><?php echo e($latest['raw_json']); ?></pre>
        </div>
    <?php endif; ?>

    <?php

    $inlineScripts = '';

    if (!empty($rows)) {
        $labels = [];
        $temps = [];
        $rssis = [];
        $heaps = [];

        foreach ($rows as $r) {
            $labels[] = $r['ts'];
            $temps[] = $r['temp_c'] !== null ? (float)$r['temp_c'] : null;
            $rssis[] = $r['rssi'] !== null ? (int)$r['rssi'] : null;
            $heaps[] = $r['heap'] !== null ? (int)$r['heap'] : null;
        }

        $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        $labelsJson = json_encode($labels, $jsonFlags);
        $tempsJson = json_encode($temps, $jsonFlags);
        $rssisJson = json_encode($rssis, $jsonFlags);
        $heapsJson = json_encode($heaps, $jsonFlags);

        $inlineScripts = <<<HTML
<script>
makeChart('tempChart', $labelsJson, [{
    label: 'Temperature °C',
    data: $tempsJson,
    borderColor: '#0073aa',
    backgroundColor: 'rgba(0,115,170,0.08)',
    fill: true,
    tension: 0.25
}], '°C');

makeChart('rssiChart', $labelsJson, [{
    label: 'RSSI dBm',
    data: $rssisJson,
    borderColor: '#72777c',
    backgroundColor: 'rgba(114,119,124,0.08)',
    fill: true,
    tension: 0.25
}], 'dBm');

makeChart('heapChart', $labelsJson, [{
    label: 'Free heap bytes',
    data: $heapsJson,
    borderColor: '#46b450',
    backgroundColor: 'rgba(70,180,80,0.08)',
    fill: true,
    tension: 0.25
}], 'bytes');
</script>
HTML;
    }

    ?>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
