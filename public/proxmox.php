<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

require_login();
$data = proxmox_data();

render_header('Proxmox-Info', 'proxmox');
if (empty($data['ok'])) {
    echo '<p style="color:#b00"><strong>Fehler:</strong> ' . e((string) ($data['error'] ?? 'Unbekannter Fehler')) . '</p>';
    render_footer();
    exit;
}

$cluster = is_array($data['cluster'] ?? null) ? $data['cluster'] : [];
$quorate = $cluster['quorate'] ?? null;
echo '<h2>Cluster</h2><p><strong>Name:</strong> ' . e((string) (($cluster['name'] ?? '') !== '' ? $cluster['name'] : '–'))
    . '<br><strong>Status:</strong> ' . ($quorate === null ? 'Einzelner Knoten' : ($quorate ? 'Quorum vorhanden' : 'Kein Quorum'))
    . '</p>';

echo '<h2>Knoten</h2><table><tr><th>Name</th><th>Status</th></tr>';
foreach ((array) ($data['nodes'] ?? []) as $n) {
    echo '<tr><td>' . e((string) ($n['name'] ?? '')) . '</td><td>' . (!empty($n['online']) ? 'online' : 'offline') . '</td></tr>';
}
echo '</table>';

echo '<h2>VMs / Container</h2><table><tr><th>ID</th><th>Name</th><th>Typ</th><th>Status</th><th>Knoten</th></tr>';
foreach ((array) ($data['guests'] ?? []) as $g) {
    echo '<tr><td>' . e((string) ($g['vmid'] ?? '')) . '</td><td>' . e((string) ($g['name'] ?? ''))
        . '</td><td>' . (($g['type'] ?? '') === 'lxc' ? 'Container' : 'VM')
        . '</td><td>' . e((string) ($g['status'] ?? '')) . '</td><td>' . e((string) ($g['node'] ?? '')) . '</td></tr>';
}
echo '</table>';
render_footer();
