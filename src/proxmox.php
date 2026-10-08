<?php
declare(strict_types=1);

function proxmox_find_python(): ?string
{
    foreach (explode(PATH_SEPARATOR, '/usr/bin:/usr/local/bin:/bin') as $dir) {
        $p = $dir . '/python3';
        if (is_file($p) && is_executable($p)) {
            return $p;
        }
    }
    return null;
}

function proxmox_data(): array
{
    $script = __DIR__ . '/../scripts/proxmox_api.py';
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'error' => 'proc_open ist in PHP deaktiviert.'];
    }
    if (!is_file($script)) {
        return ['ok' => false, 'error' => 'Python-Skript nicht gefunden.'];
    }
    $python = proxmox_find_python();
    if ($python === null) {
        return ['ok' => false, 'error' => 'python3 ist auf dem Server nicht installiert.'];
    }
    $proc = proc_open(
        [$python, $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        return ['ok' => false, 'error' => 'Python-Skript konnte nicht gestartet werden.'];
    }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $data = json_decode($out, true);
    if (!is_array($data) || !array_key_exists('ok', $data)) {
        return ['ok' => false, 'error' => 'Ungültige Antwort vom Python-Skript.'];
    }
    return $data;
}
