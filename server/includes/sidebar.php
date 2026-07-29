<nav class="sidebar">
    <a href="index.php" class="<?php echo ($current === 'index.php') ? 'current' : ''; ?>">
        <span class="ico">📊</span>
        <span class="txt">Dashboard</span>
    </a>

    <a href="devices.php" class="<?php echo ($current === 'devices.php' || $current === 'device.php') ? 'current' : ''; ?>">
        <span class="ico">🖥</span>
        <span class="txt">Devices</span>
    </a>

    <a href="health.php" class="<?php echo ($current === 'health.php') ? 'current' : ''; ?>">
        <span class="ico">🚨</span>
        <span class="txt">Health</span>
    </a>

    <a href="log.php" class="<?php echo ($current === 'log.php') ? 'current' : ''; ?>">
        <span class="ico">📜</span>
        <span class="txt">Log</span>
    </a>
</nav>
