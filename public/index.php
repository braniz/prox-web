<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();

render_header('Benutzer-Seite', 'user');
echo '<p>Angemeldet als ' . e($user['username']) . ' (' . e(trim($user['firstname'] . ' ' . $user['lastname'])) . ')</p>';
echo '<p>wird gearbeitet</p>';
echo '<p><a href="/profile.php">Passwort ändern</a></p>';
render_footer();
