<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

require_login();

function fmt_bytes($b): string
{
    return is_numeric($b) ? round($b / 1073741824, 1) . ' GiB' : '-';
}

function fmt_pct($v): string
{
    return is_numeric($v) ? round($v * 100, 1) . ' %' : '-';
}

$res = proxmox_fetch();

render_header('Proxmox-Info', 'proxmox');
if (!$res['ok']) {
    echo '<p><strong>Fehler:</strong> ' . e((string) ($res['error'] ?? 'Unbekannt')) . '</p>';
    render_footer();
    exit;
}
$d = $res['data'];
$c = $d['cluster'] ?? [];
echo '<h2>Cluster</h2><p>Name: ' . e((string) ($c['name'] ?? '-'))
    . ' | Nodes: ' . e((string) ($c['nodes'] ?? '-'))
    . ' | Quorum: ' . (!empty($c['quorate']) ? 'ja' : 'nein') . '</p>';

echo '<h2>Nodes</h2><table><tr><th>Name</th><th>Status</th><th>CPU</th><th>RAM</th></tr>';
foreach ($d['nodes'] ?? [] as $n) {
    echo '<tr><td>' . e((string) $n['name']) . '</td><td>' . e((string) $n['status']) . '</td><td>'
        . e(fmt_pct($n['cpu'])) . '</td><td>' . e(fmt_bytes($n['mem']) . ' / ' . fmt_bytes($n['maxmem'])) . '</td></tr>';
}
echo '</table><h2>VMs / Container</h2>'
    . '<table><tr><th>ID</th><th>Name</th><th>Node</th><th>Typ</th><th>Status</th><th>CPU</th><th>RAM</th></tr>';
foreach ($d['vms'] ?? [] as $v) {
    echo '<tr><td>' . e((string) $v['vmid']) . '</td><td>' . e((string) $v['name']) . '</td><td>'
        . e((string) $v['node']) . '</td><td>' . e((string) $v['type']) . '</td><td>' . e((string) $v['status'])
        . '</td><td>' . e(fmt_pct($v['cpu'])) . '</td><td>'
        . e(fmt_bytes($v['mem']) . ' / ' . fmt_bytes($v['maxmem'])) . '</td></tr>';
}
echo '</table>';
render_footer();
