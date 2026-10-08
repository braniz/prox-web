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

$hostInfoText = '';
$hostInfoLoadMessage = '';
$aptUpdateText = '';
$aptUpdateLoadMessage = '';
$hostCommentMessage = '';
$hostCommentDraft = '';
$commentStoreDir = __DIR__ . '/../data/host_comments';
$hostnameFromResult = '';

$hostInfoCacheFile = __DIR__ . '/../data/host_info_' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $id . '_' . ($node ?: 'resource')) . '.txt';
$cachedHostInfo = file_exists($hostInfoCacheFile) ? trim((string) file_get_contents($hostInfoCacheFile)) : '';
$extractGuestFileData = static function ($payload): string {
    if (is_string($payload)) {
        return trim((string) $payload);
    }
    if (!is_array($payload)) {
        return '';
    }
    foreach (['host_info', 'content', 'data', 'result', 'value', 'text'] as $key) {
        if (!array_key_exists($key, $payload)) {
            continue;
        }
        $value = $payload[$key];
        if (is_array($value)) {
            $nested = $extractGuestFileData($value);
            if ($nested !== '') {
                return $nested;
            }
            continue;
        }
        $text = trim((string) $value);
        if ($text !== '') {
            return $text;
        }
    }

    foreach ($payload as $value) {
        if (is_scalar($value)) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }
        if (is_array($value)) {
            $nested = $extractGuestFileData($value);
            if ($nested !== '') {
                return $nested;
            }
        }
    }

    return '';
};

$hostInfoRead = fetch_proxmox_data($config, ['node' => $node, 'vmid' => $id, 'type' => $type, 'read_file' => '/srv/info/host.info']);
if ($hostInfoRead['success']) {
    $freshHostInfo = $extractGuestFileData($hostInfoRead['data'] ?? '');
    if ($freshHostInfo !== '') {
        $hostInfoText = $freshHostInfo;
        if ($freshHostInfo !== $cachedHostInfo) {
            @file_put_contents($hostInfoCacheFile, $freshHostInfo . PHP_EOL, LOCK_EX);
        }
    } elseif ($cachedHostInfo !== '') {
        $hostInfoText = $cachedHostInfo;
    }
} elseif ($cachedHostInfo !== '') {
    $hostInfoText = $cachedHostInfo;
}
if ($hostInfoText === '') {
    $hostInfoLoadMessage = 'Datei /srv/info/host.info nicht gefunden oder leer.';
}

$aptUpdateCacheFile = __DIR__ . '/../data/apt_update_' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $id . '_' . ($node ?: 'resource')) . '.txt';
$cachedAptUpdate = file_exists($aptUpdateCacheFile) ? trim((string) file_get_contents($aptUpdateCacheFile)) : '';

$aptUpdateRead = fetch_proxmox_data($config, ['node' => $node, 'vmid' => $id, 'type' => $type, 'read_file' => '/srv/info/apt_update_info']);
if ($aptUpdateRead['success']) {
    $freshAptUpdate = $extractGuestFileData($aptUpdateRead['data'] ?? '');
    if ($freshAptUpdate !== '') {
        $aptUpdateText = $freshAptUpdate;
        if ($freshAptUpdate !== $cachedAptUpdate) {
            @file_put_contents($aptUpdateCacheFile, $freshAptUpdate . PHP_EOL, LOCK_EX);
        }
    } elseif ($cachedAptUpdate !== '') {
        $aptUpdateText = $cachedAptUpdate;
    }
} elseif ($cachedAptUpdate !== '') {
    $aptUpdateText = $cachedAptUpdate;
}
if ($aptUpdateText === '') {
    $aptUpdateLoadMessage = 'Datei /srv/info/apt_update_info nicht gefunden oder leer.';
}
if ($result['success'] && is_array($result['data']['proxmox'] ?? null)) {
    $hostnameFromResult = trim((string) (($result['data']['proxmox']['hostname'] ?? $result['data']['proxmox']['name'] ?? $result['data']['proxmox']['host-name'] ?? '')));
}
$commentHostName = $hostnameFromResult !== '' ? $hostnameFromResult : $node;
$commentKey = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $id . '_' . $commentHostName);
if ($commentKey === '') {
    $commentKey = 'resource';
}
$commentStoreDir = __DIR__ . '/../data/host_comments';
$commentStoreFile = $commentStoreDir . '/' . $commentKey . '.txt';
$commentHistoryText = '';
if (!is_dir($commentStoreDir)) {
    @mkdir($commentStoreDir, 0775, true);
}
if (file_exists($commentStoreFile)) {
    $commentHistoryText = trim((string) file_get_contents($commentStoreFile));
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string) ($_GET['comment'] ?? '') === 'saved') {
    $hostCommentMessage = 'Kommentar gespeichert.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['host_comment_action'])) {
    csrf_check();
    $hostCommentAction = (string) ($_POST['host_comment_action'] ?? '');
    $hostCommentDraft = trim((string) ($_POST['host_comment_text'] ?? ''));
    if ($hostCommentAction === 'new') {
        $currentUser = trim((string) (($user['username'] ?? 'system')));
        $hostCommentDraft = date('d.m.Y H:i:s') . ' - ' . ($currentUser !== '' ? $currentUser : 'system') . ': ';
    }
    if ($hostCommentAction === 'save' && $hostCommentDraft !== '') {
        $existingEntries = [];
        if (file_exists($commentStoreFile)) {
            $existingEntries = @file($commentStoreFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($existingEntries)) {
                $existingEntries = [];
            }
        }
        $writeOk = true;
        if (($existingEntries[0] ?? null) !== $hostCommentDraft) {
            array_unshift($existingEntries, $hostCommentDraft);
            $writeOk = @file_put_contents($commentStoreFile, implode(PHP_EOL, $existingEntries) . PHP_EOL, LOCK_EX) !== false;
        }
        if ($writeOk) {
            redirect('/resource.php?id=' . rawurlencode($id) . '&type=' . rawurlencode($type) . '&node=' . rawurlencode($node) . '&comment=saved');
        }
        $hostCommentMessage = 'Kommentar konnte nicht gespeichert werden.';
    }
}

