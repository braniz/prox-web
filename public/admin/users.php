<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

$me = require_admin();
$users = load_users();
$error = '';

function admin_count(array $users): int
{
    return count(array_filter($users, function ($u) {
        return $u['role'] === 'admin';
    }));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $name = trim((string) ($_POST['username'] ?? ''));
    if ($action === 'add') {
        $password = (string) ($_POST['password'] ?? '');
        $first = trim((string) ($_POST['firstname'] ?? ''));
        $last = trim((string) ($_POST['lastname'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $name)) {
            $error = 'Benutzername muss 3-32 Zeichen lang sein (A-Z, 0-9, _ . -).';
        } elseif ($first === '' || $last === '' || mb_strlen($first) > 100 || mb_strlen($last) > 100) {
            $error = 'Vorname und Nachname sind erforderlich (max. 100 Zeichen).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Bitte eine gültige E-Mail-Adresse angeben.';
        } elseif (strlen($password) < MIN_PASSWORD_LENGTH) {
            $error = 'Das Passwort muss mindestens ' . MIN_PASSWORD_LENGTH . ' Zeichen lang sein.';
        } elseif (isset($users[$name])) {
            $error = 'Benutzer existiert bereits.';
        } else {
            $users[$name] = normalize_user($name, [
                'firstname' => $first,
                'lastname' => $last,
                'email' => $email,
                'role' => $role,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'must_change_password' => true,
                'created' => date('c'),
            ]);
            save_json('users', $users);
        }
    } elseif ($action === 'delete' && isset($users[$name])) {
        if ($users[$name]['role'] === 'admin' && admin_count($users) <= 1) {
            $error = 'Der letzte Administrator kann nicht gelöscht werden.';
        } elseif ($name === $me['username']) {
            $error = 'Das eigene Konto kann nicht gelöscht werden.';
        } else {
            unset($users[$name]);
            save_json('users', $users);
        }
    }
}

render_header('Benutzerverwaltung', 'users');
if ($error !== '') {
    echo '<p style="color:#b00">' . e($error) . '</p>';
}
echo '<h2>Benutzer anlegen</h2><form method="post">'
    . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
    . '<input type="hidden" name="action" value="add">'
    . '<label>Benutzername <input name="username" required pattern="[A-Za-z0-9_.\-]{3,32}"></label>'
    . '<label>Vorname <input name="firstname" required maxlength="100"></label>'
    . '<label>Nachname <input name="lastname" required maxlength="100"></label>'
    . '<label>E-Mail <input type="email" name="email" required></label>'
    . '<label>Passwort <input type="password" name="password" required minlength="' . MIN_PASSWORD_LENGTH . '" autocomplete="new-password"></label>'
    . '<label>Gruppe <select name="role"><option value="user">user</option><option value="admin">admin</option></select></label>'
    . '<button>Anlegen</button></form>'
    . '<h2>Vorhandene Benutzer</h2>'
    . '<table><tr><th>Username</th><th>Nachname, Vorname</th><th>Email</th><th>Gruppenname</th><th></th></tr>';
foreach ($users as $u) {
    echo '<tr><td>' . e($u['username']) . '</td><td>' . e($u['lastname'] . ', ' . $u['firstname'])
        . '</td><td>' . e($u['email']) . '</td><td>' . e($u['role']) . '</td><td><form method="post">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="action" value="delete">'
        . '<input type="hidden" name="username" value="' . e($u['username']) . '">'
        . '<button>Löschen</button></form></td></tr>';
}
echo '</table>';
render_footer();
