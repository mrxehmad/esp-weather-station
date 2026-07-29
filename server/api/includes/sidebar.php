<?php
/**
 * Sidebar navigation for WordPress 2020 admin style dashboard
 */

$currentPage = $currentPage ?? '';

$menuItems = [
    ['id' => 'dashboard', 'label' => 'Dashboard', 'icon' => '📊', 'url' => '../index.php'],
    ['id' => 'devices', 'label' => 'Devices', 'icon' => '📱', 'url' => 'devices.php'],
    ['id' => 'health', 'label' => 'Health', 'icon' => '❤️', 'url' => 'health.php'],
    ['id' => 'log', 'label' => 'Log', 'icon' => '📋', 'url' => 'log.php'],
];
?>
<div id="adminmenuwrap">
    <ul id="adminmenu">
        <?php foreach ($menuItems as $item): ?>
            <li class="<?php echo $currentPage === $item['id'] ? 'current' : ''; ?>">
                <a href="<?php echo e($item['url']); ?>" data-icon="<?php echo e($item['icon']); ?>">
                    <?php echo e($item['label']); ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
