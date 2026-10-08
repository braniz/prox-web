<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$readText = static function (string $name): string {
    $file = DATA_DIR . '/' . $name;
    return is_file($file) && is_readable($file) ? trim((string) file_get_contents($file)) : '';
};

$version = $readText('prox-gui-version.txt');
$info = $readText('prox-gui-info.txt');
$impressum = $readText('prox-gui-impressum.txt');

render_header('Info / Impressum', 'impressum');

echo '<div class="section-card" style="margin-bottom:1rem;">'
    . '<h2 style="margin-top:0;">Info</h2>';
if ($version !== '') {
    echo '<p><strong>Version:</strong> ' . e($version) . '</p>';
}
if ($info !== '') {
    echo '<div style="white-space:pre-wrap;">' . e($info) . '</div>';
} else {
    echo '<p class="muted">Keine Info hinterlegt (Datei <code>data/prox-gui-Info.txt</code>).</p>';
}
echo '</div>';

echo '<div class="section-card">'
    . '<h2 style="margin-top:0;">Impressum</h2>';
if ($impressum !== '') {
    echo '<div style="white-space:pre-wrap;">' . e($impressum) . '</div>';
} else {
    echo '<p class="muted">Kein Impressum hinterlegt (Datei <code>data/prox-gui-impressum.txt</code>).</p>';
}
echo '</div>';

render_footer();
