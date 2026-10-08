<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/proxmox.php';

require_login();

render_header('Proxmox-Info', 'proxmox');

$status = proxmox_get('/cluster/status');
if ($status['error'] !== null) {
    echo '<p style="color:#b00"><strong>Fehler:</strong> ' . e($status['error']) . '</p>';
    render_footer();
    exit;
}

$cluster = null;
$nodes = [];
foreach ((array) $status['data'] as $item) {
    if (!is_array($item)) {
        continue;
    }
    if (($item['type'] ?? '') === 'cluster') {
        $cluster = $item;
    } elseif (($item['type'] ?? '') === 'node') {
        $nodes[] = $item;
    }
}

echo '<h2>Cluster</h2>';
if ($cluster !== null) {
    echo '<p>Name: ' . e((string) ($cluster['name'] ?? '-')) . '<br>Knoten: ' . e((string) ($cluster['nodes'] ?? '-'))
        . '<br>Quorum: ' . (!empty($cluster['quorate']) ? 'ja' : 'nein') . '</p>';
} else {
    echo '<p>Einzelner Knoten (kein Cluster).</p>';
}

echo '<h2>Knoten</h2><table><tr><th>Name</th><th>IP</th><th>Status</th></tr>';
foreach ($nodes as $n) {
    echo '<tr><td>' . e((string) ($n['name'] ?? '')) . '</td><td>' . e((string) ($n['ip'] ?? ''))
        . '</td><td>' . (!empty($n['online']) ? 'online' : 'offline') . '</td></tr>';
}
echo '</table>';

echo '<h2>VMs und Container</h2>';
$res = proxmox_get('/cluster/resources?type=vm');
if ($res['error'] !== null) {
    echo '<p style="color:#b00"><strong>Fehler:</strong> ' . e($res['error']) . '</p>';
} else {
    $vms = array_filter((array) $res['data'], 'is_array');
    usort($vms, function ($a, $b) {
        return ((int) ($a['vmid'] ?? 0)) <=> ((int) ($b['vmid'] ?? 0));
    });
    $running = count(array_filter($vms, function ($v) {
        return ($v['status'] ?? '') === 'running';
    }));
    echo '<p>Gesamt: ' . count($vms) . ', laufend: ' . $running . '</p>';
    echo '<table><tr><th>ID</th><th>Name</th><th>Typ</th><th>Knoten</th><th>Status</th></tr>';
    foreach ($vms as $v) {
        $type = ($v['type'] ?? '') === 'lxc' ? 'Container' : 'VM';
        echo '<tr><td>' . e((string) ($v['vmid'] ?? '')) . '</td><td>' . e((string) ($v['name'] ?? ''))
            . '</td><td>' . $type . '</td><td>' . e((string) ($v['node'] ?? ''))
            . '</td><td>' . e((string) ($v['status'] ?? '')) . '</td></tr>';
    }
    echo '</table>';
}
render_footer();
