<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (current_user() !== null) {
    redirect('/');
}
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (login_locked()) {
        $error = 'Zu viele Fehlversuche. Bitte später erneut versuchen.';
    } else {
        $users = load_users();
        $hash = isset($users[$name]) ? $users[$name]['password_hash'] : password_hash('dummy', PASSWORD_DEFAULT);
        if (password_verify($password, $hash) && isset($users[$name])) {
            login_reset();
            session_regenerate_id(true);
            $_SESSION['user'] = $name;
            redirect('/');
        }
        login_failed();
        $error = 'Benutzername oder Passwort falsch.';
    }
}

render_header('Anmeldung', 'login');
if ($error !== '') {
    echo '<p style="color:#b00">' . e($error) . '</p>';
}
echo '<form method="post"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
    . '<label>Benutzername <input name="username" required autofocus></label>'
    . '<label>Passwort <input type="password" name="password" required></label>'
    . '<button>Anmelden</button></form>';
render_footer();
