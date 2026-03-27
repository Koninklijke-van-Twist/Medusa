<?php
require_once __DIR__ . '/loadingscreen.php';
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Altijd laden</title>
</head>

<body style="margin:0; font-family:Verdana, Geneva, Tahoma, sans-serif;">
    <?= render_loading_screen([
        'id' => 'forever-loading-screen',
        'title' => 'Bezig met laden...',
        'subtitle' => 'Dit scherm blijft actief',
        'visible' => true,
    ]) ?>
</body>

</html>