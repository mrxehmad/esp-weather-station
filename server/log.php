<?php

require_once __DIR__ . '/includes/functions.php';

$pdo = db();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$total = (int)$pdo->query('SELECT COUNT(*) FROM readings')->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare('
    SELECT *
    FROM readings
    ORDER BY id DESC
    LIMIT :limit OFFSET :offset
');

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll();

$pageTitle = 'Reading Log';

include __DIR__ . '/includes/header.php';

?>

<div class="card" style="margin-bottom:16px;">
    <strong>Total readings:</strong> <?php echo e((string)$total); ?>
    &nbsp;|&nbsp;
    <strong>Page:</strong> <?php echo e((string)$page); ?> / <?php echo e((string)$totalPages); ?>
</div>

<div class="table-card">
    <table class="list">
        <thead>
            <tr>
                <th>Time UTC</th>
                <th>Device</th>
                <th>Temp</th>
                <th>RSSI</th>
                <th>Heap</th>
                <th>Fails</th>
                <th>WiFi</th>
                <th>Last error</th>
                <th>Raw</th>
            </tr>
        </thead>

        <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="9">No readings yet.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo e($r['ts']); ?></td>

                        <td>
                            <a href="device.php?chip=<?php echo e(urlencode($r['chip_id'])); ?>">
                                <?php echo e($r['chip_id']); ?>
                            </a>
                        </td>

                        <td>
                            <?php echo $r['temp_c'] !== null ? e(number_format((float)$r['temp_c'], 2) . ' °C') : '—'; ?>
                        </td>

                        <td>
                            <?php echo $r['rssi'] !== null ? e((int)$r['rssi'] . ' dBm') : '—'; ?>
                        </td>

                        <td><?php echo e(format_bytes($r['heap'])); ?></td>

                        <td><?php echo e((string)(int)($r['consec_fails'] ?? 0)); ?></td>

                        <td><?php echo e($r['wifi_state'] ?: '—'); ?></td>

                        <td>
                            <?php echo !empty($r['last_error']) ? e($r['last_error']) : '—'; ?>
                        </td>

                        <td>
                            <?php if (!empty($r['raw_json'])): ?>
                                <details>
                                    <summary>view</summary>
                                    <pre><?php echo e($r['raw_json']); ?></pre>
                                </details>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div style="margin-top:16px;">
    <?php if ($page > 1): ?>
        <a class="button" href="?page=<?php echo e((string)($page - 1)); ?>">Newer</a>
    <?php endif; ?>

    <?php if ($page < $totalPages): ?>
        <a class="button" href="?page=<?php echo e((string)($page + 1)); ?>">Older</a>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
