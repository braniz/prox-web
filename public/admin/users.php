<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

$users = load_json('users');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $name = trim((string) ($_POST['username'] ?? ''));
    if ($action === 'add') {
        $password = (string) ($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $name) || strlen($password) < 8) {
            $error = 'Benutzername (3-32 Zeichen: A-Z, 0-9, _ . -) und Passwort (min. 8 Zeichen) erforderlich.';
        } elseif (isset($users[$name])) {
            $error = 'Benutzer existiert bereits.';
        } else {
            $users[$name] = ['password_hash' => password_hash($password, PASSWORD_DEFAULT)];
            save_json('users', $users);
        }
    } elseif ($action === 'delete' && isset($users[$name])) {
        unset($users[$name]);
        save_json('users', $users);
    }
}

render_header('Benutzerverwaltung', 'users');
if ($error !== '') {
    echo '<p style="color:#b00">' . e($error) . '</p>';
}
echo '<table><tr><th>Benutzer</th><th></th></tr>';
foreach (array_keys($users) as $u) {
    $u = (string) $u;
    echo '<tr><td>' . e($u) . '</td><td><form method="post">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="action" value="delete">'
        . '<input type="hidden" name="username" value="' . e($u) . '">'
        . '<button>Löschen</button></form></td></tr>';
}
echo '</table><h2>Benutzer anlegen</h2><form method="post">'
    . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
    . '<input type="hidden" name="action" value="add">'
    . '<label>Benutzername <input name="username" required></label>'
    . '<label>Passwort <input type="password" name="password" required minlength="8"></label>'
    . '<button>Anlegen</button></form>';
render_footer();