$aptUpdateLabel = $aptUpdateText !== '' ? $aptUpdateText : $aptUpdateLoadMessage;
$aptUpdateStatusStyle = 'width:100%; box-sizing:border-box; resize:vertical; border:1px solid #dfe7f1; border-radius:8px; padding:.5rem; background:#f8fafc; color:#0f172a;';
$aptUpdateIsRed = false;
if ($aptUpdateText !== '' && $aptUpdateText !== $aptUpdateLoadMessage) {
    $aptUpdateDates = [];
    preg_match_all('/\b(\d{4}-\d{2}-\d{2}|\d{2}\.\d{2}\.\d{4}|\d{4}\/\d{2}\/\d{2}|\d{2}\/\d{2}\/\d{4})\b/', $aptUpdateText, $aptUpdateDateMatches);
    foreach (($aptUpdateDateMatches[1] ?? []) as $candidateDate) {
        $parsedDate = null;
        foreach (['Y-m-d', 'd.m.Y', 'Y/m/d', 'd/m/Y'] as $format) {
            $date = DateTime::createFromFormat('!' . $format, $candidateDate);
            if ($date instanceof DateTime) {
                $parsedDate = $date->getTimestamp();
                break;
            }
        }
        if ($parsedDate !== null) {
            $aptUpdateDates[] = $parsedDate;
        }
    }
    if ($aptUpdateDates !== []) {
        $latestAptUpdateTs = max($aptUpdateDates);
        $aptUpdateAgeDays = (int) floor((time() - $latestAptUpdateTs) / 86400);
        if ($aptUpdateAgeDays >= 40) {
            $aptUpdateIsRed = true;
            $aptUpdateStatusStyle = 'width:100%; box-sizing:border-box; resize:vertical; border:1px solid #fca5a5; border-radius:8px; padding:.5rem; background:#fee2e2; color:#7f1d1d;';
        } elseif ($aptUpdateAgeDays >= 30) {
            $aptUpdateStatusStyle = 'width:100%; box-sizing:border-box; resize:vertical; border:1px solid #fcd34d; border-radius:8px; padding:.5rem; background:#fef3c7; color:#78350f;';
        } else {
            $aptUpdateStatusStyle = 'width:100%; box-sizing:border-box; resize:vertical; border:1px solid #86efac; border-radius:8px; padding:.5rem; background:#dcfce7; color:#14532d;';
        }
    }
}
if ($aptUpdateIsRed) {
    $hostNameLabel = $hostnameFromResult !== '' ? $hostnameFromResult : ($hostname !== '' ? $hostname : $node);
    ensure_red_update_kanban_task((string) $id, (string) $node, $hostNameLabel, $aptUpdateText);
}

$pageTitle = $id;
if ($result['success'] && is_array($result['data']['proxmox'] ?? null)) {
    $hostname = trim((string) (($result['data']['proxmox']['hostname'] ?? $result['data']['proxmox']['name'] ?? $result['data']['proxmox']['host-name'] ?? '')));
    if ($hostname !== '') {
        $pageTitle = $hostname;
    }
}

render_header($pageTitle, 'resources');
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
$qmInfo = is_array($detail['qm_info'] ?? null) ? $detail['qm_info'] : [];
$guestAgent = is_array($detail['guest_agent'] ?? null) ? $detail['guest_agent'] : [];
$guestCmds = is_array($detail['guest_cmds'] ?? null) ? $detail['guest_cmds'] : [];

$extractIpValues = static function ($value) {
    $result = ['ipv4' => [], 'ipv6' => []];
    $seen = [];
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

    $walk = static function ($node) use (&$walk, &$seen, &$result, $extractTextTokens): void {
        if (is_array($node)) {
            foreach ($node as $key => $item) {
                $keyLower = strtolower((string) $key);
                if (str_contains($keyLower, 'ip') || in_array($keyLower, ['net0', 'net1', 'network', 'net'], true)) {
                    if (is_array($item)) {
                        $walk($item);
                    } else {
                        $text = trim((string) $item);
                        if ($text !== '') {
                            foreach ($extractTextTokens($text) as $compact) {
                                if (preg_match('/^127\./', $compact) || strtolower($compact) === '::1' || preg_match('/^169\.254\./', $compact) || preg_match('/^fe80:/i', $compact)) {
                                    continue;
                                }
                                if (!isset($seen[$compact])) {
                                    $seen[$compact] = true;
                                    if (str_contains($compact, ':')) {
                                        $result['ipv6'][] = $compact;
                                    } else {
                                        $result['ipv4'][] = $compact;
                                    }
                                }
                            }
                        }
                    }
                }
                if (is_array($item)) {
                    $walk($item);
                }
            }
            return;
        }

        if (is_string($node)) {
            foreach ($extractTextTokens($node) as $compact) {
                if (preg_match('/^127\./', $compact) || strtolower($compact) === '::1' || preg_match('/^169\.254\./', $compact) || preg_match('/^fe80:/i', $compact)) {
                    continue;
                }
                if (!isset($seen[$compact])) {
                    $seen[$compact] = true;
                    if (str_contains($compact, ':')) {
                        $result['ipv6'][] = $compact;
                    } else {
                        $result['ipv4'][] = $compact;
                    }
                }
            }
        }
    };
    $walk($value);
    return $result;
};

$ips = $extractIpValues(['proxmox' => $proxmox, 'guest_agent' => $guestAgent]);
if ((empty($ips['ipv4']) && empty($ips['ipv6'])) && is_array($detail['guest_cmds'] ?? null) && isset($detail['guest_cmds']['network'])) {
    $networkPayload = json_decode((string) $detail['guest_cmds']['network'], true);
    if (is_array($networkPayload)) {
        $ips = $extractIpValues(['proxmox' => $proxmox, 'guest_agent' => $networkPayload]);
    }
}
$hostname = trim((string) (($proxmox['hostname'] ?? $proxmox['name'] ?? $proxmox['host-name'] ?? '')));
if ($hostname === '') {
    $hostname = trim((string) (($detail['node'] ?? '') . '-' . $id));
}

