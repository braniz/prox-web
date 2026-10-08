<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

require_admin();

$cfg = load_json('api');
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $new = [
        'host' => trim((string) ($_POST['host'] ?? '')),
        'port' => (int) ($_POST['port'] ?? 8006),
        'token_id' => trim((string) ($_POST['token_id'] ?? '')),
        'verify_tls' => !empty($_POST['verify_tls']),
        'token_secret' => (string) ($_POST['token_secret'] ?? '') !== ''
            ? (string) $_POST['token_secret']
            : (string) ($cfg['token_secret'] ?? ''),
    ];
    save_json('api', $new);
    $cfg = $new;
    $saved = true;
}

render_header('API-Info Eingabe', 'api');
if ($saved) {
    echo '<p>Gespeichert.</p>';
}
echo '<form method="post"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
    . '<label>Host <input name="host" value="' . e((string) ($cfg['host'] ?? '')) . '"></label>'
    . '<label>Port <input type="number" name="port" value="' . e((string) ($cfg['port'] ?? 8006)) . '"></label>'
    . '<label>Token-ID (Benutzer@Realm!Name) <input name="token_id" value="' . e((string) ($cfg['token_id'] ?? '')) . '"></label>'
    . '<label><input type="checkbox" name="verify_tls" value="1"' . (!empty($cfg['verify_tls']) ? ' checked' : '')
    . '> TLS-Zertifikat prüfen</label>'
    . '<label>Token-Secret <input type="password" name="token_secret" autocomplete="off" placeholder="'
    . (!empty($cfg['token_secret']) ? 'unverändert lassen' : '') . '"></label>'
    . '<button>Speichern</button></form>';
render_footer();
