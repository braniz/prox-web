<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/proxmox.php';

require_login();
$data = proxmox_data();

render_header('Proxmox-Info', 'proxmox');
if (!$data['ok']) {
    echo '<p style="color:#b00"><strong>Fehler:</strong> ' . e((string) $data['error']) . '</p>';
    render_footer();
    exit;
}

$c = $data['cluster'] ?? [];
if (!empty($c['standalone'])) {
    $cluster = 'Einzelner Node (kein Cluster)';
} else {
    $cluster = (string) ($c['name'] ?? '') . ' – ' . (!empty($c['quorate']) ? 'Quorum vorhanden' : 'Kein Quorum');
}
echo '<h2>Cluster</h2><p>' . e($cluster) . '</p><h2>Nodes</h2>'
    . '<table><tr><th>Name</th><th>IP</th><th>Status</th></tr>';
foreach ((array) ($data['nodes'] ?? []) as $n) {
    echo '<tr><td>' . e((string) ($n['name'] ?? '')) . '</td><td>' . e((string) ($n['ip'] ?? '')) . '</td><td>'
        . (!empty($n['online']) ? 'online' : 'offline') . '</td></tr>';
}
echo '</table><h2>VMs und Container</h2>';
$guests = (array) ($data['guests'] ?? []);
if (!$guests) {
    echo '<p>Keine VMs oder Container gefunden.</p>';
} else {
    echo '<table><tr><th>ID</th><th>Name</th><th>Typ</th><th>Node</th><th>Status</th></tr>';
    foreach ($guests as $g) {
        echo '<tr><td>' . e((string) ($g['vmid'] ?? '')) . '</td><td>' . e((string) ($g['name'] ?? '')) . '</td><td>'
            . (($g['type'] ?? '') === 'lxc' ? 'Container' : 'VM') . '</td><td>' . e((string) ($g['node'] ?? ''))
            . '</td><td>' . e((string) ($g['status'] ?? '')) . '</td></tr>';
    }
    echo '</table>';
}
render_footer();
