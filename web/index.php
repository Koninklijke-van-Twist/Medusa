<?php
require_once __DIR__ . '/loadingscreen.php';
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medusa laden...</title>
    <meta http-equiv="refresh" content="4;url=goedkeuren.php">
</head>

<body style="margin:0; font-family:Verdana, Geneva, Tahoma, sans-serif;">
    <?= render_loading_screen([
        'id' => 'index-loading-screen',
        'title' => 'Applicatie laden...',
        'subtitle' => 'Je wordt doorgestuurd naar goedkeuren',
        'visible' => true,
        'redirectUrl' => 'goedkeuren.php',
        'redirectDelayMs' => 1200,
    ]) ?>
    <noscript>
        <div style="padding:20px;">JavaScript is uitgeschakeld. Klik <a href="goedkeuren.php">hier</a> om verder te
            gaan.</div>
    </noscript>

<?php
if (!function_exists('kvt_email_is_admin')) {
    $__kvtUa = __DIR__ . '/lib/UserAccess.php';
    if (is_file($__kvtUa)) {
        require_once $__kvtUa;
    }
}
$__kvtEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
if ($__kvtEmail !== '' && function_exists('kvt_email_is_admin') && kvt_email_is_admin($__kvtEmail)):
?>
<p style="margin:12px 20px"><a href="admin_users.php">Gebruikersbeheer</a></p>
<?php endif; ?>
</body>

</html>