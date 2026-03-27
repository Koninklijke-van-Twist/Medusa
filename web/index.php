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
    <div style="padding:20px;">JavaScript is uitgeschakeld. Klik <a href="goedkeuren.php">hier</a> om verder te gaan.</div>
</noscript>
</body>
</html>
