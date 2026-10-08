<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$config = load_json('api');
$info = null;
$error = '';

if (!function_exists('proc_open')) {
    $error = 'Die Python-Komponente kann auf diesem Server nicht gestartet werden.';
} else {
    $pipes = [];
    $process = @proc_open(
        ['python3', __DIR__ . '/../python/proxmox.py'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__)
    );

    if (!is_resource($process)) {
        $error = 'Die Python-Komponente kann auf diesem Server nicht gestartet werden.';
    } else {
        $input = json_encode($config);
        fwrite($pipes[0], $input === false ? '{}' : $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode($output, true);

        if (is_array($result) && !empty($result['success']) && is_array($result['data'] ?? null)) {
            $info = $result['data'];
        } else {
            $error = is_array($result) && is_string($result['error'] ?? null)
                ? $result['error']
                : 'Die Cluster-Informationen konnten nicht geladen werden.';
        }
    }
}

header('Cache-Control: no-store');
render_header('Proxmox-Cluster', 'user');
echo '<p>Angemeldet als ' . e($user['username']) . ' ('
    . e(trim($user['firstname'] . ' ' . $user['lastname'])) . ')</p>';

echo '<p>API-Info: Host ' . e((string) ($config['host'] ?? '—'))
    . ' · Port ' . e((string) ($config['port'] ?? 8006))
    . ' · TLS ' . e(api_tls_enabled($config) ? 'ja' : 'nein') . '</p>';

if ($error !== '') {
    echo '<p role="alert">' . e($error) . '</p>';
    echo '<p>Bitte prüfen Sie die API-Zugangsdaten unter „Admin: API-Info“ oder wenden Sie sich an einen Administrator.</p>';
} else {
    $cluster = null;
    foreach ($info['cluster'] ?? [] as $item) {
        if (($item['type'] ?? '') === 'cluster') {
            $cluster = $item;
            break;
        }
    }
    if (is_array($cluster)) {
        echo '<h2>Cluster</h2><p>Name: ' . e((string) ($cluster['name'] ?? '—'))
            . ' · Status: ' . e(!empty($cluster['quorate']) ? 'Quorum vorhanden' : 'Kein Quorum')
            . ' · Knoten: ' . e((string) ($cluster['nodes'] ?? '—')) . '</p>';
    }

    echo '<h2>Knoten</h2><table><thead><tr><th>Name</th><th>Status</th><th>CPU</th><th>Arbeitsspeicher</th></tr></thead><tbody>';
    foreach ($info['nodes'] ?? [] as $node) {
        $cpu = is_numeric($node['cpu'] ?? null) ? number_format((float) $node['cpu'] * 100, 1) . '%' : '—';
        $memory = is_numeric($node['mem'] ?? null) && is_numeric($node['maxmem'] ?? null)
            ? number_format((float) $node['mem'] / 1073741824, 1) . ' / '
                . number_format((float) $node['maxmem'] / 1073741824, 1) . ' GiB'
            : '—';
        echo '<tr><td>' . e((string) ($node['node'] ?? '—')) . '</td><td>'
            . e((string) ($node['status'] ?? '—')) . '</td><td>' . e($cpu)
            . '</td><td>' . e($memory) . '</td></tr>';
    }
    if (empty($info['nodes'])) {
        echo '<tr><td colspan="4">Keine Knoten gefunden.</td></tr>';
    }
    echo '</tbody></table>';

    echo '<h2>Virtuelle Maschinen und Container</h2><table><thead><tr><th>ID</th><th>Name</th><th>Typ</th><th>Knoten</th><th>Status</th></tr></thead><tbody>';
    $resourceCount = 0;
    foreach ($info['resources'] ?? [] as $resource) {
        if (!in_array($resource['type'] ?? '', ['qemu', 'lxc'], true)) {
            continue;
        }
        ++$resourceCount;
        echo '<tr><td>' . e((string) ($resource['vmid'] ?? '—')) . '</td><td>'
            . e((string) ($resource['name'] ?? '—')) . '</td><td>'
            . e(($resource['type'] ?? '') === 'qemu' ? 'VM' : 'Container') . '</td><td>'
            . e((string) ($resource['node'] ?? '—')) . '</td><td>'
            . e((string) ($resource['status'] ?? '—')) . '</td></tr>';
    }
    if ($resourceCount === 0) {
        echo '<tr><td colspan="5">Keine virtuellen Maschinen oder Container gefunden.</td></tr>';
    }
    echo '</tbody></table>';
}

echo '<p><a href="/profile.php">Passwort ändern</a></p>';
render_footer();