$proxmoxSummary = [];
$preferredOrder = ['agent', 'boot', 'cores', 'cpu', 'ide2', 'memory', 'meta', 'name', 'net0', 'numa', 'ostype', 'scsi0', 'scsihw', 'smbios1', 'sockets', 'vmgenid', 'digest'];
$preferredIndex = [];
foreach ($preferredOrder as $index => $key) {
    $preferredIndex[$key] = $index;
}

$collectEntries = static function (array $source) use (&$collectEntries, &$preferredIndex): array {
    $volatileKeys = ['name', 'vmid', 'status', 'type'];
    $entries = [];
    foreach ($source as $key => $value) {
        if (is_array($value)) {
            continue;
        }
        $normalizedKey = strtolower((string) $key);
        if (in_array($normalizedKey, $volatileKeys, true)) {
            continue;
        }
        $entries[] = ['key' => (string) $key, 'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value];
    }
    usort($entries, static function (array $a, array $b) use ($preferredIndex): int {
        $aKey = strtolower((string) $a['key']);
        $bKey = strtolower((string) $b['key']);
        $aPos = $preferredIndex[$aKey] ?? PHP_INT_MAX;
        $bPos = $preferredIndex[$bKey] ?? PHP_INT_MAX;
        if ($aPos !== $bPos) {
            return $aPos <=> $bPos;
        }
        return strcmp($aKey, $bKey);
    });
    return $entries;
};

$proxmoxSummary = $collectEntries($proxmox);
if ($proxmoxSummary === []) {
    $proxmoxSummary = $collectEntries($qmInfo);
}
$proxmoxSummary = array_values(array_filter(
    $proxmoxSummary,
    static fn (array $row): bool => !in_array(strtolower((string) ($row['key'] ?? '')), ['name', 'vmid', 'status', 'type'], true)
));

$summaryVmId = (string) (($qmInfo['vmid'] ?? $id) ?: $id);
$summaryType = strtolower(trim((string) (($qmInfo['type'] ?? $type) ?: $type)));
$summaryName = trim((string) (($qmInfo['name'] ?? $hostname) ?: $hostname));
$summaryStatus = 'unbekannt';
if (isset($qmInfo['status']) && is_scalar($qmInfo['status'])) {
    $summaryStatus = (string) $qmInfo['status'];
} else {
    $clusterStatus = fetch_proxmox_data($config);
    if ($clusterStatus['success'] && is_array($clusterStatus['data']['resources'] ?? null)) {
        foreach ($clusterStatus['data']['resources'] as $resourceEntry) {
            if (!is_array($resourceEntry)) {
                continue;
            }
            if ((string) ($resourceEntry['node'] ?? '') !== $node) {
                continue;
            }
            if ((string) ($resourceEntry['vmid'] ?? '') !== $summaryVmId) {
                continue;
            }
            if (strtolower((string) ($resourceEntry['type'] ?? '')) !== $summaryType) {
                continue;
            }
            $summaryStatus = (string) ($resourceEntry['status'] ?? 'unbekannt');
            break;
        }
    }
}
if ($summaryStatus === 'unbekannt' && isset($proxmox['status']) && is_scalar($proxmox['status'])) {
    $summaryStatus = (string) $proxmox['status'];
}
$summaryTypeLabel = $summaryType === 'lxc' ? 'LXC' : ($summaryType === 'qemu' ? 'VM' : $typeLabel);

$headerHostname = $hostname !== '' ? $hostname : $node;
$statusValue = strtolower(trim((string) $summaryStatus));
if (in_array($statusValue, ['running', 'online', 'ok', 'started', 'active'], true)) {
    $statusStyle = 'padding:.55rem .7rem; background:#dcfce7; border:1px solid #86efac; border-radius:10px; color:#166534;';
} elseif (in_array($statusValue, ['stopped', 'offline', 'shutdown', 'aus', 'halted', 'paused'], true)) {
    $statusStyle = 'padding:.55rem .7rem; background:#fee2e2; border:1px solid #fca5a5; border-radius:10px; color:#991b1b;';
} else {
    $statusStyle = 'padding:.55rem .7rem; background:#f8fafc; border:1px solid #dfebf7; border-radius:10px; color:#0f172a;';
}

echo '<div class="section-card compact-box" style="margin-top:1rem;">'
    . '<div class="info-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:.75rem;">'
    . '<div style="padding:.55rem .7rem; background:#f8fafc; border:1px solid #dfebf7; border-radius:10px;"><div style="font-size:.66rem; color:#64748b; letter-spacing:.04em; text-transform:uppercase; margin-bottom:.2rem;">Knoten</div><strong>' . e($node) . '</strong></div>'
    . '<div style="padding:.55rem .7rem; background:#f8fafc; border:1px solid #dfebf7; border-radius:10px;"><div style="font-size:.66rem; color:#64748b; letter-spacing:.04em; text-transform:uppercase; margin-bottom:.2rem;">Typ</div><strong>' . e($summaryTypeLabel) . '</strong></div>'
    . '<div style="padding:.55rem .7rem; background:#f8fafc; border:1px solid #dfebf7; border-radius:10px;"><div style="font-size:.66rem; color:#64748b; letter-spacing:.04em; text-transform:uppercase; margin-bottom:.2rem;">VMID</div><strong>' . e($id . ' - ' . $headerHostname) . '</strong></div>'
    . '<div style="' . $statusStyle . '"><div style="font-size:.66rem; letter-spacing:.04em; text-transform:uppercase; margin-bottom:.2rem;">Status</div><strong>' . e($summaryStatus) . '</strong></div>'
    . '</div>'
    . '</div>';

if ($type === 'qemu' || $type === 'lxc') {
    echo '<div style="display:grid; grid-template-columns:minmax(0, 2fr) minmax(260px, 1fr); gap:1rem; align-items:start; margin-top:1rem;">'
        . '<div>';
}

$normalizeGuestInfoLabel = static function (string $key): string {
    $map = [
        'pretty-name' => 'Name',
        'version' => 'Version',
        'kernel-release' => 'Kernel',
        'kernel-version' => 'Kernel-Version',
        'machine' => 'Architektur',
        'id' => 'ID',
        'time' => 'Zeit',
    ];
    $label = strtolower($key);
    return $map[$label] ?? ucfirst(str_replace(['-', '_'], ' ', $label));
};

$formatGuestValue = static function ($value): string {
    if (is_bool($value)) {
        return $value ? 'Ja' : 'Nein';
    }
    if (is_numeric($value) && is_string($value)) {
        $number = (float) $value;
        if (abs($number) > 1e12) {
            $seconds = $number / 1e9;
            return gmdate('d.m.Y H:i:s', (int) floor($seconds)) . ' UTC';
        }
    }
    if (is_numeric($value)) {
        $number = (float) $value;
        if (abs($number) > 1e12) {
            $seconds = $number / 1e9;
            return gmdate('d.m.Y H:i:s', (int) floor($seconds)) . ' UTC';
        }
        return (string) $value;
    }
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }
    return trim((string) $value);
};

