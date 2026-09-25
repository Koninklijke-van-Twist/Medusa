<?php

/**
 * OData-routing: Mímir als $mimirApi gezet is, BC als de key ontbreekt.
 * Run: php web/tests/test_mimir_odata_routing.php
 */

require_once dirname(__DIR__) . '/odata.php';
require_once dirname(__DIR__) . '/auth_helper.php';

$failures = 0;
$mockPort = 18941;
$mockLog = sys_get_temp_dir() . '/ploutos-mimir-mock.log';
$mockScript = sys_get_temp_dir() . '/ploutos-mimir-mock.php';
$serverLog = sys_get_temp_dir() . '/ploutos-mimir-server.log';
$authPath = dirname(__DIR__) . '/auth.php';
$authBackup = null;

function test_assert(string $name, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "OK  {$name}\n";
        return;
    }

    $failures++;
    echo "FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function test_reset_discovery_cache(): void
{
    unset(
        $GLOBALS['demeter_company_environment_map'],
        $GLOBALS['demeter_companies_by_environment'],
        $GLOBALS['demeter_active_environments']
    );
}

function test_write_mock(): void
{
    global $mockScript, $mockLog;
    $log = var_export($mockLog, true);
    $php = <<<'PHP'
<?php
$log = LOG_PATH;
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents($log, json_encode([
    'uri' => $uri,
    'method' => $method,
    'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'authorization' => $authorization,
    'php_auth_user' => (string) ($_SERVER['PHP_AUTH_USER'] ?? ''),
    'api_key' => (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''),
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');

if (str_contains($uri, '/mimir/api/redirect.php')) {
    header('Location: http://127.0.0.1:1/stolen', true, 302);
    echo json_encode(['error' => 'redirect']);
    exit;
}

if (str_contains($uri, '/mimir-dup/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Overlap BV', 'environment' => 'Production'],
        ['name' => 'Overlap BV', 'environment' => 'Sandbox'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Hunter van Twist', 'environment' => 'Sandbox'],
        ['name' => 'Koninklijke van Twist', 'environment' => 'Production'],
        ['name' => "Van Twist's", 'environment' => 'Production'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/query.php')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    echo json_encode(['value' => [[
        'No' => 'TS1',
        'company' => (string) ($body['company'] ?? ''),
        'table' => (string) ($body['table'] ?? ''),
        'select' => $body['select'] ?? [],
        'filter' => (string) ($body['filter'] ?? ''),
        'max_age' => $body['max_age'] ?? null,
    ]]]);
    exit;
}

$user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
if ($user === '' && str_starts_with($authorization, 'Basic ')) {
    $decoded = base64_decode(substr($authorization, 6), true);
    if (is_string($decoded) && str_contains($decoded, ':')) {
        $user = explode(':', $decoded, 2)[0];
    }
}
echo json_encode(['value' => [[
    'Name' => 'BC Company',
    'via' => 'bc',
    'user' => $user,
]]]);
PHP;
    file_put_contents($mockScript, str_replace('LOG_PATH', $log, $php));
}

/**
 * Zet auth.php terug. Verwijdert het bestand alleen als deze test het zelf heeft aangemaakt.
 */
function test_restore_auth_php(string $path, bool $existedBefore, ?string $backup, bool $written): void
{
    if (!$written) {
        return;
    }

    if ($existedBefore) {
        if (!is_string($backup)) {
            return;
        }
        file_put_contents($path, $backup);
        return;
    }

    @unlink($path);
}

function test_mock_requests(): array
{
    global $mockLog;
    if (!is_file($mockLog)) {
        return [];
    }
    $rows = [];
    foreach (file($mockLog, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    return $rows;
}

function test_cache_files(): array
{
    $files = glob(dirname(__DIR__) . '/cache/odata/*.json') ?: [];
    sort($files);
    return $files;
}

test_assert('mimir uit zonder key', odata_mimir_enabled() === false);
test_assert(
    'default Mímir-base',
    odata_mimir_base_url() === 'https://sleutels.kvt.nl/mimir/api'
);

$restoreProbe = sys_get_temp_dir() . '/ploutos-auth-restore-probe.php';
$restoreCreated = sys_get_temp_dir() . '/ploutos-auth-restore-created.php';
file_put_contents($restoreProbe, "<?php\n\$marker = 'original';\n");
test_restore_auth_php($restoreProbe, true, "<?php\n\$marker = 'original';\n", false);
test_assert(
    'restore laat bestaand bestand met rust als de test niet schreef',
    is_file($restoreProbe) && str_contains((string) file_get_contents($restoreProbe), 'original')
);
file_put_contents($restoreProbe, "<?php\n\$marker = 'replaced';\n");
test_restore_auth_php($restoreProbe, true, "<?php\n\$marker = 'original';\n", true);
test_assert(
    'restore zet backup terug nadat de test schreef',
    is_file($restoreProbe) && str_contains((string) file_get_contents($restoreProbe), 'original')
);
@unlink($restoreProbe);
file_put_contents($restoreCreated, "<?php\n\$marker = 'created';\n");
test_restore_auth_php($restoreCreated, false, null, true);
test_assert('restore verwijdert alleen een door de test aangemaakt bestand', !is_file($restoreCreated));
file_put_contents($restoreCreated, "<?php\n\$marker = 'keep';\n");
test_restore_auth_php($restoreCreated, true, null, true);
test_assert(
    'restore wist geen bestaand bestand zonder leesbare backup',
    is_file($restoreCreated) && str_contains((string) file_get_contents($restoreCreated), 'keep')
);
@unlink($restoreCreated);

$spaceUrl = 'https://bc.example/Production/ODataV4/Company(\'' . rawurlencode('Koninklijke van Twist') . '\')/Urenstaten?$select=No,Name&$filter=' . rawurlencode("Resource_No eq 'A'");
$parsedSpace = odata_mimir_parse_entity_url($spaceUrl);
test_assert(
    'entity-URL met spatie in bedrijfsnaam',
    is_array($parsedSpace)
        && ($parsedSpace['company'] ?? '') === 'Koninklijke van Twist'
        && ($parsedSpace['entity'] ?? '') === 'Urenstaten'
        && ($parsedSpace['query']['$select'] ?? '') === 'No,Name'
        && ($parsedSpace['query']['$filter'] ?? '') === "Resource_No eq 'A'",
    json_encode($parsedSpace, JSON_UNESCAPED_UNICODE)
);
test_assert(
    'entity-URL is geen company-discovery',
    odata_mimir_parse_companies_url($spaceUrl) === null
);

$companiesUrl = 'https://bc.example/Production/ODataV4/Companies?$select=Name';
$parsedCompanies = odata_mimir_parse_companies_url($companiesUrl);
test_assert(
    'companies-URL levert environment',
    is_array($parsedCompanies) && ($parsedCompanies['environment'] ?? '') === 'Production',
    json_encode($parsedCompanies)
);
test_assert('companies-URL is geen entity', odata_mimir_parse_entity_url($companiesUrl) === null);

$threw = false;
try {
    auth_get_auth_for_environment('Production');
} catch (RuntimeException $error) {
    $threw = str_contains($error->getMessage(), 'Geen auth-configuratie');
}
test_assert('BC-auth ontbreekt blijft exception zonder Mímir', $threw);

$threw = false;
try {
    auth_discover_companies_across_active_environments(30);
} catch (RuntimeException $error) {
    $threw = str_contains($error->getMessage(), 'Geen actieve environments');
}
test_assert('company-discovery faalt snel zonder BC-config en zonder Mímir', $threw);

$baseUrl = '';
$threw = false;
try {
    auth_build_company_base_url('Koninklijke van Twist', 'Production');
} catch (RuntimeException $error) {
    $threw = str_contains($error->getMessage(), 'baseUrl ontbreekt');
}
test_assert('company-URL eist baseUrl zonder Mímir', $threw);

$threw = false;
try {
    auth_build_company_base_url('Koninklijke van Twist', '');
} catch (RuntimeException $error) {
    $threw = str_contains($error->getMessage(), 'Geen environment beschikbaar');
}
test_assert('leeg environment blijft een fout zonder Mímir', $threw);

test_write_mock();
@unlink($mockLog);
@unlink($serverLog);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $mockPort, $mockScript],
    [
        1 => ['file', $serverLog, 'w'],
        2 => ['file', $serverLog, 'a'],
    ],
    $pipes,
    sys_get_temp_dir()
);
test_assert('mock-server start', is_resource($server));
$ready = false;
for ($attempt = 0; $attempt < 30; $attempt++) {
    $socket = @fsockopen('127.0.0.1', $mockPort, $errno, $errstr, 0.1);
    if (is_resource($socket)) {
        fclose($socket);
        $ready = true;
        break;
    }
    usleep(50000);
}
test_assert('mock-server luistert', $ready);

$mimirApi = 'mimir_test_key';
$mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir/api';
$baseUrl = '';
unset($GLOBALS['auth_list'], $GLOBALS['environment'], $GLOBALS['auth']);
test_reset_discovery_cache();

$authExistedBefore = is_file($authPath);
$authBackup = null;
if ($authExistedBefore) {
    $authRaw = file_get_contents($authPath);
    $authBackup = is_string($authRaw) ? $authRaw : null;
}
$authWritten = false;
$cacheBefore = test_cache_files();

try {
    $threw = false;
    try {
        auth_build_company_base_url('Koninklijke van Twist', '   ');
    } catch (RuntimeException $error) {
        $threw = str_contains($error->getMessage(), 'Geen environment beschikbaar');
    }
    test_assert('leeg environment blijft een fout in Mímir-modus', $threw);

    $apostropheBase = auth_build_company_base_url("Van Twist's", 'Production');
    $apostropheUrl = $apostropheBase . 'Urenstaten?$select=No,Resource_No&$filter=' . rawurlencode("Resource_No eq 'A'") . '&$format=json';
    $parsedApostrophe = odata_mimir_parse_entity_url($apostropheUrl);
    test_assert(
        'lege baseUrl en apostrof in bedrijfsnaam',
        is_array($parsedApostrophe)
            && ($parsedApostrophe['company'] ?? '') === "Van Twist's"
            && ($parsedApostrophe['entity'] ?? '') === 'Urenstaten'
            && ($parsedApostrophe['query']['$select'] ?? '') === 'No,Resource_No'
            && ($parsedApostrophe['query']['$filter'] ?? '') === "Resource_No eq 'A'",
        json_encode($parsedApostrophe, JSON_UNESCAPED_UNICODE) . ' url=' . $apostropheUrl
    );

    $discovered = auth_discover_companies_across_active_environments(30);
    test_assert(
        'Mímir company-discovery zonder BC-creds',
        ($discovered['companies'] ?? []) === ['Hunter van Twist', 'Koninklijke van Twist', "Van Twist's"]
            && ($discovered['map']['Koninklijke van Twist'] ?? '') === 'Production'
            && ($discovered['map']['Hunter van Twist'] ?? '') === 'Sandbox'
            && ($discovered['map']["Van Twist's"] ?? '') === 'Production'
            && ($discovered['primary_environment'] ?? '') === 'Production',
        json_encode($discovered, JSON_UNESCAPED_UNICODE)
    );

    $auth = auth_get_auth_for_environment('Production');
    test_assert('lege auth-sentinel in Mímir-modus', $auth === []);

    $context = auth_set_current_company_context('Hunter van Twist', 30);
    test_assert(
        'company-context zonder BC-auth',
        ($context['environment'] ?? '') === 'Sandbox' && ($context['auth'] ?? null) === []
    );

    $active = auth_get_active_environments();
    test_assert(
        'environments uit Mímir als auth_list ontbreekt',
        $active === ['Production', 'Sandbox'],
        json_encode($active)
    );
    unset($GLOBALS['demeter_active_environments']);
    $coldActive = auth_get_active_environments();
    test_assert(
        'koude environment-lijst komt gesorteerd uit Mímir',
        $coldActive === ['Production', 'Sandbox'],
        json_encode($coldActive)
    );

    $rows = odata_get_all($apostropheUrl, [], 60);
    test_assert(
        'odata_get_all via Mímir zonder BC-creds',
        is_array($rows[0] ?? null)
            && ($rows[0]['company'] ?? '') === "Van Twist's"
            && ($rows[0]['table'] ?? '') === 'Urenstaten'
            && ($rows[0]['filter'] ?? '') === "Resource_No eq 'A'"
            && ($rows[0]['max_age'] ?? null) === 60
            && ($rows[0]['select'] ?? []) === ['No', 'Resource_No'],
        json_encode($rows, JSON_UNESCAPED_UNICODE)
    );
    test_assert('Mímir slaat Ploutos-filecache over', test_cache_files() === $cacheBefore);

    $companyRows = odata_get_all('https://bc.example/Sandbox/ODataV4/Company?$select=Name', [], 30);
    $companyNames = array_map(static function (array $row): string {
        return (string) ($row['Name'] ?? '');
    }, $companyRows);
    test_assert(
        'company-discovery-URL gaat naar Mímir, niet naar BC-host',
        $companyNames === ['Hunter van Twist'],
        json_encode($companyNames, JSON_UNESCAPED_UNICODE)
    );

    $debug = odata_debug_fetch_raw($apostropheUrl, []);
    test_assert(
        'debug-fetch blijft op Mímir',
        ($debug['auth_mode'] ?? '') === 'mimir' && ($debug['curl_error'] ?? '') === '' && str_contains((string) ($debug['raw'] ?? ''), "Van Twist's"),
        json_encode($debug, JSON_UNESCAPED_UNICODE)
    );

    $redirectThrew = false;
    try {
        odata_mimir_request('GET', 'redirect.php');
    } catch (Exception $error) {
        $redirectThrew = str_contains($error->getMessage(), 'HTTP 302');
    }
    test_assert('Mímir-request volgt geen redirect', $redirectThrew);

    $requests = test_mock_requests();
    $hitBcHost = false;
    $sawMimirUa = false;
    $sawApiKey = false;
    $followedRedirect = false;
    foreach ($requests as $request) {
        $uri = (string) ($request['uri'] ?? '');
        if (str_contains($uri, 'bc.example') || str_contains($uri, '/stolen')) {
            $hitBcHost = true;
        }
        if (str_contains($uri, '/stolen')) {
            $followedRedirect = true;
        }
        if (($request['ua'] ?? '') === 'Ploutos-MimirClient/1.0' && str_contains($uri, '/mimir/api/')) {
            $sawMimirUa = true;
        }
        if (($request['api_key'] ?? '') === 'mimir_test_key') {
            $sawApiKey = true;
        }
    }
    test_assert('geen request naar de BC-host', $hitBcHost === false);
    test_assert('redirect-doel wordt niet aangeroepen', $followedRedirect === false);
    test_assert('Mímir-client user-agent', $sawMimirUa);
    test_assert('Mímir API-key header', $sawApiKey);

    test_reset_discovery_cache();
    $mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir-dup/api';
    $overlapThrew = false;
    try {
        auth_discover_companies_across_active_environments(30);
    } catch (RuntimeException $error) {
        $overlapThrew = str_contains($error->getMessage(), 'Bedrijfsnaam-overlap');
    }
    test_assert('overlap tussen Mímir-environments blijft een fout', $overlapThrew);

    $mimirApi = '';
    $baseUrl = 'http://127.0.0.1:' . $mockPort;
    $environment = 'Production';
    $auth_list = [
        'Production' => [
            'mode' => 'basic',
            'user' => 'bcuser',
            'pass' => 'bcpass',
        ],
    ];
    $auth = $auth_list['Production'];
    if ($authExistedBefore && !is_string($authBackup)) {
        throw new RuntimeException('Bestaande auth.php kon niet worden gelezen; test wijzigt het bestand niet.');
    }
    $authWritten = true;
    file_put_contents($authPath, "<?php\n\$baseUrl = " . var_export($baseUrl, true) . ";\n\$environment = 'Production';\n\$auth_list = " . var_export($auth_list, true) . ";\n\$mimirApi = '';\n");
    test_reset_discovery_cache();
    @unlink($mockLog);

    test_assert('Mímir uit na lege key', odata_mimir_enabled() === false);
    $bcUrl = $baseUrl . '/Production/ODataV4/Companies?$select=Name';
    $bcRows = odata_get_all($bcUrl, $auth, 30);
    test_assert(
        'zonder Mímir blijft BC-fetch werken',
        is_array($bcRows[0] ?? null) && ($bcRows[0]['via'] ?? '') === 'bc' && ($bcRows[0]['user'] ?? '') === 'bcuser',
        json_encode($bcRows)
    );
    $bcRequests = test_mock_requests();
    $bcHitMimir = false;
    foreach ($bcRequests as $request) {
        if (str_contains((string) ($request['uri'] ?? ''), '/mimir/')) {
            $bcHitMimir = true;
        }
    }
    test_assert('BC-fetch raakt Mímir niet', $bcHitMimir === false);

    $cachedBc = odata_get_all($bcUrl, $auth, 30);
    test_assert(
        'BC-filecache blijft werken',
        ($cachedBc[0]['via'] ?? '') === 'bc' && count(test_mock_requests()) === count($bcRequests)
    );
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    test_restore_auth_php($authPath, $authExistedBefore, $authBackup, $authWritten);
    foreach (array_diff(test_cache_files(), $cacheBefore) as $createdCache) {
        @unlink($createdCache);
    }
    @unlink($mockScript);
    @unlink($mockLog);
    @unlink($serverLog);
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}

echo "all mimir routing tests passed\n";
exit(0);
