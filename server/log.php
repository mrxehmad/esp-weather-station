<?php
/**
 * Reading log page - WordPress 2020 admin style
 * Paginated table of latest readings with expandable raw JSON
 */

require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/functions.php';

$pageTitle = 'Reading Log';
$currentPage = 'log';

// Pagination
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

try {
    $db = getDb();
    
    // Get total count
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM readings');
    $totalRows = $stmt->fetch()['cnt'];
    $totalPages = ceil($totalRows / $perPage);
    
    // Get readings for current page
    $stmt = $db->prepare("
        SELECT r.*, d.device_name
        FROM readings r
        LEFT JOIN devices d ON r.chip_id = d.chip_id
        ORDER BY r.ts DESC, r.id DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $readings = $stmt->fetchAll();
    
} catch (Throwable $e) {
    $error = $e->getMessage();
}

include __DIR__ . '/api/includes/header.php';
?>

<div class="wrap">
    <h1>Reading Log</h1>
    
    <?php if (isset($error)): ?>
        <div class="alert-row alert-danger">
            <div class="alert-message">Database error: <?php echo e($error); ?></div>
        </div>
    <?php else: ?>
    
    <!-- Pagination Top -->
    <?php if ($totalPages > 1): ?>
    <div class="mb-2">
        <?php if ($page > 1): ?>
            <a href="?p=<?php echo $page - 1; ?>" class="button">← Previous</a>
        <?php endif; ?>
        
        <span class="text-muted" style="margin:0 10px;">
            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
        </span>
        
        <?php if ($page < $totalPages): ?>
            <a href="?p=<?php echo $page + 1; ?>" class="button">Next →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <table class="wp-list-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Device</th>
                <th>Time</th>
                <th>Temp</th>
                <th>RSSI</th>
                <th>Heap</th>
                <th>WiFi State</th>
                <th>Last Error</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($readings)): ?>
            <tr>
                <td colspan="9" style="text-align:center;padding:40px;">No readings found</td>
            </tr>
            <?php else: ?>
                <?php foreach ($readings as $row): 
                    $hasRawJson = !empty($row['raw_json']);
                ?>
                <tr data-row-id="<?php echo $row['id']; ?>">
                    <td><?php echo $row['id']; ?></td>
                    <td>
                        <strong><?php echo e($row['chip_id']); ?></strong>
                        <?php if (!empty($row['device_name'])): ?>
                            <br><small class="text-muted"><?php echo e($row['device_name']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo e(date('Y-m-d H:i:s', strtotime($row['ts']))); ?></td>
                    <td><?php echo $row['temp_c'] ? number_format($row['temp_c'], 2) . ' °C' : '-'; ?></td>
                    <td><?php echo $row['rssi'] ? $row['rssi'] . ' dBm' : '-'; ?></td>
                    <td><?php echo $row['heap'] ? formatBytes($row['heap']) : '-'; ?></td>
                    <td><?php echo e($row['wifi_state'] ?? '-'); ?></td>
                    <td>
                        <?php if (!empty($row['last_error'])): ?>
                            <span class="status-pill status-degraded">Error</span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($hasRawJson): ?>
                            <button class="button toggle-raw" data-id="<?php echo $row['id']; ?>">Show JSON</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($hasRawJson): ?>
                <tr class="raw-json-row" id="raw-<?php echo $row['id']; ?>" style="display:none;">
                    <td colspan="9" style="background:#fafafa;">
                        <pre><code><?php 
                            $json = json_decode($row['raw_json'], true);
                            echo e(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); 
                        ?></code></pre>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <!-- Pagination Bottom -->
    <?php if ($totalPages > 1): ?>
    <div class="mt-2">
        <?php if ($page > 1): ?>
            <a href="?p=<?php echo $page - 1; ?>" class="button">← Previous</a>
        <?php endif; ?>
        
        <span class="text-muted" style="margin:0 10px;">
            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
        </span>
        
        <?php if ($page < $totalPages): ?>
            <a href="?p=<?php echo $page + 1; ?>" class="button">Next →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle raw JSON display
    document.querySelectorAll('.toggle-raw').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const rowId = this.getAttribute('data-id');
            const rawRow = document.getElementById('raw-' + rowId);
            
            if (rawRow.style.display === 'none') {
                rawRow.style.display = 'table-row';
                this.textContent = 'Hide JSON';
            } else {
                rawRow.style.display = 'none';
                this.textContent = 'Show JSON';
            }
        });
    });
});
</script>

<?php include __DIR__ . '/api/includes/footer.php'; ?>
