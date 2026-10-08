<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

function split_ip_addresses($node): array
{
    $result = ['ipv4' => [], 'ipv6' => []];
    $seen = [];

    $add = static function ($value) use (&$result, &$seen): void {
        if (!is_scalar($value) && !is_array($value)) {
            return;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return;
        }

        $compact = preg_replace('/\/.*$/', '', $text);
        $compact = preg_replace('/%.*$/', '', (string) $compact);
        $compact = trim((string) $compact);
        if ($compact === '' || !filter_var($compact, FILTER_VALIDATE_IP)) {
            return;
        }

        if (preg_match('/^127\./', $compact) || strtolower($compact) === '::1' || preg_match('/^169\.254\./', $compact) || preg_match('/^fe80:/i', $compact)) {
            return;
        }

        if (isset($seen[$compact])) {
            return;
        }
        $seen[$compact] = true;

        if (str_contains($compact, ':')) {
            $result['ipv6'][] = $compact;
            return;
        }
        $result['ipv4'][] = $compact;
    };

    $extractTextTokens = static function (string $text): array {
        $candidates = [];
        foreach (preg_split('/[\s,]+/', $text) as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $raw = $part;
            if (str_contains($raw, '=')) {
                [$key, $value] = array_pad(explode('=', $raw, 2), 2, '');
                if ($value !== '') {
                    $raw = $value;
                }
            }
            foreach (preg_split('/[;]+/', $raw) as $candidate) {
                $candidate = trim((string) $candidate, " \t\n\r\0\x0B[](){}\"'");
                $clean = preg_replace('/\/.*$/', '', $candidate);
                $clean = preg_replace('/%.*$/', '', (string) $clean);
                $clean = trim((string) $clean);
                if ($clean === '' || !filter_var($clean, FILTER_VALIDATE_IP)) {
                    continue;
                }
                $candidates[] = $clean;
            }
        }
        return array_values(array_unique($candidates));
    };

    $walk = static function ($value) use (&$walk, $add, $extractTextTokens): void {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $keyLower = strtolower((string) $key);
                if (strpos($keyLower, 'ip') !== false || in_array($keyLower, ['net0', 'net1', 'network', 'net'], true)) {
                    if (is_array($item)) {
                        foreach ($item as $subItem) {
                            $add($subItem);
                        }
                    } else {
                        $add($item);
                    }
                }
                if (is_array($item)) {
                    $walk($item);
                }
            }
            return;
        }

        if (is_string($value)) {
            foreach ($extractTextTokens($value) as $candidate) {
                if (in_array(strtolower((string) $candidate), ['dhcp', 'localhost'], true)) {
                    continue;
                }
                $add($candidate);
            }
        }
    };

    $walk($node);
    return $result;
}

function apt_update_state(string $text): string
{
    $dates = [];
    preg_match_all('/\b(\d{4}-\d{2}-\d{2}|\d{2}\.\d{2}\.\d{4}|\d{4}\/\d{2}\/\d{2}|\d{2}\/\d{2}\/\d{4})\b/', $text, $matches);
    foreach (($matches[1] ?? []) as $candidateDate) {
        $parsed = null;
        foreach (['Y-m-d', 'd.m.Y', 'Y/m/d', 'd/m/Y'] as $format) {
            $date = DateTime::createFromFormat('!' . $format, $candidateDate);
            if ($date instanceof DateTime) {
                $parsed = $date->getTimestamp();
                break;
            }
        }
        if ($parsed !== null) {
            $dates[] = $parsed;
        }
    }

    if ($dates === []) {
        return 'neutral';
    }

    $ageDays = (int) floor((time() - max($dates)) / 86400);
    if ($ageDays >= 40) {
        return 'red';
    }
    if ($ageDays >= 30) {
        return 'yellow';
    }
    return 'green';
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

    $ips = split_ip_addresses($resource);
    if ((empty($ips['ipv4']) && empty($ips['ipv6'])) && in_array($type, ['qemu', 'lxc'], true)) {
        $detail = fetch_proxmox_data($config, ['node' => $resource['node'], 'vmid' => $resource['vmid'], 'type' => $resource['type']]);
        if ($detail['success']) {
            $guestAgent = $detail['data']['guest_agent'] ?? [];
            if (empty($guestAgent) && isset($detail['data']['guest_cmds']['network'])) {
                $guestAgent = json_decode((string) $detail['data']['guest_cmds']['network'], true);
            }
            $ips = split_ip_addresses($guestAgent);
        }
    }

    $updateText = '';
    $updateData = fetch_proxmox_data($config, ['node' => (string) ($resource['node'] ?? ''), 'vmid' => (string) ($resource['vmid'] ?? ''), 'type' => $type, 'read_file' => '/srv/info/apt_update_info']);
    if ($updateData['success'] && is_array($updateData['data'] ?? null)) {
        $updateText = trim((string) ($updateData['data']['host_info'] ?? ''));
    }
    ensure_red_update_kanban_task((string) ($resource['vmid'] ?? ''), (string) ($resource['node'] ?? ''), (string) ($resource['name'] ?? ''), $updateText);

    $resources[] = [
        'node' => (string) ($resource['node'] ?? '—'),
        'name' => (string) ($resource['name'] ?? '—'),
        'vmid' => (string) ($resource['vmid'] ?? ''),
        'type' => $type,
        'status' => (string) ($resource['status'] ?? '—'),
        'ipv4' => $ips['ipv4'],
        'ipv6' => $ips['ipv6'],
        'update_state' => apt_update_state($updateText),
    ];
}

