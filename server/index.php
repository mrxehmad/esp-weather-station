<?php

require_once __DIR__ . '/includes/functions.php';

$pdo = db();

$devices = $pdo->query('
    SELECT chip_id, device_name
    FROM devices
    ORDER BY last_seen DESC
')->fetchAll();

$chip = $_GET['chip'] ?? ($devices[0]['chip_id'] ?? null);

$device = null;
$latest = null;
$rows = [];

if ($chip) {
    $stmt = $pdo->prepare('SELECT * FROM devices WHERE chip_id = ?');
    $stmt->execute([$chip]);
    $device = $stmt->fetch() ?: null;
}

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
        SELECT ts, temp_c, rssi
        FROM readings
        WHERE chip_id = ?
          AND ts >= datetime('now', '-1 day')
        ORDER BY ts ASC
        LIMIT 600
    ");
    $stmt->execute([$device['chip_id']]);
    $rows = $stmt->fetchAll();
}

$pageTitle = 'Dashboard';

include __DIR__ . '/includes/header.php';

?>

<?php if (!$device): ?>

    <div class="notice">
        No devices yet.
        <br><br>
        Point your ESP8266 to:
        <br>
        <code>http://your-server/api/receive.php</code>
        <br><br>
        Test with:
        <br>
        <pre>curl -i -X POST http://your-server/api/receive.php \
  -H "Content-Type: application/json" \
  -d '{"temperature":21.45,"rssi":-61,"boot_count":12,"fails":0}'</pre>
    </div>

<?php else: ?>

    <form method="get" class="device-select">
        <label for="chip"><strong>Device</strong></label>

        <select id="chip" name="chip" onchange="this.form.submit()">
            <?php foreach ($devices as $d): ?>
                <option value="<?php echo e($d['chip_id']); ?>" <?php echo ($d['chip_id'] === $device['chip_id']) ? 'selected' : ''; ?>>
                    <?php echo e(display_device_name($d)); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <a class="button" href="device.php?chip=<?php echo e(urlencode($device['chip_id'])); ?>">
            Open device detail
        </a>
    </form>

    <?php
    $status = device_status($device);
    $cardClass = 'ok';

    if ($status['class'] === 'offline') {
        $cardClass = 'alert';
    } elseif ($status['class'] === 'degraded') {
        $cardClass = 'warn';
    }

    $temp = $latest['temp_c'] ?? $device['last_temp'];
    $rssi = $device['last_rssi'];
    $heapMin = $device['heap_min'];
    $okUploads = (int)($device['ok_uploads'] ?? 0);
    $totalUploads = (int)($device['total_uploads'] ?? 0);
    ?>

    <div class="cards">
        <div class="card">
            <div class="label">Temperature</div>
            <div class="value">
                <?php echo $temp !== null ? e(number_format((float)$temp, 1) . ' °C') : '—'; ?>
            </div>
            <div class="sub">
                Last seen <?php echo e(ago($device['last_seen'])); ?>
            </div>
        </div>

        <div class="card <?php echo e($cardClass); ?>">
            <div class="label">Status</div>
            <div class="value"><?php echo e($status['label']); ?></div>
            <div class="sub">
                Fails: <?php echo e((string)(int)($device['last_consec_fails'] ?? 0)); ?>
            </div>
        </div>

        <div class="card">
            <div class="label">WiFi RSSI</div>
            <div class="value">
                <?php echo $rssi !== null ? e((int)$rssi . ' dBm') : '—'; ?>
            </div>
            <div class="sub">
                Channel <?php echo e((string)($latest['channel'] ?? '—')); ?>
            </div>
        </div>

        <div class="card">
            <div class="label">Heap Minimum</div>
            <div class="value"><?php echo e(format_bytes($heapMin)); ?></div>
            <div class="sub">
                Current: <?php echo e(format_bytes($latest['heap'] ?? $device['last_heap'])); ?>
            </div>
        </div>

        <div class="card">
            <div class="label">Uploads</div>
            <div class="value">
                <?php echo $totalUploads > 0 ? e($okUploads . ' / ' . $totalUploads) : '—'; ?>
            </div>
            <div class="sub">
                Success rate: <?php echo e(upload_rate($device)); ?>
            </div>
        </div>
    </div>

    <?php if (!empty($device['last_error'])): ?>
        <div class="alert-item danger">
            <strong>Last device error</strong><br>
            <?php echo e($device['last_error']); ?>
        </div>
    <?php endif; ?>

    <div class="chart-grid">
        <div class="card chart-card">
            <div class="label">Temperature — last 24 hours</div>
            <div class="chart-wrap">
                <canvas id="tempChart"></canvas>
            </div>
        </div>

        <div class="card chart-card">
            <div class="label">RSSI — last 24 hours</div>
            <div class="chart-wrap">
                <canvas id="rssiChart"></canvas>
            </div>
        </div>
    </div>

    <?php

    $inlineScripts = '';

    if (!empty($rows)) {
        $labels = [];
        $temps = [];
        $rssis = [];

        foreach ($rows as $r) {
            $labels[] = $r['ts'];
            $temps[] = $r['temp_c'] !== null ? (float)$r['temp_c'] : null;
            $rssis[] = $r['rssi'] !== null ? (int)$r['rssi'] : null;
        }

        $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        $labelsJson = json_encode($labels, $jsonFlags);
        $tempsJson = json_encode($temps, $jsonFlags);
        $rssisJson = json_encode($rssis, $jsonFlags);

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
</script>
HTML;
    }

    ?>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
