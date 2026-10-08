<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

function extract_ip_address_value(array $resource): string
{
    $candidates = [];
    foreach (['ip', 'ip-address', 'ip_address', 'ipaddress'] as $key) {
        if (isset($resource[$key]) && is_scalar($resource[$key]) && (string) $resource[$key] !== '') {
            $candidates[] = (string) $resource[$key];
        }
    }

    if (isset($resource['ip_addresses']) && is_array($resource['ip_addresses'])) {
        foreach ($resource['ip_addresses'] as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $candidates[] = (string) $value;
            }
        }
    }

    foreach (['net0', 'net1', 'network', 'net'] as $key) {
        if (!isset($resource[$key]) || !is_string($resource[$key])) {
            continue;
        }
        preg_match_all('/(?:\d{1,3}\.){3}\d{1,3}|[0-9a-fA-F:]{2,}/', $resource[$key], $matches);
        foreach ($matches[0] as $match) {
            if (in_array($match, ['dhcp', 'localhost'], true)) {
                continue;
            }
            if (filter_var($match, FILTER_VALIDATE_IP) !== false) {
                $candidates[] = $match;
            }
        }
    }

    $seen = [];
    $clean = [];
    foreach ($candidates as $candidate) {
        $trimmed = trim((string) $candidate);
        if ($trimmed === '' || isset($seen[$trimmed])) {
            continue;
        }
        $seen[$trimmed] = true;
        $clean[] = $trimmed;
    }

    return implode(', ', $clean);
}

$user = require_login();
$config = load_json('api');
$result = fetch_proxmox_data($config);

if (!$result['success']) {
    render_header('VMs / LXC / Docker', 'resources');
    echo '<p role="alert">' . e((string) $result['error']) . '</p>';
    render_footer();
    exit;
}

$info = $result['data'];
$resources = [];
foreach ($info['resources'] ?? [] as $resource) {
    $type = strtolower((string) ($resource['type'] ?? ''));
    if (!in_array($type, ['qemu', 'lxc', 'docker'], true)) {
        continue;
    }

    $ip = extract_ip_address_value($resource);

    $resources[] = [
        'node' => (string) ($resource['node'] ?? '—'),
        'name' => (string) ($resource['name'] ?? '—'),
        'vmid' => (string) ($resource['vmid'] ?? ''),
        'type' => $type,
        'status' => (string) ($resource['status'] ?? '—'),
        'ip' => $ip,
    ];
}

usort($resources, static function (array $a, array $b): int {
    return strcmp($a['node'] . ':' . $a['name'], $b['node'] . ':' . $b['name']);
});

render_header('VMs / LXC / Docker', 'resources');
echo '<p>Alle virtuellen Maschinen, Container und potenziell zugeordneten Docker-Instanzen aus Proxmox.</p>';

echo '<table><thead><tr><th>Knoten</th><th>Name</th><th>IP-Adresse</th><th>Status</th><th>Typ</th></tr></thead><tbody>';
foreach ($resources as $resource) {
    $typeLabel = match ($resource['type']) {
        'qemu' => 'VM',
        'lxc' => 'LXC',
        'docker' => 'Docker',
        default => 'Unbekannt',
    };
    $href = '/resource.php?id=' . rawurlencode((string) $resource['vmid']) . '&type=' . rawurlencode($resource['type']) . '&node=' . rawurlencode($resource['node']);
    echo '<tr>'
        . '<td>' . e($resource['node']) . '</td>'
        . '<td><a href="' . e($href) . '">' . e($resource['name']) . '</a></td>'
        . '<td>' . e($resource['ip'] !== '' ? $resource['ip'] : '—') . '</td>'
        . '<td>' . e($resource['status']) . '</td>'
        . '<td>' . e($typeLabel) . '</td>'
        . '</tr>';
}
if ($resources === []) {
    echo '<tr><td colspan="5">Keine VMs, LXC-Container oder Docker-Objekte gefunden.</td></tr>';
}
echo '</tbody></table>';

render_footer();
