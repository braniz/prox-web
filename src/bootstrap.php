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

function render_header(string $title, string $area): void
{
    $user = current_user();
    $nav = [];
    if ($user !== null) {
        $nav['user'] = ['/', 'Benutzer-Seite'];
        $nav['profile'] = ['/profile.php', 'Mein Profil'];
        if ($user['role'] === 'admin') {
            $nav['users'] = ['/admin/users.php', 'Admin: Benutzerverwaltung'];
            $nav['api'] = ['/admin/api.php', 'Admin: API-Info'];
        }
    }
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' – prox-web</title>'
        . '<style>body{font-family:sans-serif;max-width:48rem;margin:2rem auto;padding:0 1rem}'
        . 'nav a{margin-right:1rem}nav a.active{font-weight:bold}label{display:block;margin:.5rem 0}'
        . 'table{border-collapse:collapse}td,th{border:1px solid #ccc;padding:.3rem .6rem}</style>'
        . '</head><body><nav>';
    foreach ($nav as $key => [$href, $label]) {
        echo '<a href="' . $href . '"' . ($key === $area ? ' class="active"' : '') . '>' . e($label) . '</a>';
    }
    if ($user !== null) {
        echo '<span>Angemeldet: ' . e($user['username']) . ' '
            . '<form method="post" action="/logout.php" style="display:inline">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<button>Abmelden</button></form></span>';
    }
    echo '</nav><h1>' . e($title) . '</h1>';
}

function render_footer(): void
{
    echo '</body></html>';
}
