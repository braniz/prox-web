<?php

declare(strict_types=1);

$tmpDir = sys_get_temp_dir() . '/prox-web-test-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0700, true);
define('DATA_DIR', $tmpDir);
register_shutdown_function(static function () use ($tmpDir): void {
    array_map('unlink', glob($tmpDir . '/*') ?: []);
    @rmdir($tmpDir);
});

require __DIR__ . '/../src/bootstrap.php';

$created = ensure_red_update_kanban_task('101', 'iqprox01', 'web01', 'apt-update: 2024-01-01');
if (!$created) {
    fwrite(STDERR, "Expected red update to create a Kanban task\n");
    exit(1);
}

$tasks = load_kanban_tasks();
if (count($tasks) !== 1) {
    fwrite(STDERR, "Expected exactly one Kanban task, got " . count($tasks) . "\n");
    exit(1);
}

if ((string) ($tasks[0]['title'] ?? '') !== 'Apt-Update prüfen: web01') {
    fwrite(STDERR, "Unexpected task title: " . ($tasks[0]['title'] ?? '') . "\n");
    exit(1);
}

if ((string) ($tasks[0]['creator'] ?? '') !== 'System (Web-Logik)') {
    fwrite(STDERR, "Unexpected creator: " . ($tasks[0]['creator'] ?? '') . "\n");
    exit(1);
}

echo "Kanban red-update regression test passed\n";
