<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/proxmox.php';

require_login();
$data = proxmox_data();

render_header('Proxmox-Info', 'proxmox');
if (empty($data['ok'])) {
    echo '<p style="color:#b00">Fehler: ' . e((string) ($data['error'] ?? 'Unbekannter Fehler')) . '</p>';
    render_footer();
    exit;
}

$cluster = is_array($data['cluster'] ?? null) ? $data['cluster'] : [];
$name = (string) ($cluster['name'] ?? '');
$quorate = $cluster['quorate'] ?? null;
echo '<h2>Cluster</h2><p>Name: ' . e($name !== '' ? $name : 'Einzelner Node (kein Cluster)') . '<br>Status: '
    . ($quorate === null ? '–' : ($quorate ? 'Quorum vorhanden' : 'Kein Quorum')) . '</p>';

echo '<h2>Nodes</h2><table><tr><th>Node</th><th>Status</th></tr>';
foreach ((array) ($data['nodes'] ?? []) as $n) {
    echo '<tr><td>' . e((string) ($n['name'] ?? '')) . '</td><td>'
        . (!empty($n['online']) ? 'online' : 'offline') . '</td></tr>';
}
echo '</table>';

$guests = (array) ($data['guests'] ?? []);
$running = 0;
foreach ($guests as $g) {
    if (($g['status'] ?? '') === 'running') {
        $running++;
    }
}
echo '<h2>VMs / Container</h2><p>' . count($guests) . ' gesamt, ' . $running . ' laufend</p>'
    . '<table><tr><th>ID</th><th>Name</th><th>Typ</th><th>Status</th><th>Node</th></tr>';
foreach ($guests as $g) {
    echo '<tr><td>' . e((string) ($g['vmid'] ?? '')) . '</td><td>' . e((string) ($g['name'] ?? '')) . '</td><td>'
        . (($g['type'] ?? '') === 'lxc' ? 'Container' : 'VM') . '</td><td>'
        . e((string) ($g['status'] ?? '')) . '</td><td>' . e((string) ($g['node'] ?? '')) . '</td></tr>';
}
echo '</table>';
render_footer();
