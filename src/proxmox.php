<?php
declare(strict_types=1);

function proxmox_find_python(): ?string
{
    foreach (['/usr/bin/python3', '/usr/local/bin/python3', '/bin/python3'] as $p) {
        if (is_executable($p)) {
            return $p;
        }
    }
    return null;
}

function proxmox_data(): array
{
    $script = __DIR__ . '/../scripts/proxmox_api.py';
    if (!is_file($script)) {
        return ['ok' => false, 'error' => 'Python-Skript scripts/proxmox_api.py nicht gefunden.'];
    }
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'error' => 'PHP-Funktion proc_open ist deaktiviert.'];
    }
    $python = proxmox_find_python();
    if ($python === null) {
        return ['ok' => false, 'error' => 'python3 ist auf dem Server nicht installiert.'];
    }

    $proc = @proc_open(
        [$python, $script],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        return ['ok' => false, 'error' => 'Python-Skript konnte nicht gestartet werden.'];
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $data = json_decode($out, true);
    if (!is_array($data) || !isset($data['ok'])) {
        return ['ok' => false, 'error' => 'Ungültige Antwort vom Python-Skript.'];
    }
    if (!$data['ok']) {
        return ['ok' => false, 'error' => (string) ($data['error'] ?? 'Unbekannter Fehler.')];
    }
    return $data;
}
