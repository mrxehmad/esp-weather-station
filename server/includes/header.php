<?php

require_once __DIR__ . '/functions.php';

$current = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
$pageTitle = $pageTitle ?? 'Dashboard';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($pageTitle . ' - ' . APP_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>

<div id="wpadminbar">
    <strong><a href="index.php"><?php echo e(APP_NAME); ?></a></strong>
    <span class="ab-item">ESP8266 Temperature Monitor</span>
</div>

<div class="layout">
<?php require __DIR__ . '/sidebar.php'; ?>

<main class="content">
    <h1 class="page-title"><?php echo e($pageTitle); ?></h1>
