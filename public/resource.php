<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$config = load_json('api');
$id = trim((string) ($_GET['id'] ?? ''));
$type = strtolower(trim((string) ($_GET['type'] ?? '')));
$node = trim((string) ($_GET['node'] ?? ''));

if ($id === '' || $node === '' || !in_array($type, ['qemu', 'lxc', 'docker'], true)) {
    render_header('Ressource', 'resources');
    echo '<p>Keine gültige Ressource ausgewählt.</p>';
    render_footer();
    exit;
}

$result = fetch_proxmox_data($config, ['node' => $node, 'vmid' => $id, 'type' => $type]);

render_header('Details: ' . $id, 'resources');
if (!$result['success']) {
    echo '<p role="alert">' . e((string) $result['error']) . '</p>';
    render_footer();
    exit;
}

$detail = $result['data'];
$typeLabel = match ($type) {
    'qemu' => 'VM',
    'lxc' => 'LXC',
    'docker' => 'Docker',
    default => 'Unbekannt',
};

$proxmox = is_array($detail['proxmox'] ?? null) ? $detail['proxmox'] : [];
$guestAgent = is_array($detail['guest_agent'] ?? null) ? $detail['guest_agent'] : [];

$proxmoxSummary = [];
foreach ($proxmox as $key => $value) {
    if (is_array($value)) {
        continue;
    }
    $proxmoxSummary[] = '<strong>' . e((string) $key) . ':</strong> ' . e((string) $value);
}

$agentSummary = [];
if (isset($guestAgent['result']) && is_array($guestAgent['result'])) {
    foreach ($guestAgent['result'] as $key => $value) {
        $agentSummary[] = '<strong>' . e((string) $key) . ':</strong> ' . e(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE));
    }
}
if ($guestAgent === []) {
    $agentSummary[] = 'Keine Daten vom QEMU- oder LXC-Agent erhalten.';
}

echo '<p><strong>Knoten:</strong> ' . e($node)
    . ' · <strong>Typ:</strong> ' . e($typeLabel)
    . ' · <strong>ID:</strong> ' . e((string) $id) . '</p>';

echo '<h2>Proxmox-Informationen</h2>';
if ($proxmoxSummary === []) {
    echo '<p>Keine direkten Proxmox-Details verfügbar.</p>';
} else {
    echo '<ul><li>' . implode('</li><li>', $proxmoxSummary) . '</li></ul>';
}

echo '<h2>QEMU/LXC-Agent</h2>';
if ($agentSummary === []) {
    echo '<p>Keine Daten vom Guest-Agent verfügbar.</p>';
} else {
    echo '<ul><li>' . implode('</li><li>', $agentSummary) . '</li></ul>';
}

if (isset($proxmox['name']) || isset($proxmox['cpus']) || isset($proxmox['memory'])) {
    echo '<h2>Direktwerte</h2><pre>' . e(json_encode($proxmox, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
}

render_footer();
