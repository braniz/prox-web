<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$error = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $current = (string) ($_POST['current'] ?? '');
    $new = (string) ($_POST['new'] ?? '');
    $repeat = (string) ($_POST['repeat'] ?? '');
    if (!password_verify($current, $user['password_hash'])) {
        $error = 'Aktuelles Passwort ist falsch.';
    } elseif (strlen($new) < MIN_PASSWORD_LENGTH) {
        $error = 'Das neue Passwort muss mindestens ' . MIN_PASSWORD_LENGTH . ' Zeichen lang sein.';
    } elseif ($new !== $repeat) {
        $error = 'Die neuen Passwörter stimmen nicht überein.';
    } elseif ($new === $current) {
        $error = 'Das neue Passwort muss sich vom aktuellen unterscheiden.';
    } else {
        $users = load_users();
        $users[$user['username']]['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
        $users[$user['username']]['must_change_password'] = false;
        save_json('users', $users);
        session_regenerate_id(true);
        $user = $users[$user['username']];
        $ok = true;
    }
}

render_header('Mein Profil', 'profile');
if ($user['must_change_password']) {
    echo '<p style="color:#b00;font-weight:bold">Warnung: Bitte ändern Sie jetzt Ihr Passwort, '
        . 'bevor Sie fortfahren.</p>';
}
if ($error !== '') {
    echo '<p style="color:#b00">' . e($error) . '</p>';
}
if ($ok) {
    echo '<p style="color:#070">Passwort wurde geändert.</p>';
}
echo '<p>Benutzername: ' . e($user['username']) . '<br>Name: '
    . e(trim($user['firstname'] . ' ' . $user['lastname'])) . '</p>'
    . '<h2>Passwort ändern</h2><form method="post">'
    . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
    . '<label>Aktuelles Passwort <input type="password" name="current" required autocomplete="current-password"></label>'
    . '<label>Neues Passwort <input type="password" name="new" required minlength="' . MIN_PASSWORD_LENGTH . '" autocomplete="new-password"></label>'
    . '<label>Neues Passwort wiederholen <input type="password" name="repeat" required autocomplete="new-password"></label>'
    . '<button>Ändern</button></form>';
render_footer();