usort($resources, static function (array $a, array $b): int {
    return strcmp($a['node'] . ':' . $a['name'], $b['node'] . ':' . $a['name']);
});

$nodeGroups = [];
foreach ($resources as $resource) {
    $nodeName = $resource['node'];
    if (!isset($nodeGroups[$nodeName])) {
        $nodeGroups[$nodeName] = [
            'items' => [],
            'total' => 0,
            'running' => 0,
            'stopped' => 0,
            'vm' => 0,
            'container' => 0,
        ];
    }
    $nodeGroups[$nodeName]['items'][] = $resource;
    ++$nodeGroups[$nodeName]['total'];
    $status = strtolower((string) $resource['status']);
    if (in_array($status, ['running', 'online', 'active'], true)) {
        ++$nodeGroups[$nodeName]['running'];
    } else {
        ++$nodeGroups[$nodeName]['stopped'];
    }
    if ($resource['type'] === 'qemu') {
        ++$nodeGroups[$nodeName]['vm'];
    }
    if ($resource['type'] === 'lxc') {
        ++$nodeGroups[$nodeName]['container'];
    }
}
ksort($nodeGroups);

render_header('VMs / LXC / Docker', 'resources');

foreach ($nodeGroups as $nodeName => $group) {
    echo '<details class="section-card" open style="margin-top:.65rem;padding:0;overflow:hidden;">'
        . '<summary style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;cursor:pointer;list-style:none;padding:.75rem .85rem;background:#f8fafc;border-bottom:1px solid #dfe7f1;">'
        . '<span style="font-weight:700;font-size:1.05rem;">Knoten: ' . e($nodeName) . '</span>'
        . '<span style="display:flex;gap:.4rem;flex-wrap:wrap;">'
        . '<span class="tag" style="background:#eef2ff;color:#4338ca;">Gesamt ' . e((string) $group['total']) . '</span>'
        . '<span class="tag" style="background:#ecfdf5;color:#166534;">Laufend ' . e((string) $group['running']) . '</span>'
        . '<span class="tag" style="background:#fef2f2;color:#991b1b;">Gestoppt ' . e((string) $group['stopped']) . '</span>'
        . '</span>'
        . '</summary>'
        . '<div style="padding:.65rem .85rem .8rem;">'
        . '<table style="margin-top:0.2rem;table-layout:fixed;width:100%;">'
        . '<colgroup><col style="width:26%"><col style="width:34%"><col style="width:15%"><col style="width:13%"><col style="width:12%"></colgroup>'
        . '<thead><tr><th style="padding:.45rem .6rem;">Name</th><th style="padding:.45rem .6rem;">IP-Adresse</th><th style="padding:.45rem .6rem;">Status</th><th style="padding:.45rem .6rem;">Typ</th><th style="padding:.45rem .6rem;text-align:center;">Update</th></tr></thead><tbody>';

    foreach ($group['items'] as $resource) {
        $typeLabel = match ($resource['type']) {
            'qemu' => 'VM',
            'lxc' => 'LXC',
            'docker' => 'Docker',
            default => 'Unbekannt',
        };
        $href = '/resource.php?id=' . rawurlencode((string) $resource['vmid']) . '&type=' . rawurlencode($resource['type']) . '&node=' . rawurlencode($resource['node']);
        $ipHtml = '—';
        if (!empty($resource['ipv4']) || !empty($resource['ipv6'])) {
            $parts = [];
            if (!empty($resource['ipv4'])) {
                $parts[] = 'IPv4: ' . e(implode(', ', $resource['ipv4']));
            }
            if (!empty($resource['ipv6'])) {
                $parts[] = 'IPv6: ' . e(implode(', ', $resource['ipv6']));
            }
            $ipHtml = '<div>' . implode('<br>', $parts) . '</div>';
        }
        $statusClass = strtolower((string) $resource['status']) === 'running' ? '' : 'stopped';
        $updateColor = match ($resource['update_state']) {
            'red' => '#dc2626',
            'yellow' => '#f59e0b',
            'green' => '#16a34a',
            default => '#e2e8f0',
        };
        $updateCell = '<span title="Update" style="display:block;width:1.1rem;height:1.1rem;border-radius:999px;background:' . e($updateColor) . ';border:1px solid rgba(15,23,42,0.12);margin:0 auto;"></span>';
        echo '<tr>'
            . '<td><a href="' . e($href) . '">' . e($resource['name']) . '</a></td>'
            . '<td>' . $ipHtml . '</td>'
            . '<td><span class="status-pill ' . e($statusClass) . '">' . e($resource['status']) . '</span></td>'
            . '<td><span class="tag">' . e($typeLabel) . '</span></td>'
            . '<td style="text-align:center;vertical-align:middle;">' . $updateCell . '</td>'
            . '</tr>';
    }

    echo '</tbody></table></div></details>';
}

if ($resources === []) {
    echo '<div class="section-card" style="margin-top:1rem;"><p>Keine VMs, LXC-Container oder Docker-Objekte gefunden.</p></div>';
}

render_footer();
