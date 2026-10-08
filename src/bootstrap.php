<?php
declare(strict_types=1);

const DATA_DIR = __DIR__ . '/../data';
const MIN_PASSWORD_LENGTH = 8;
const MAX_LOGIN_FAILURES = 5;
const LOGIN_LOCK_SECONDS = 300;

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function load_json(string $name): array
{
    $file = DATA_DIR . '/' . $name . '.json';
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function save_json(string $name, array $data): void
{
    file_put_contents(
        DATA_DIR . '/' . $name . '.json',
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function api_tls_enabled(array $cfg): bool
{
    if (!array_key_exists('tls', $cfg)) {
        return true;
    }
    $v = $cfg['tls'];
    if (is_string($v)) {
        return in_array(strtolower(trim($v)), ['1', 'true', 'ja', 'yes', 'on'], true);
    }
    return !empty($v);
}

function api_certificate_verification_enabled(array $cfg): bool
{
    if (!array_key_exists('verify_certificate', $cfg)) {
        return true;
    }
    $v = $cfg['verify_certificate'];
    if ($v === null) {
        return true;
    }
    if (is_string($v)) {
        $normalized = strtolower(trim($v));
        if (in_array($normalized, ['0', 'false', 'nein', 'no', 'off'], true)) {
            return false;
        }
        if (in_array($normalized, ['1', 'true', 'ja', 'yes', 'on'], true)) {
            return true;
        }
        return true;
    }
    return !empty($v);
}

function normalize_user(string $username, array $u): array
{
    return [
        'username' => $username,
        'firstname' => (string) ($u['firstname'] ?? ''),
        'lastname' => (string) ($u['lastname'] ?? ''),
        'email' => (string) ($u['email'] ?? ''),
        'role' => ($u['role'] ?? 'user') === 'admin' ? 'admin' : 'user',
        'password_hash' => (string) ($u['password_hash'] ?? ''),
        'must_change_password' => !empty($u['must_change_password']),
        'created' => (string) ($u['created'] ?? ''),
    ];
}

function load_users(): array
{
    $users = [];
    foreach (load_json('users') as $name => $u) {
        $users[(string) $name] = normalize_user((string) $name, is_array($u) ? $u : []);
    }
    if (!$users) {
        $users['admin'] = normalize_user('admin', [
            'role' => 'admin',
            'password_hash' => password_hash('admin', PASSWORD_DEFAULT),
            'must_change_password' => true,
            'created' => date('c'),
        ]);
        save_json('users', $users);
    }
    return $users;
}

function current_user(): ?array
{
    $name = $_SESSION['user'] ?? null;
    if (!is_string($name)) {
        return null;
    }
    $users = load_users();
    return $users[$name] ?? null;
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        unset($_SESSION['user']);
        redirect('/login.php');
    }
    if ($user['must_change_password'] && basename($_SERVER['SCRIPT_NAME']) !== 'profile.php') {
        redirect('/profile.php');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Zugriff verweigert.');
    }
    return $user;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function login_locked(): bool
{
    $a = load_json('login_attempts')[client_ip()] ?? null;
    return is_array($a) && ($a['count'] ?? 0) >= MAX_LOGIN_FAILURES
        && time() - (int) ($a['last'] ?? 0) < LOGIN_LOCK_SECONDS;
}

function login_failed(): void
{
    $all = load_json('login_attempts');
    foreach ($all as $ip => $a) {
        if (time() - (int) ($a['last'] ?? 0) >= LOGIN_LOCK_SECONDS) {
            unset($all[$ip]);
        }
    }
    $ip = client_ip();
    $all[$ip] = ['count' => (int) ($all[$ip]['count'] ?? 0) + 1, 'last' => time()];
    save_json('login_attempts', $all);
    sleep(1);
}

function login_reset(): void
{
    $all = load_json('login_attempts');
    unset($all[client_ip()]);
    save_json('login_attempts', $all);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $sent = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Ungültiges CSRF-Token.');
    }
}

function fetch_proxmox_data(array $config, ?array $guest = null): array
{
    if (!function_exists('proc_open')) {
        return ['success' => false, 'error' => 'Die Python-Komponente kann auf diesem Server nicht gestartet werden.'];
    }

    $payload = $config;
    if (is_array($guest)) {
        $payload['guest'] = $guest;
    }

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
        return ['success' => false, 'error' => 'Die Python-Komponente kann auf diesem Server nicht gestartet werden.'];
    }

    $input = json_encode($payload);
    fwrite($pipes[0], $input === false ? '{}' : $input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $result = json_decode((string) $output, true);
    if (!is_array($result)) {
        return ['success' => false, 'error' => 'Die Cluster-Informationen konnten nicht geladen werden.'];
    }

    if (!empty($result['success']) && array_key_exists('data', $result)) {
        return ['success' => true, 'data' => $result['data']];
    }

    return ['success' => false, 'error' => is_string($result['error'] ?? null)
        ? $result['error']
        : 'Die Cluster-Informationen konnten nicht geladen werden.'];
}

function render_header(string $title, string $area): void
{
    $user = current_user();
    $isAdmin = $user !== null && $user['role'] === 'admin';

    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' – prox-web</title>'
        . '<style>body{font-family:sans-serif;margin:0;padding:0;background:#f3f5f7;color:#1f2933}'
        . '.layout{display:grid;grid-template-columns:260px minmax(0,1fr);min-height:100vh}'
        . '.sidebar{padding:1.25rem 1rem;border-right:1px solid #d9e2ec;background:#fff}'
        . '.content{padding:1.5rem 2rem;max-width:900px}'
        . 'nav{margin-bottom:1.5rem;padding:0}'
        . 'nav > details{margin-bottom:.75rem}'
        . 'nav > details:last-child{margin-bottom:0}'
        . 'nav summary{cursor:pointer;font-weight:700;color:#222;padding:.35rem .5rem;border-radius:.35rem;background:#f1f5f9;list-style:none}'
        . 'nav summary::-webkit-details-marker{display:none}'
        . 'nav ul{list-style:none;padding-left:.75rem;margin:.65rem 0 0 0}'
        . 'nav li{margin:.35rem 0}'
        . 'nav a{display:block;padding:.4rem .6rem;border-radius:.3rem;text-decoration:none;color:#0b4d8c}'
        . 'nav a.active{font-weight:bold;background:#e6f0ff;color:#062a58}'
        . 'nav a:hover{text-decoration:none;background:#eef5ff}'
        . '.userbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.2rem;border-bottom:1px solid #e5e7eb;background:#fff}'
        . 'label{display:block;margin:.5rem 0}'
        . 'table{border-collapse:collapse}td,th{border:1px solid #ccc;padding:.3rem .6rem}</style>'
        . '</head><body><div class="layout">'
        . '<aside class="sidebar"><div class="userbar">';

    if ($user !== null) {
        echo '<div><strong>' . e($user['username']) . '</strong></div>'
            . '<form method="post" action="/logout.php" style="display:inline">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<button type="submit">Abmelden</button></form>';
    }
    echo '</div><nav>';

    if ($user !== null) {
        echo '<details ' . (in_array($area, ['user', 'profile', 'resources'], true) ? 'open' : '') . '>'
            . '<summary>Proxmox</summary>'
            . '<ul>'
            . '<li><a href="/"' . ($area === 'user' ? ' class="active"' : '') . '>Benutzer-Seite</a></li>'
            . '<li><a href="/resources.php"' . ($area === 'resources' ? ' class="active"' : '') . '>VMs / LXC / Docker</a></li>'
            . '<li><a href="/profile.php"' . ($area === 'profile' ? ' class="active"' : '') . '>Mein Profil</a></li>'
            . '</ul>'
            . '</details>';

        if ($isAdmin) {
            echo '<details ' . (in_array($area, ['users', 'api'], true) ? 'open' : '') . '>'
                . '<summary>Admin</summary>'
                . '<ul>'
                . '<li><a href="/admin/users.php"' . ($area === 'users' ? ' class="active"' : '') . '>Benutzerverwaltung</a></li>'
                . '<li><a href="/admin/api.php"' . ($area === 'api' ? ' class="active"' : '') . '>API-Info</a></li>'
                . '</ul>'
                . '</details>';
        }
    }

    echo '</nav></aside><main class="content"><h1>' . e($title) . '</h1>';
}

function render_footer(): void
{
    echo '</main></div></body></html>';
}