$renderGuestInfoTable = static function (string $label, $value, ?string $key = null) use ($normalizeGuestInfoLabel, $formatGuestValue): void {
    $hiddenJsonKeys = ['name', 'vmid', 'status', 'type'];
    $formatInlineValue = static function (string $metricKey, $metricValue): string {
        $lowerKey = strtolower($metricKey);
        if ($metricValue === null) {
            return '—';
        }
        if (is_array($metricValue)) {
            $json = json_encode($metricValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json !== false ? $json : '[]';
        }
        $text = trim((string) $metricValue);
        if ($text === '') {
            return '—';
        }
        if (is_bool($metricValue)) {
            return $metricValue ? 'Ja' : 'Nein';
        }
        if (is_numeric($text)) {
            $number = (float) $text;
            if (in_array($lowerKey, ['mem', 'maxmem', 'memory', 'maxmemory', 'disk', 'maxdisk', 'totaldisk', 'useddisk', 'maxswap', 'swap', 'netin', 'netout', 'diskread', 'diskwrite'], true)) {
                $mb = $number / 1024 / 1024;
                if ($mb < 1024) {
                    return number_format($mb, 0, ',', '.') . ' MB';
                }
                $gb = $mb / 1024;
                return number_format($gb, 1, ',', '.') . ' GB';
            }
            if (in_array($lowerKey, ['cpu', 'pressurecpusome', 'pressurecpufull', 'pressureiosome', 'pressureiofull', 'pressurememorysome', 'pressurememoryfull', 'usedcpu', 'cpu_usage'], true)) {
                return number_format($number, 2, ',', '.');
            }
            if (in_array($lowerKey, ['cpus', 'pid'], true)) {
                return number_format($number, 0, ',', '.');
            }
            if ($lowerKey === 'uptime') {
                $seconds = (int) round($number);
                $hours = intdiv($seconds, 3600);
                $minutes = intdiv($seconds % 3600, 60);
                $secs = $seconds % 60;
                return ($hours > 0 ? $hours . 'h ' : '') . ($minutes > 0 ? $minutes . 'm ' : '') . ($secs > 0 ? $secs . 's' : '0s');
            }
            if ($lowerKey === 'ha') {
                return json_encode(['managed' => (int) $number], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            return $text;
        }
        return $text;
    };
    $stripRootHiddenKeys = static function ($node) use ($hiddenJsonKeys): mixed {
        if (!is_array($node)) {
            return $node;
        }
        $clean = [];
        foreach ($node as $k => $v) {
            $normalized = strtolower((string) $k);
            if (in_array($normalized, $hiddenJsonKeys, true)) {
                continue;
            }
            $clean[$k] = $v;
        }
        return $clean;
    };
    $text = trim((string) $value);
    $decoded = json_decode($text, true);
    $decoded = $stripRootHiddenKeys($decoded);
    $rows = [];

    if ($key === 'network') {
        $items = $decoded;
        if (is_array($decoded) && isset($decoded['result']) && is_array($decoded['result'])) {
            $items = $decoded['result'];
        }
        if (is_array($items)) {
            foreach ($items as $interface) {
                if (is_array($interface)) {
                    $interfaceName = trim((string) ($interface['name'] ?? $interface['interface'] ?? $interface['ifname'] ?? ''));
                    $mac = trim((string) ($interface['hardware-address'] ?? $interface['mac-address'] ?? $interface['mac'] ?? ''));
                    $ipv4 = [];
                    $ipv6 = [];
                    foreach (['ip-addresses', 'ip-address', 'addresses'] as $field) {
                        if (!isset($interface[$field])) {
                            continue;
                        }
                        $addressList = $interface[$field];
                        if (!is_array($addressList)) {
                            $addressList = [$addressList];
                        }
                        foreach ($addressList as $entry) {
                            if (is_array($entry)) {
                                $ip = trim((string) ($entry['ip-address'] ?? $entry['address'] ?? ''));
                                if ($ip === '' && isset($entry['ip-address-type']) && is_string($entry['ip-address-type'])) {
                                    $ip = trim((string) ($entry['ip-address-type'] ?? ''));
                                }
                            } else {
                                $ip = trim((string) $entry);
                            }
                            if ($ip === '') {
                                continue;
                            }
                            $cleanIp = preg_replace('/\/\d+$/', '', $ip);
                            $cleanIp = trim((string) $cleanIp);
                            if ($cleanIp === '' || !filter_var($cleanIp, FILTER_VALIDATE_IP)) {
                                continue;
                            }
                            if (str_contains($cleanIp, ':')) {
                                $ipv6[] = $cleanIp;
                            } else {
                                $ipv4[] = $cleanIp;
                            }
                        }
                    }
                    if ($interfaceName === '' && $mac === '' && $ipv4 === [] && $ipv6 === []) {
                        continue;
                    }
                    if (strtolower($interfaceName) === 'lo' || strtolower($interfaceName) === 'localhost' || str_contains(strtolower($interfaceName), 'loopback')) {
                        continue;
                    }
                    $rows[] = [
                        'interface' => $interfaceName === '' ? '—' : $interfaceName,
                        'mac' => $mac === '' ? '—' : $mac,
                        'ipv4' => array_values(array_unique($ipv4)),
                        'ipv6' => array_values(array_unique($ipv6)),
                    ];
                    continue;
                }
                if (is_string($interface)) {
                    $cleanIp = preg_replace('/\/\d+$/', '', $interface);
                    $cleanIp = trim((string) $cleanIp);
                    if ($cleanIp !== '' && filter_var($cleanIp, FILTER_VALIDATE_IP)) {
                        $rows[] = [
                            'interface' => '—',
                            'mac' => '—',
                            'ipv4' => str_contains($cleanIp, ':') ? [] : [$cleanIp],
                            'ipv6' => str_contains($cleanIp, ':') ? [$cleanIp] : [],
                        ];
                    }
                }
            }
        }
    } elseif (is_array($decoded)) {
        if (array_is_list($decoded)) {
            foreach ($decoded as $index => $entry) {
                if (is_array($entry)) {
                    foreach ($entry as $keyName => $item) {
                        $normalizedKey = strtolower((string) $keyName);
                        if (in_array($normalizedKey, $hiddenJsonKeys, true)) {
                            continue;
                        }
                        $rows[] = ['label' => $normalizeGuestInfoLabel((string) $keyName), 'value' => $formatInlineValue((string) $keyName, $item)];
                    }
                }
            }
        } else {
            foreach ($decoded as $keyName => $item) {
                $normalizedKey = strtolower((string) $keyName);
                if (in_array($normalizedKey, $hiddenJsonKeys, true)) {
                    continue;
                }
                $rows[] = ['label' => $normalizeGuestInfoLabel((string) $keyName), 'value' => $formatInlineValue((string) $keyName, $item)];
            }
        }
    } elseif ($text !== '') {
        $rows[] = ['label' => 'Wert', 'value' => $text];
    }

    if ($key === 'status_current') {
        $allowedStatusLabels = [
            'disk' => 'Disk',
            'maxdisk' => 'MaxDisk',
            'uptime' => 'Uptime',
        ];
        $filteredRows = [];
        foreach ($rows as $row) {
            $labelKey = strtolower((string) ($row['label'] ?? ''));
            if (!isset($allowedStatusLabels[$labelKey])) {
                continue;
            }
            $row['label'] = $allowedStatusLabels[$labelKey];
            $filteredRows[] = $row;
        }
        $rows = $filteredRows;
    }

    $blockBorderStyle = ($key === 'status_current')
        ? 'border:1px solid #dfe7f1; border-left:4px solid #3b82f6; border-radius:12px;'
        : 'border:1px solid #dfe7f1; border-radius:12px;';

    echo '<div style="margin:0 0 .7rem; padding:.6rem .7rem; ' . $blockBorderStyle . ' background:linear-gradient(180deg, #f8fbff 0%, #f8fafc 100%); box-shadow:0 1px 2px rgba(15, 23, 42, 0.04);">'
        . '<div style="font-weight:700; font-size:.65rem; line-height:1.2; margin:0 0 .35rem; color:#475569; letter-spacing:.04em; text-transform:uppercase;">' . e($label) . '</div>';

    if ($key === 'network') {
        if ($rows === []) {
            echo '<div class="muted" style="line-height:1.3; color:#64748b;">Keine Ausgabe</div>';
        } else {
            echo '<table class="compact" style="margin:0; border-collapse:collapse; width:100%; font-size:.76rem; table-layout:fixed; word-break:break-word;"><thead><tr><th style="padding:.35rem .35rem; background:#eef6ff; text-align:left; border-bottom:1px solid #dfe7f1;">Interface</th><th style="padding:.35rem .35rem; background:#eef6ff; text-align:left; border-bottom:1px solid #dfe7f1;">MAC</th><th style="padding:.35rem .35rem; background:#eef6ff; text-align:left; border-bottom:1px solid #dfe7f1;">IP-Adressen</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $ipv4Html = $row['ipv4'] === [] ? '—' : implode('<br>', array_map(static fn (string $ip): string => e($ip), $row['ipv4']));
                $ipv6Html = $row['ipv6'] === [] ? '—' : implode('<br>', array_map(static fn (string $ip): string => e($ip), $row['ipv6']));
                $ipHtml = ($row['ipv4'] === [] && $row['ipv6'] === [])
                    ? '—'
                    : '<div style="display:block; background:#dbeafe; color:#0f172a; padding:.15rem .2rem; border-radius:6px; border:1px solid #93c5fd; margin-bottom:.15rem;">' . $ipv4Html . '</div><div style="display:block; background:#ede9fe; color:#1f2937; padding:.15rem .2rem; border-radius:6px; border:1px solid #c4b5fd;">' . $ipv6Html . '</div>';
                echo '<tr style="line-height:1.2;"><td style="padding:.22rem .25rem; vertical-align:top; border-bottom:1px solid #edf2f7;">' . e((string) $row['interface']) . '</td><td style="padding:.22rem .25rem; vertical-align:top; border-bottom:1px solid #edf2f7;">' . e((string) $row['mac']) . '</td><td style="padding:.22rem .25rem; vertical-align:top; border-bottom:1px solid #edf2f7;">' . $ipHtml . '</td></tr>';
            }
            echo '</tbody></table>';
        }

    } elseif ($key === 'fsinfo') {
        $fsRows = [];
        if (is_array($decoded) && isset($decoded['result']) && is_array($decoded['result'])) {
            $fsRows = $decoded['result'];
        } elseif (is_array($decoded)) {
            $fsRows = $decoded;
        }

        if ($fsRows === []) {
            echo '<div class="muted" style="line-height:1.3; color:#64748b;">Keine Ausgabe</div>';
        } else {
            echo '<table class="compact" style="margin:0; border-collapse:collapse; width:100%; font-size:.76rem; table-layout:fixed; word-break:break-word;"><thead><tr><th style="padding:.35rem .35rem; background:#eef6ff; text-align:left; border-bottom:1px solid #dfe7f1;">Mountpoint</th><th style="padding:.35rem .35rem; background:#eef6ff; text-align:left; border-bottom:1px solid #dfe7f1;">Typ</th><th style="padding:.35rem .35rem; background:#eef6ff; text-align:left; border-bottom:1px solid #dfe7f1;">Belegt</th><th style="padding:.35rem .35rem; background:#eef6ff; text-align:left; border-bottom:1px solid #dfe7f1;">Gesamt</th><th style="padding:.35rem .35rem; background:#eef6ff; text-align:left; border-bottom:1px solid #dfe7f1;">Auslastung</th></tr></thead><tbody>';
            foreach ($fsRows as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $mount = trim((string) ($entry['mountpoint'] ?? $entry['mp'] ?? $entry['path'] ?? ''));
                $type = trim((string) ($entry['type'] ?? $entry['fstype'] ?? $entry['filesystem'] ?? ''));
                $used = trim((string) ($entry['used'] ?? $entry['used-bytes'] ?? $entry['used-space'] ?? ''));
                $total = trim((string) ($entry['total'] ?? $entry['total-bytes'] ?? $entry['total-space'] ?? ''));
                $usage = trim((string) ($entry['usage'] ?? $entry['used-percent'] ?? $entry['percent-used'] ?? ''));
                if ($mount === '' && $type === '' && $used === '' && $total === '' && $usage === '') {
                    continue;
                }
                $formatBytes = static function (?string $value): string {
                    if ($value === null || trim($value) === '') {
                        return '—';
                    }
                    $numeric = (float) trim($value);
                    if ($numeric <= 0) {
                        return '0 B';
                    }
                    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                    $unitIndex = 0;
                    while ($numeric >= 1024 && $unitIndex < count($units) - 1) {
                        $numeric /= 1024;
                        $unitIndex++;
                    }
                    if ($numeric >= 100 || $unitIndex === 0) {
                        return number_format($numeric, 0, ',', '.') . ' ' . $units[$unitIndex];
                    }
                    return number_format($numeric, 1, ',', '.') . ' ' . $units[$unitIndex];
                };

                if ($usage === '') {
                    $usage = ($total !== '' && $used !== '' && (float) $total > 0) ? round(((float) $used / (float) $total) * 100, 2) . '%' : '—';
                }
                echo '<tr style="line-height:1.2;">'
                    . '<td style="padding:.22rem .25rem; vertical-align:top; border-bottom:1px solid #edf2f7;">' . e($mount === '' ? '—' : $mount) . '</td>'
                    . '<td style="padding:.22rem .25rem; vertical-align:top; border-bottom:1px solid #edf2f7;">' . e($type === '' ? '—' : $type) . '</td>'
                    . '<td style="padding:.22rem .25rem; vertical-align:top; border-bottom:1px solid #edf2f7;">' . e($used === '' ? '—' : $formatBytes($used)) . '</td>'
                    . '<td style="padding:.22rem .25rem; vertical-align:top; border-bottom:1px solid #edf2f7;">' . e($total === '' ? '—' : $formatBytes($total)) . '</td>'
                    . '<td style="padding:.22rem .25rem; vertical-align:top; border-bottom:1px solid #edf2f7;">' . e($usage === '' ? '—' : $usage) . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table>';
        }
    } else {
        if ($rows === []) {
            echo '<div class="muted" style="line-height:1.3; color:#64748b;">Keine Ausgabe</div>';
        } elseif ($key === 'status_current') {
            echo '<table class="compact" style="margin:0; border-collapse:collapse; width:100%; font-size:.76rem; table-layout:fixed; word-break:break-word;"><thead><tr><th style="padding:.22rem .25rem; text-align:left; background:#eef6ff; border-bottom:1px solid #dfe7f1;">Eigenschaft</th><th style="padding:.22rem .25rem; text-align:left; background:#eef6ff; border-bottom:1px solid #dfe7f1;">Wert</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $label = $row['label'] ?? '';
                $value = $row['value'] ?? null;
                echo '<tr style="line-height:1.2;"><td style="padding:.22rem .25rem; vertical-align:top; background:inherit; border-bottom:1px solid #edf2f7; width:40%;">' . e((string) $label) . '</td><td style="padding:.22rem .25rem; vertical-align:top; background:inherit; border-bottom:1px solid #edf2f7;">' . e($formatGuestValue($value)) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            $groupMap = [
                'CPU' => ['cpu', 'cpus', 'pressurecpufull', 'pressurecpusome'],
                'Memory' => ['mem', 'maxmem', 'swap', 'maxswap', 'pressurememoryfull', 'pressurememorysome'],
                'Disk' => ['disk', 'maxdisk', 'diskread', 'diskwrite'],
                'Network' => ['netin', 'netout'],
                'Process' => ['pid'],
                'Uptime' => ['uptime'],
                'HA' => ['ha'],
            ];
            $groupedRows = [];
            foreach ($rows as $row) {
                $metricKey = strtolower((string) ($row['label'] ?? ''));
                $group = 'Other';
                foreach ($groupMap as $name => $keys) {
                    if (in_array($metricKey, $keys, true)) {
                        $group = $name;
                        break;
                    }
                }
                $groupedRows[$group][] = $row;
            }

            foreach ($groupedRows as $groupName => $groupRows) {
                echo '<div style="margin:.5rem 0 .2rem; padding:.5rem .65rem; border:1px solid #dfe7f1; border-left:4px solid #3b82f6; border-radius:12px; background:linear-gradient(180deg, #f8fbff 0%, #f8fafc 100%); box-shadow:0 1px 2px rgba(15, 23, 42, 0.04);">'
                    . '<div style="margin:0 0 .35rem; font-size:.68rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:#475569;">' . e($groupName) . '</div>'
                    . '<table class="compact" style="margin:0; border-collapse:collapse; width:100%; font-size:.76rem; table-layout:fixed; word-break:break-word;"><thead><tr><th style="padding:.22rem .25rem; text-align:left; background:#eef6ff; border-bottom:1px solid #dfe7f1;">Eigenschaft</th><th style="padding:.22rem .25rem; text-align:left; background:#eef6ff; border-bottom:1px solid #dfe7f1;">Wert</th></tr></thead><tbody>';
                foreach ($groupRows as $index => $row) {
                    $rowStyle = $index === 0 ? 'background:#f8fbff; font-weight:600;' : 'line-height:1.2;';
                    $label = $row['label'] ?? '';
                    $value = $row['value'] ?? null;
                    echo '<tr style="' . $rowStyle . '"><td style="padding:.22rem .25rem; vertical-align:top; background:inherit; border-bottom:1px solid #edf2f7; width:40%;">' . e((string) $label) . '</td><td style="padding:.22rem .25rem; vertical-align:top; background:inherit; border-bottom:1px solid #edf2f7;">' . e($formatGuestValue($value)) . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
        }
    }
    echo '</div>';
};

$runtimeBytes = static function ($value): string {
    if ($value === null) {
        return '—';
    }
    if (is_array($value)) {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '[]';
    }
    $text = trim((string) $value);
    if ($text === '') {
        return '—';
    }
    $numeric = (float) $text;
    if ($numeric <= 0) {
        return '0 MB';
    }
    $mb = $numeric / 1024 / 1024;
    if ($mb < 1024) {
        return number_format($mb, 0, ',', '.') . ' MB';
    }
    $gb = $mb / 1024;
    return number_format($gb, 1, ',', '.') . ' GB';
};

$formatReadableValue = static function (string $key, $value): string {
    $lowerKey = strtolower($key);
    if ($value === null) {
        return '—';
    }
    if (is_array($value)) {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '[]';
    }
    $text = trim((string) $value);
    if ($text === '') {
        return '—';
    }
    if (is_bool($value)) {
        return $value ? 'Ja' : 'Nein';
    }
    if (is_numeric($text)) {
        $number = (float) $text;
        if (in_array($lowerKey, ['mem', 'maxmem', 'memory', 'maxmemory', 'disk', 'maxdisk', 'totaldisk', 'useddisk', 'maxswap', 'swap', 'netin', 'netout', 'diskread', 'diskwrite'], true)) {
            return $runtimeBytes($number);
        }
        if (in_array($lowerKey, ['cpu', 'pressurecpusome', 'pressurecpufull', 'pressureiosome', 'pressureiofull', 'pressurememorysome', 'pressurememoryfull', 'usedcpu', 'cpu_usage'], true)) {
            return number_format($number, 2, ',', '.') . '';
        }
        if (in_array($lowerKey, ['cpus', 'pid'], true)) {
            return number_format($number, 0, ',', '.');
        }
        if (in_array($lowerKey, ['uptime'], true)) {
            $seconds = (int) round($number);
            $hours = intdiv($seconds, 3600);
            $minutes = intdiv($seconds % 3600, 60);
            $secs = $seconds % 60;
            return ($hours > 0 ? $hours . 'h ' : '') . ($minutes > 0 ? $minutes . 'm ' : '') . ($secs > 0 ? $secs . 's' : '0s');
        }
        if (in_array($lowerKey, ['ha'], true)) {
            return json_encode(['managed' => (int) $number], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    return $text;
};

$formatProxmoxMetric = static function (string $key, $value) use ($runtimeBytes, $formatReadableValue): string {
    $lowerKey = strtolower($key);
    if ($value === null) {
        return '—';
    }
    if (is_array($value)) {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '[]';
    }
    $text = trim((string) $value);
    if ($text === '') {
        return '—';
    }
    if (!is_numeric($text)) {
        return $text;
    }
    if (in_array($lowerKey, ['mem', 'maxmem', 'memory', 'maxmemory', 'disk', 'maxdisk', 'totaldisk', 'useddisk', 'maxswap', 'swap', 'netin', 'netout', 'diskread', 'diskwrite'], true)) {
        return $runtimeBytes((float) $text);
    }
    if (in_array($lowerKey, ['cpu', 'pressurecpusome', 'pressurecpufull', 'pressureiosome', 'pressureiofull', 'pressurememorysome', 'pressurememoryfull', 'usedcpu', 'cpu_usage'], true)) {
        $number = (float) $text;
        return number_format($number, 2, ',', '.');
    }
    if (in_array($lowerKey, ['cpus', 'pid'], true)) {
        return number_format((float) $text, 0, ',', '.');
    }
    if ($lowerKey === 'uptime') {
        $seconds = (int) round((float) $text);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        return ($hours > 0 ? $hours . 'h ' : '') . ($minutes > 0 ? $minutes . 'm ' : '') . ($secs > 0 ? $secs . 's' : '0s');
    }
    if ($lowerKey === 'ha') {
        return json_encode(['managed' => (int) $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $text;
};

$cpuVerbose = null;
foreach (['cpu', 'cpu_usage', 'usedcpu'] as $candidate) {
    if (array_key_exists($candidate, $qmInfo) && trim((string) $qmInfo[$candidate]) !== '') {
        $cpuVerbose = trim((string) $qmInfo[$candidate]);
        break;
    }
}
$memVerbose = null;
foreach (['mem', 'memory', 'usedmem', 'memory_used'] as $candidate) {
    if (array_key_exists($candidate, $qmInfo) && trim((string) $qmInfo[$candidate]) !== '') {
        $memVerbose = trim((string) $qmInfo[$candidate]);
        break;
    }
}
$maxMemVerbose = null;
foreach (['maxmem', 'maxmemory', 'memory_total', 'totalmem'] as $candidate) {
    if (array_key_exists($candidate, $qmInfo) && trim((string) $qmInfo[$candidate]) !== '') {
        $maxMemVerbose = trim((string) $qmInfo[$candidate]);
        break;
    }
}

$runtimeCards = [];
if ($cpuVerbose !== null) {
    $runtimeCards[] = ['label' => 'CPU', 'value' => rtrim(rtrim($cpuVerbose, '%'), ' ') . '%'];
}
if ($memVerbose !== null) {
    $ramValue = $runtimeBytes((float) $memVerbose);
    if ($maxMemVerbose !== null) {
        $ramValue .= ' / ' . $runtimeBytes((float) $maxMemVerbose);
    }
    $runtimeCards[] = ['label' => 'RAM', 'value' => $ramValue];
}

if ($runtimeCards !== []) {
    echo '<div class="section-card compact-box" style="margin-top:1rem;">'
        . '<h2 style="margin:0 0 .35rem;">CPU / RAM</h2>'
        . '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:.6rem; font-size:.76rem;">';
    foreach ($runtimeCards as $card) {
        echo '<div style="padding:.5rem .6rem; background:#f8fafc; border:1px solid #e5edf5; border-radius:10px;">'
            . '<div class="muted" style="font-size:.68rem; line-height:1.2; margin-bottom:.12rem;">' . e((string) $card['label']) . '</div>'
            . '<strong>' . e((string) $card['value']) . '</strong>'
            . '</div>';
    }
    echo '</div></div>';
}

$guestCommandBlocks = [
    ['key' => 'network', 'label' => 'qm guest cmd ' . $id . ' network-get-interfaces'],
    ['key' => 'fsinfo', 'label' => 'qm guest cmd ' . $id . ' get-fsinfo'],
    ['key' => 'osinfo', 'label' => 'qm guest cmd ' . $id . ' get-osinfo'],
    ['key' => 'users', 'label' => 'qm guest cmd ' . $id . ' get-users'],
    ['key' => 'time', 'label' => 'qm guest cmd ' . $id . ' get-time'],
];

$lxcCommandBlocks = [
    ['key' => 'status_current', 'label' => 'pvesh get /nodes/' . $node . '/lxc/' . $id . '/status/current'],
    ['key' => 'config', 'label' => 'pvesh get /nodes/' . $node . '/lxc/' . $id . '/config'],
];

if ($type === 'qemu') {
    echo '<div class="section-card compact-box" style="margin-top:1rem;">'
        . '<h2 style="margin:0 0 .35rem;">Gast-Info</h2>';
    foreach ($guestCommandBlocks as $block) {
        $key = (string) $block['key'];
        $label = (string) $block['label'];
        $value = $guestCmds[$key] ?? '';
        $renderGuestInfoTable($label, $value, $key);
    }
    echo '</div>';
} elseif ($type === 'lxc') {
    echo '<div class="section-card compact-box" style="margin-top:1rem;">'
        . '<h2 style="margin:0 0 .35rem;">LXC-Info</h2>';
    foreach ($lxcCommandBlocks as $block) {
        $key = (string) $block['key'];
        $label = (string) $block['label'];
        $value = $guestCmds[$key] ?? '';
        $renderGuestInfoTable($label, $value, $key);
    }
    echo '</div>';
}

echo '</div>'
    . '<div style="display:flex; flex-direction:column; gap:1rem; width:100%; margin-top:1rem;">'
    . '<div class="section-card compact-box" style="height:100%;">'
    . '<h2 style="margin:0 0 .35rem;">Host-Info</h2>'
    . '<label style="display:block; margin-bottom:.25rem; font-weight:600;">/srv/info/host.info</label>'
    . '<textarea readonly rows="3" style="width:100%; box-sizing:border-box; resize:vertical; background:#f8fafc; border:1px solid #dfe7f1; border-radius:8px; padding:.5rem;">' . e($hostInfoText !== '' ? $hostInfoText : $hostInfoLoadMessage) . '</textarea>'
    . '</div>'
    . '<div class="section-card compact-box" style="height:100%;">'
    . '<h2 style="margin:0 0 .35rem;">Kommentar</h2>'
    . '<form method="post" style="display:flex; flex-direction:column; gap:.5rem;">'
    . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
    . '<input type="hidden" name="host_comment_action" value="save">'
    . '<textarea name="host_comment_text" rows="3" style="width:100%; box-sizing:border-box; resize:vertical; border:1px solid #dfe7f1; border-radius:8px; padding:.5rem;">' . e($hostCommentDraft) . '</textarea>'
    . '<div style="display:flex; gap:.5rem;">'
    . '<button type="submit" name="host_comment_action" value="new" style="padding:.35rem .7rem; cursor:pointer;">Neu</button>'
    . '<button type="submit" name="host_comment_action" value="save" style="padding:.35rem .7rem; cursor:pointer;">Speichern</button>'
    . '</div>'
    . ($hostCommentMessage !== '' ? '<div class="muted" style="font-size:.72rem;">' . e($hostCommentMessage) . '</div>' : '')
    . '</form>'
    . '</div>'
    . '<div class="section-card compact-box" style="height:100%;">'
    . '<h2 style="margin:0 0 .35rem;">Kommentar-Datei</h2>'
    . '<textarea readonly rows="8" style="width:100%; box-sizing:border-box; resize:vertical; background:#f8fafc; border:1px solid #dfe7f1; border-radius:8px; padding:.5rem;">' . e($commentHistoryText) . '</textarea>'
    . '</div>'
    . '<div class="section-card compact-box" style="height:100%;">'
    . '<h2 style="margin:0 0 .35rem;">Apt update</h2>'
    . '<textarea readonly rows="3" style="' . $aptUpdateStatusStyle . '">' . e($aptUpdateLabel) . '</textarea>'
    . '</div>'
    . '</div>';

echo '<div class="section-card compact-box" style="margin-top:1rem;">'
    . '<h2 style="margin:0 0 .35rem;">Proxmox-Info</h2>';
if ($proxmoxSummary === []) {
    echo '<p class="muted" style="margin:0; line-height:1.3;">Keine direkten Proxmox-Details verfügbar.</p>';
} else {
    echo '<table class="compact" style="margin:0; border-collapse:collapse;"><thead><tr><th style="padding:.18rem .25rem;">Eigenschaft</th><th style="padding:.18rem .25rem;">Wert</th></tr></thead><tbody>';
    foreach ($proxmoxSummary as $row) {
        $metricKey = (string) $row['key'];
        $metricValue = $formatProxmoxMetric($metricKey, $row['value']);
        echo '<tr style="line-height:1.2;"><td style="padding:.12rem .25rem; vertical-align:top;">' . e($metricKey) . '</td><td style="padding:.12rem .25rem; vertical-align:top;">' . e($metricValue) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

render_footer();
