<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/includes/db.php';

$pdo = db();

$pdo->exec(
    "DELETE FROM readings WHERE ts < datetime('now', '-" . (int)RETENTION_DAYS . " day')"
);

echo "Cleanup complete\n";
