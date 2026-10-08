<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

require_admin();

$cfg = load_json('api');
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $postedVerification = (string) ($_POST['verify_certificate'] ?? '');
    $new = [
        'host' => trim((string) ($_POST['host'] ?? '')),
        'port' => ((int) ($_POST['port'] ?? 8006) >= 1 && (int) ($_POST['port'] ?? 8006) <= 65535)
            ? (int) $_POST['port'] : 8006,
        'tls' => (string) ($_POST['tls'] ?? '1') === '1',
        'verify_certificate' => $postedVerification === '0'
            ? false
            : ($postedVerification === '1' ? true : api_certificate_verification_enabled($cfg)),
        'token_id' => trim((string) ($_POST['token_id'] ?? '')),
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
    . '<label>TLS '
    . '<select name="tls">'
    . '<option value="1"' . (api_tls_enabled($cfg) ? ' selected' : '') . '>ja</option>'
    . '<option value="0"' . (!api_tls_enabled($cfg) ? ' selected' : '') . '>nein</option>'
    . '</select></label>'
    . '<label>Zertifikat prüfen '
    . '<select name="verify_certificate">'
    . '<option value="1"' . (api_certificate_verification_enabled($cfg) ? ' selected' : '') . '>ja</option>'
    . '<option value="0"' . (!api_certificate_verification_enabled($cfg) ? ' selected' : '') . '>nein</option>'
    . '</select></label>'
    . '<label>Token-ID <input name="token_id" value="' . e((string) ($cfg['token_id'] ?? '')) . '"></label>'
    . '<label>Token-Secret <input type="password" name="token_secret" autocomplete="off" placeholder="'
    . (!empty($cfg['token_secret']) ? 'unverändert lassen' : '') . '"></label>'
    . '<button>Speichern</button></form>';
render_footer();
