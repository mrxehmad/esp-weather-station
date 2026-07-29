<?php
/**
 * Header include for WordPress 2020 admin style dashboard
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    define('ABSPATH', true);
}

$pageTitle = $pageTitle ?? 'Temperature Monitor';
$currentPage = $currentPage ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> - Temperature Monitor</title>
    <link rel="stylesheet" href="assets/css/admin.css">
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="wp-admin">
    <!-- Top Admin Bar -->
    <div id="wpadminbar">
        <div class="ab-wrap">
            <span class="ab-site-title">🌡️ Temperature Monitor</span>
        </div>
    </div>
    
    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>
    
    <!-- Main Content -->
    <div id="wpcontent">
