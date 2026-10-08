<?php
declare(strict_types=1);

/**
 * Ruft die Proxmox-API serverseitig mit dem gespeicherten API-Token ab.
 * Rückgabe: ['data' => mixed, 'error' => ?string]
 */
function proxmox_get(string $path): array
{
    $cfg = load_json('api');
    $host = trim((string) ($cfg['host'] ?? ''));
    $tokenId = trim((string) ($cfg['token_id'] ?? ''));
    $secret = (string) ($cfg['token_secret'] ?? '');
    if ($host === '' || $tokenId === '' || $secret === '') {
        return ['data' => null, 'error' => 'API-Zugangsdaten sind nicht vollständig hinterlegt (Admin: API-Info).'];
    }
    if (!function_exists('curl_init')) {
        return ['data' => null, 'error' => 'PHP-Erweiterung curl fehlt (apt install php-curl).'];
    }
    if (!preg_match('/^[A-Za-z0-9.\-]+$|^\[[0-9A-Fa-f:]+\]$/', $host)) {
        return ['data' => null, 'error' => 'Ungültiger Host in der API-Info.'];
    }
    $port = (int) ($cfg['port'] ?? 8006);
    $url = 'https://' . $host . ':' . ($port > 0 ? $port : 8006) . '/api2/json' . $path;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: PVEAPIToken=' . $tokenId . '=' . $secret],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => !empty($cfg['verify_tls']),
        CURLOPT_SSL_VERIFYHOST => !empty($cfg['verify_tls']) ? 2 : 0,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['data' => null, 'error' => 'Proxmox-API nicht erreichbar: ' . $curlError];
    }
    if ($status === 401 || $status === 403) {
        return ['data' => null, 'error' => 'Zugriff verweigert (HTTP ' . $status . '): Token-ID oder Secret ungültig bzw. Berechtigungen fehlen.'];
    }
    $json = json_decode((string) $body, true);
    if ($status !== 200 || !is_array($json) || !isset($json['data'])) {
        return ['data' => null, 'error' => 'Unerwartete Antwort der Proxmox-API (HTTP ' . $status . ').'];
    }
    return ['data' => $json['data'], 'error' => null];
}
