<?php
/**
 * Auth-template voor Medusa.
 *
 * Mímir heeft voorrang. Laat het BC-blok staan: dat is de automatische
 * fallback wanneer Mímir uitvalt. Zet beide naast elkaar in web/auth.php
 * (niet in git):
 *   $mimirApi  = 'mimir_…';  // verplicht om Mímir te activeren
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
 *
 * Met $mimirApi gezet gaan fetches eerst naar Mímir en bij een storing naar
 * de BC-variabelen hieronder. Zonder $mimirApi wordt alleen het BC-blok gebruikt.
 * Ontbreken de BC-credentials, dan komt de oorspronkelijke Mímir-fout terug.
 */

// --- Mímir (aanbevolen) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Business Central (directe route, én fallback als Mímir faalt) ---
$auth_list =
    [
        "env1" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
        "env2" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
        "env3" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD']
    ];
$environment = "env1";
$auth = $auth_list[$environment];
$baseUrl = "https://my-bc-domain.com:7148/";

$allowedUsers = [
    "user@domain.nl"
];
