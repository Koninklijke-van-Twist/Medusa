<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

$logFile = sys_get_temp_dir() . '/medusa-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['MEDUSA_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
    ];
    if (preg_match('#/ODataV4/Company(?:\\?|$)#', $url) === 1) {
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/odata.php';
require dirname(__DIR__) . '/web/auth_helper.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Medusa] Mímir failed, falling back to direct OData:');
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

$builtBefore = auth_build_company_base_url('KVT Gas', 'Production');
if (strpos($builtBefore, "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/") !== 0) {
    fail('company-URL moet de BC-base gebruiken, kreeg: ' . $builtBefore);
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser') {
    fail('company-fallback gebruikte niet de BC-credentials');
}

$builtAfter = auth_build_company_base_url('KVT Gas', 'Production');
if ($builtAfter !== $builtBefore) {
    fail('na de circuit-open moet de company-URL de BC-base houden, kreeg: ' . $builtAfter);
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() !== 1) {
    fail('alleen de eerste Mímir-fout mag gelogd worden, log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Medusa] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$beforeDebug = count($calls);
$debug = odata_debug_fetch_raw(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    ['mode' => 'basic', 'user' => '', 'pass' => '']
);
if (($debug['curl_error'] ?? '') !== '' || strpos((string) ($debug['raw'] ?? ''), 'WO-1') === false) {
    fail('odata_debug_fetch_raw viel niet terug: ' . json_encode($debug));
}
if (($debug['auth_mode'] ?? '') === 'mimir') {
    fail('debug-fallback bleef op Mímir-modus staan');
}
$debugCall = $calls[$beforeDebug] ?? null;
if (!is_array($debugCall) || ($debugCall['user'] ?? '') !== 'bcuser' || ($debugCall['url'] ?? '') !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('debug-fallback URL/auth klopt niet: ' . json_encode($debugCall));
}

odata_mimir_circuit_reset();
unset(
    $GLOBALS['demeter_company_environment_map'],
    $GLOBALS['demeter_companies_by_environment'],
    $GLOBALS['demeter_active_environments']
);
$discovered = auth_discover_companies_across_active_environments(30);
if (($discovered['companies'] ?? []) !== $expectedNames || ($discovered['map']['KVT Gas'] ?? '') !== 'Production') {
    fail('company-discovery viel niet terug op BC: ' . json_encode($discovered));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

$auth_list['Sandbox'] = ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'];
$GLOBALS['demeter_company_environment_map'] = [
    'KVT Gas' => 'Production',
    'Sandbox BV' => 'Sandbox',
];
odata_mimir_circuit_reset();
$loggedBeforeSecondEnv = fallback_count();
$beforeSecondEnv = count($calls);
$secondEnvRows = odata_mimir_query('Sandbox BV', 'AppResource', ['$select' => 'No'], 30);
if (($secondEnvRows[0]['No'] ?? '') !== 'WO-1') {
    fail('tweede environment gaf geen BC-rijen');
}
$secondEnvCall = $calls[$beforeSecondEnv] ?? null;
$expectedSecondUrl = "https://bc.example:7148/Sandbox/ODataV4/Company('Sandbox%20BV')/AppResource?";
if (!is_array($secondEnvCall) || strpos((string) ($secondEnvCall['url'] ?? ''), $expectedSecondUrl) !== 0 || ($secondEnvCall['user'] ?? '') !== 'sandbox-user') {
    fail('query gebruikte niet het environment van het bedrijf: ' . json_encode($secondEnvCall));
}
if (fallback_count() !== $loggedBeforeSecondEnv + 1) {
    fail('tweede environment mag maar één keer loggen, log=' . fallback_log());
}
odata_mimir_query('Sandbox BV', 'AppResource', ['$select' => 'No'], 30);
if (fallback_count() !== $loggedBeforeSecondEnv + 1) {
    fail('open circuit mag niet opnieuw loggen, log=' . fallback_log());
}
if (strpos(fallback_log(), 'sandbox-secret') !== false) {
    fail('log bevat een geheim');
}
odata_mimir_circuit_reset();
$mimirSegmentRows = odata_get_all(
    "https://mimir.invalid/mimir/ODataV4/Company('Sandbox%20BV')/AppResource?\$select=No",
    $auth,
    30
);
if (($mimirSegmentRows[0]['No'] ?? '') !== 'WO-1') {
    fail('mimir-segment URL viel niet terug');
}
$mimirSegmentCall = $calls[count($calls) - 1] ?? null;
if (!is_array($mimirSegmentCall) || strpos((string) ($mimirSegmentCall['url'] ?? ''), "https://bc.example:7148/Sandbox/ODataV4/Company('Sandbox%20BV')/AppResource?") !== 0 || ($mimirSegmentCall['user'] ?? '') !== 'sandbox-user') {
    fail('mimir-segment werd niet naar het bedrijfs-environment herschreven: ' . json_encode($mimirSegmentCall));
}
$encodedEnvUrl = odata_bc_url_from_odata_url("https://mimir.invalid/My%20Env/ODataV4/Company('X')/AppResource");
if ($encodedEnvUrl !== "https://bc.example:7148/My%20Env/ODataV4/Company('X')/AppResource") {
    fail('environment-segment werd dubbel gecodeerd: ' . $encodedEnvUrl);
}

$environment = 'mimir';
$cacheKey = build_cache_key("https://bc.example:7148/Sandbox/ODataV4/Company('Sandbox%20BV')/AppResource", ['user' => 'sandbox-user']);
$cacheEnv = substr(strrchr($cacheKey, '|'), 1);
if ($cacheEnv !== 'Sandbox') {
    fail('cache-key moet het echte BC-environment gebruiken, kreeg: ' . $cacheKey);
}
$environment = 'Production';

odata_mimir_circuit_reset();
$callsBeforeLocal = count($calls);
$loggedBeforeLocal = fallback_count();
$localThrew = false;
try {
    odata_get_all('https://mimir.invalid/not-odata', $auth, 30);
} catch (Throwable $localError) {
    $localThrew = strpos($localError->getMessage(), 'kon niet worden vertaald') !== false;
}
if (!$localThrew) {
    fail('een lokale vertaalfout moet blijven bestaan');
}
if (odata_mimir_circuit_open() || count($calls) !== $callsBeforeLocal || fallback_count() !== $loggedBeforeLocal) {
    fail('een lokale vertaalfout mag het circuit niet openen');
}

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/AppWerkorders', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable) {
    fail('geen exception gevangen');
}
if (strpos($rethrown->getMessage(), 'Mímir') !== 0 && strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . $rethrown->getMessage());
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

$savedGlobals = [];
foreach (['baseUrl', 'auth', 'auth_list', 'environment', 'base', 'MEDUSA_AUTH_PHP_PATH', 'MEDUSA_AUTH_PHP_VARS'] as $globalName) {
    $savedGlobals[$globalName] = array_key_exists($globalName, $GLOBALS) ? $GLOBALS[$globalName] : null;
    $savedGlobals[$globalName . '_set'] = array_key_exists($globalName, $GLOBALS);
    unset($GLOBALS[$globalName]);
}
$authFile = sys_get_temp_dir() . '/medusa-auth-lazy.php';
file_put_contents($authFile, "<?php\n\$baseUrl = 'https://from-file.example:7148/';\n\$environment = 'FromFile';\n\$auth_list = ['FromFile' => ['mode' => 'basic', 'user' => 'fileuser', 'pass' => 'file-secret']];\n\$auth = \$auth_list['FromFile'];\n\$base = 'https://from-file.example:7148/base';\n");
$GLOBALS['MEDUSA_AUTH_PHP_PATH'] = $authFile;
odata_bc_ensure_auth_loaded();
if (($GLOBALS['baseUrl'] ?? '') !== 'https://from-file.example:7148/'
    || ($GLOBALS['environment'] ?? '') !== 'FromFile'
    || ($GLOBALS['auth']['user'] ?? '') !== 'fileuser'
    || ($GLOBALS['auth_list']['FromFile']['user'] ?? '') !== 'fileuser'
    || ($GLOBALS['base'] ?? '') !== 'https://from-file.example:7148/base') {
    fail('auth.php-variabelen zijn niet naar $GLOBALS gekopieerd: ' . json_encode([
        'baseUrl' => $GLOBALS['baseUrl'] ?? null,
        'environment' => $GLOBALS['environment'] ?? null,
        'auth' => $GLOBALS['auth'] ?? null,
        'base' => $GLOBALS['base'] ?? null,
    ]));
}
$GLOBALS['baseUrl'] = 'https://keep.example/';
unset($GLOBALS['MEDUSA_AUTH_PHP_VARS']);
odata_bc_ensure_auth_loaded();
if (($GLOBALS['baseUrl'] ?? '') !== 'https://keep.example/') {
    fail('een gezette baseUrl werd overschreven: ' . (string) ($GLOBALS['baseUrl'] ?? ''));
}
if (($GLOBALS['environment'] ?? '') !== 'FromFile' || ($GLOBALS['auth']['user'] ?? '') !== 'fileuser') {
    fail('een tweede load maakte de andere globals leeg');
}
if (strpos(fallback_log(), 'file-secret') !== false) {
    fail('log bevat een geheim uit auth.php');
}
@unlink($authFile);
foreach (['baseUrl', 'auth', 'auth_list', 'environment', 'base', 'MEDUSA_AUTH_PHP_PATH', 'MEDUSA_AUTH_PHP_VARS'] as $globalName) {
    if (!empty($savedGlobals[$globalName . '_set'])) {
        $GLOBALS[$globalName] = $savedGlobals[$globalName];
    } else {
        unset($GLOBALS[$globalName]);
    }
}

echo "OK\n";
