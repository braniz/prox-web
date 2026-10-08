<?php
declare(strict_types=1);

session_start();

const DATA_DIR = __DIR__ . '/../data';

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
    $nav = [
        'user' => ['/', 'Benutzer-Seite'],
        'users' => ['/admin/users.php', 'Admin: Benutzerverwaltung'],
        'api' => ['/admin/api.php', 'Admin: API-Info'],
    ];
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
    echo '</nav><h1>' . e($title) . '</h1>';
}

function render_footer(): void
{
    echo '</body></html>';
}
