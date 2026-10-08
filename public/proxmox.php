<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/proxmox.php';

require_login();
$data = proxmox_data();

render_header('Proxmox-Informationen', 'proxmox');

if (empty($data['ok'])) {
    echo '<p style="color:#b00"><strong>Fehler:</strong> ' . e((string) ($data['error'] ?? 'Unbekannter Fehler')) . '</p>';
    render_footer();
    exit;
}

echo '<h2>Cluster</h2><p><strong>Name:</strong> ' . e((string) ($data['cluster']['name'] ?? '-'))
    . '<br><strong>Status:</strong> ' . e((string) ($data['cluster']['status'] ?? '-')) . '</p>';

echo '<h2>Nodes</h2><table><tr><th>Node</th><th>Status</th></tr>';
foreach ((array) ($data['nodes'] ?? []) as $n) {
    echo '<tr><td>' . e((string) ($n['node'] ?? '')) . '</td><td>'
        . (!empty($n['online']) ? 'online' : 'offline') . '</td></tr>';
}
echo '</table>';

echo '<h2>VMs / Container</h2><table><tr><th>ID</th><th>Name</th><th>Typ</th><th>Status</th><th>Node</th></tr>';
foreach ((array) ($data['vms'] ?? []) as $v) {
    $type = ($v['type'] ?? '') === 'lxc' ? 'Container' : 'VM';
    echo '<tr><td>' . e((string) ($v['vmid'] ?? '')) . '</td><td>' . e((string) ($v['name'] ?? ''))
        . '</td><td>' . $type . '</td><td>' . e((string) ($v['status'] ?? ''))
        . '</td><td>' . e((string) ($v['node'] ?? '')) . '</td></tr>';
}
echo '</table>';
render_footer();
