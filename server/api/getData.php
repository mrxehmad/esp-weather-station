<?php
/**
 * Retrieve temperature data from SQLite database
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Database path
$dbPath = __DIR__ . '/../data/temperature.db';

// Get query parameters
$hours = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10000;

// Validate parameters – support up to 1 year
if ($hours < 1)    $hours = 24;
if ($hours > 8760) $hours = 8760;
if ($limit < 1)    $limit = 10000;
if ($limit > 50000) $limit = 50000;

// Check if database exists
if (!file_exists($dbPath)) {
    http_response_code(404);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database not found. Run init_database.php first.',
        'data'    => [],
        'stats'   => null
    ]);
    exit();
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $cutoffTime = time() - ($hours * 3600);

    // ── Main data query ──────────────────────────────────────────────────────
    // For large ranges, downsample to avoid sending millions of points.
    // We pick at most 2000 representative points via sqlite's rowid modulo trick.
    $stmt = $db->prepare('
        SELECT
            d.id,
            d.received_at,
            d.device_timestamp,
            s.temperature,
            s.offset
        FROM temperature_data d
        INNER JOIN temperature_samples s ON d.id = s.data_id
        WHERE d.received_at >= :cutoff_time
        ORDER BY d.device_timestamp ASC, s.offset ASC
        LIMIT :limit
    ');

    $stmt->bindValue(':cutoff_time', $cutoffTime, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($results)) {
        http_response_code(404);
        echo json_encode([
            'status'  => 'error',
            'message' => 'No data available for the selected time range',
            'data'    => [],
            'stats'   => null,
            'filter'  => [
                'hours' => $hours,
                'from'  => date('Y-m-d H:i:s', $cutoffTime),
                'to'    => date('Y-m-d H:i:s')
            ]
        ]);
        exit();
    }

    // ── Flatten ───────────────────────────────────────────────────────────────
    $flatData = [];
    foreach ($results as $row) {
        $flatData[] = [
            'timestamp'   => intval($row['device_timestamp']) + intval($row['offset']),
            'temperature' => floatval($row['temperature']),
            'received_at' => intval($row['received_at'])
        ];
    }

    // ── Downsample for very long ranges (keep ≤ 2 000 points for the chart) ──
    $maxPoints  = 2000;
    $chartData  = $flatData;
    if (count($flatData) > $maxPoints) {
        $step      = count($flatData) / $maxPoints;
        $chartData = [];
        for ($i = 0; $i < $maxPoints; $i++) {
            $chartData[] = $flatData[(int)round($i * $step)];
        }
    }

    // ── Stats for selected range ───────────────────────────────────────────────
    $temps  = array_column($flatData, 'temperature');
    $stats  = [
        'current'        => end($temps),
        'min'            => min($temps),
        'max'            => max($temps),
        'avg'            => round(array_sum($temps) / count($temps), 2),
        'total_readings' => count($flatData)
    ];

    // ── Peak stats: daily / weekly / monthly ──────────────────────────────────
    // Always query from the full database for these so they stay meaningful.
    function getPeakStats(PDO $db, int $secondsBack): array {
        $cutoff = time() - $secondsBack;
        $row    = $db->query("
            SELECT
                MAX(s.temperature) as high,
                MIN(s.temperature) as low
            FROM temperature_data d
            INNER JOIN temperature_samples s ON d.id = s.data_id
            WHERE d.received_at >= {$cutoff}
        ")->fetch(PDO::FETCH_ASSOC);

        return [
            'high' => $row && $row['high'] !== null ? round(floatval($row['high']), 1) : null,
            'low'  => $row && $row['low']  !== null ? round(floatval($row['low']),  1) : null
        ];
    }

    $peakStats = [
        'daily'   => getPeakStats($db, 86400),
        'weekly'  => getPeakStats($db, 604800),
        'monthly' => getPeakStats($db, 2592000)
    ];

    // ── Database metadata ─────────────────────────────────────────────────────
    $dbInfo = [
        'total_records' => $db->query('SELECT COUNT(*) FROM temperature_data')->fetchColumn(),
        'total_samples' => $db->query('SELECT COUNT(*) FROM temperature_samples')->fetchColumn(),
        'oldest_record' => $db->query('SELECT MIN(received_at) FROM temperature_data')->fetchColumn(),
        'newest_record' => $db->query('SELECT MAX(received_at) FROM temperature_data')->fetchColumn(),
    ];

    http_response_code(200);
    echo json_encode([
        'status'        => 'success',
        'data'          => $chartData,
        'stats'         => $stats,
        'peak_stats'    => $peakStats,
        'filter'        => [
            'hours' => $hours,
            'from'  => date('Y-m-d H:i:s', $cutoffTime),
            'to'    => date('Y-m-d H:i:s')
        ],
        'database_info' => $dbInfo
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database error: ' . $e->getMessage(),
        'data'    => [],
        'stats'   => null
    ]);
}
?>
