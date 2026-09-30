<?php

/**
 * Query-planning voor de goedkeurpagina: korte filters, cutoff, activiteit.
 * Run: php web/tests/test_goedkeuren_load.php
 */

require_once dirname(__DIR__) . '/odata.php';
require_once dirname(__DIR__) . '/lib_goedkeuren.php';

$failures = 0;

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

function test_filter_values(string $url): array
{
    $parts = parse_url($url);
    $query = [];
    if (is_array($parts) && isset($parts['query'])) {
        parse_str((string) $parts['query'], $query);
    }
    $filter = (string) ($query['$filter'] ?? '');
    preg_match_all("/eq '((?:[^']|'')*)'/", $filter, $matches);

    return $matches[1] ?? [];
}

$longIds = [];
for ($i = 1; $i <= 40; $i++) {
    $longIds[] = sprintf('TS%020d', $i);
}
$chunks = gk_odata_chunk_values('Time_Sheet_No', $longIds);
$flat = [];
$maxClauses = 0;
$maxChars = 0;
foreach ($chunks as $chunk) {
    $maxClauses = max($maxClauses, count($chunk));
    $decoded = gk_odata_or_filter_decoded('Time_Sheet_No', $chunk);
    $maxChars = max($maxChars, strlen($decoded));
    foreach ($chunk as $id) {
        $flat[] = $id;
    }
}
test_assert('lange urenstaatnummers worden gesplitst', count($chunks) > 1, 'chunks=' . count($chunks));
test_assert(
    'elk chunk blijft binnen clausule- en tekenbudget',
    $maxClauses <= gk_odata_max_clauses() && $maxChars <= gk_odata_max_filter_chars(),
    "clauses={$maxClauses} chars={$maxChars}"
);
test_assert('alle sleutels blijven aanwezig', $flat === $longIds);

$quoted = gk_odata_or_filter_decoded('Resource_No', ["O'Neil"]);
test_assert('apostrof in sleutel wordt verdubbeld', $quoted === "Resource_No eq 'O''Neil'", $quoted);

$small = gk_plan_future_timesheet_filters(['A', 'B', 'C'], '2026-07-01', '2027-07-01');
test_assert('klein bedrijf: één precieze toekomstfilter', count($small) === 1, json_encode($small));
$smallFilter = (string) ($small[0] ?? '');
test_assert(
    'precieze filter bevat datums en resources',
    strpos($smallFilter, 'Starting_Date ge 2026-07-01') !== false
        && strpos($smallFilter, 'Starting_Date le 2027-07-01') !== false
        && strpos($smallFilter, "Resource_No eq 'A'") !== false
        && strpos($smallFilter, "Resource_No eq 'B'") !== false
        && strpos($smallFilter, "Resource_No eq 'C'") !== false,
    $smallFilter
);

$manyResources = [];
for ($i = 1; $i <= 100; $i++) {
    $manyResources[] = sprintf('R%04d', $i);
}
$wide = gk_plan_future_timesheet_filters($manyResources, '2026-09-01', '2027-09-30');
test_assert(
    'KVT-lookahead is één datumfilter zonder resource-OR',
    count($wide) === 1 && strpos((string) ($wide[0] ?? ''), 'Resource_No') === false
        && strpos((string) ($wide[0] ?? ''), 'Starting_Date ge 2026-09-01') !== false
        && strpos((string) ($wide[0] ?? ''), 'Starting_Date le 2027-09-30') !== false,
    json_encode($wide)
);
test_assert('ongeldige datum levert geen toekomstfilter', gk_plan_future_timesheet_filters(['A'], '01-09-2026', '2027-09-30') === []);

$window = gk_timesheet_query_window('2026-07-01', '2026-07-01', '2026-09-30', '2026-09-30');
test_assert(
    'header-venster padt zeven dagen voor de maandag-grens',
    ($window['from'] ?? '') === '2026-06-24' && ($window['to'] ?? '') === '2026-09-30',
    json_encode($window)
);
$early = gk_timesheet_query_window('2026-07-01', '2026-01-01', '2026-09-30', '2026-12-31');
test_assert(
    'expliciet bereik blijft ruimer dan de pad',
    ($early['from'] ?? '') === '2026-01-01' && ($early['to'] ?? '') === '2026-12-31',
    json_encode($early)
);
test_assert(
    'urenstaat valt in venster',
    gk_timesheet_matches_window(['Starting_Date' => '2026-06-29', 'Ending_Date' => '2026-07-05'], '2026-06-24', '2026-09-30')
);
test_assert(
    'urenstaat buiten venster valt af',
    !gk_timesheet_matches_window(['Starting_Date' => '2026-05-01', 'Ending_Date' => '2026-05-07'], '2026-06-24', '2026-09-30')
);

$resources = [
    'R1' => ['name' => 'Een'],
    'R2' => ['name' => 'Twee'],
    'OUT' => ['name' => 'Buiten'],
];
$activity = gk_recent_activity_from_headers([
    ['Resource_No' => 'R1', 'Starting_Date' => '2026-09-30'],
    ['Resource_No' => 'NOPE', 'Starting_Date' => '2026-09-28'],
    ['Resource_No' => 'R2', 'Starting_Date' => 'geen-datum'],
    ['Resource_No' => '', 'Starting_Date' => '2026-09-28'],
], $resources);
test_assert(
    'activiteit komt uit de header-resource',
    ($activity['resourceNos']['R1'] ?? false) === true
        && ($activity['resourceNos']['R2'] ?? false) === true
        && !isset($activity['resourceNos']['NOPE'])
        && !isset($activity['resourceNos']['OUT'])
);
test_assert(
    'weekstart is de maandag van Starting_Date',
    isset($activity['weekStarts']['2026-09-28']) && count($activity['weekStarts']) === 1,
    json_encode(array_keys($activity['weekStarts']))
);

$lineHeaders = [
    'TS-KEEP' => [
        'No' => 'TS-KEEP',
        'Resource_No' => 'R1',
        'Quantity_Open' => 0,
        'Quantity_Submitted' => '1,5',
        'Quantity_Approved' => 0,
        'Quantity_Rejected' => 0,
        'LVS_Open_Exists' => false,
        'LVS_Approved_Exists' => false,
        'LVS_Rejected_Exists' => false,
    ],
    'TS-EMPTY' => [
        'No' => 'TS-EMPTY',
        'Resource_No' => 'R1',
        'Quantity_Open' => 0,
        'Quantity_Submitted' => 0,
        'Quantity_Approved' => 0,
        'Quantity_Rejected' => 0,
        'LVS_Open_Exists' => false,
        'LVS_Approved_Exists' => 'nee',
        'LVS_Rejected_Exists' => false,
    ],
    'TS-FLAG' => [
        'No' => 'TS-FLAG',
        'Resource_No' => 'R1',
        'Quantity_Open' => 0,
        'Quantity_Submitted' => 0,
        'Quantity_Approved' => 0,
        'Quantity_Rejected' => 0,
        'LVS_Open_Exists' => true,
        'LVS_Approved_Exists' => false,
        'LVS_Rejected_Exists' => false,
    ],
    'TS-OTHER' => [
        'No' => 'TS-OTHER',
        'Resource_No' => 'R9',
        'Quantity_Open' => 8,
        'Quantity_Submitted' => 0,
        'Quantity_Approved' => 0,
        'Quantity_Rejected' => 0,
    ],
    'TS-UNKNOWN' => [
        'No' => 'TS-UNKNOWN',
        'Resource_No' => 'R1',
    ],
];
$lineNos = gk_timesheet_numbers_for_lines($lineHeaders, ['R1' => true]);
sort($lineNos);
test_assert(
    'regels alleen voor gevulde of onbekende staten van deze resources',
    $lineNos === ['TS-FLAG', 'TS-KEEP', 'TS-UNKNOWN'],
    json_encode($lineNos)
);

$baseResources = [
    'BC' => [
        'terminationDate' => '2026-08-01',
        'effectiveTerminationDate' => '2026-08-01',
        'effectiveTerminationSource' => 'bc',
    ],
    'GAP' => [
        'terminationDate' => '0001-01-01',
        'effectiveTerminationDate' => '',
        'effectiveTerminationSource' => 'bc',
    ],
    'NONE' => [
        'terminationDate' => '',
        'effectiveTerminationDate' => '',
        'effectiveTerminationSource' => 'bc',
    ],
    'CONT' => [
        'terminationDate' => '',
        'effectiveTerminationDate' => '',
        'effectiveTerminationSource' => 'bc',
    ],
    'LATE' => [
        'terminationDate' => '',
        'effectiveTerminationDate' => '',
        'effectiveTerminationSource' => 'bc',
    ],
];
$weeks = ['2026-09-07', '2026-09-14', '2026-09-21'];
$cutoff = gk_apply_future_timesheet_cutoffs($baseResources, [
    ['Resource_No' => 'GAP', 'Starting_Date' => '2026-09-07'],
    ['Resource_No' => 'GAP', 'Starting_Date' => '2026-09-22'],
    ['Resource_No' => 'CONT', 'Starting_Date' => '2026-09-07'],
    ['Resource_No' => 'CONT', 'Starting_Date' => '2026-09-14'],
    ['Resource_No' => 'CONT', 'Starting_Date' => '2026-09-21'],
    ['Resource_No' => 'LATE', 'Starting_Date' => '2026-09-21'],
    ['Resource_No' => 'OTHER', 'Starting_Date' => '2026-09-07'],
], $weeks, '2026-09-01');
test_assert(
    'BC-uitdienst blijft BC',
    ($cutoff['resources']['BC']['effectiveTerminationSource'] ?? '') === 'bc'
        && ($cutoff['resources']['BC']['effectiveTerminationDate'] ?? '') === '2026-08-01'
);
test_assert(
    'gat na een bestaande week wordt de cutoff',
    ($cutoff['resources']['GAP']['effectiveTerminationDate'] ?? '') === '2026-09-14'
        && ($cutoff['resources']['GAP']['effectiveTerminationSource'] ?? '') === 'missing-future-timesheets'
);
test_assert(
    'geen enkele staat in het jaar wordt uit dienst vanaf het startpunt',
    ($cutoff['resources']['NONE']['effectiveTerminationDate'] ?? '') === '2026-09-01'
        && ($cutoff['resources']['NONE']['effectiveTerminationSource'] ?? '') === 'missing-future-timesheets'
);
test_assert(
    'aaneengesloten weken blijven zonder cutoff',
    ($cutoff['resources']['CONT']['effectiveTerminationSource'] ?? '') === 'bc'
        && ($cutoff['resources']['CONT']['effectiveTerminationDate'] ?? '') === ''
);
test_assert(
    'ontbrekende weken vóór de eerste staat zijn geen cutoff',
    ($cutoff['resources']['LATE']['effectiveTerminationSource'] ?? '') === 'bc'
        && ($cutoff['resources']['LATE']['effectiveTerminationDate'] ?? '') === ''
);
test_assert('cutoff-tellers kloppen', ($cutoff['cutoff'] ?? -1) === 1 && ($cutoff['without'] ?? -1) === 1, json_encode($cutoff));

$seenCounts = [];
$GLOBALS['MEDUSA_GK_ODATA_GET_MANY'] = static function (array $urls) use (&$seenCounts): array {
    $completed = [];
    $failed = [];
    foreach ($urls as $index => $url) {
        $values = test_filter_values((string) $url);
        $seenCounts[] = count($values);
        if (count($values) > 2) {
            $failed[] = $index;
            continue;
        }
        $rows = [];
        foreach ($values as $value) {
            $rows[] = ['No' => str_replace("''", "'", $value)];
        }
        $completed[$index] = $rows;
    }
    if ($failed !== []) {
        throw new OdataFilterRejected('HTTP 400', $failed, $completed);
    }
    $out = [];
    foreach ($urls as $index => $url) {
        $out[] = $completed[$index] ?? [];
    }

    return $out;
};
$splitRows = gk_odata_fetch_by_or_filter(
    'https://bc.example/Production/ODataV4/Company(\'K\')/',
    'Urenstaatregels',
    'Time_Sheet_No',
    'Time_Sheet_No',
    ['A', 'B', 'C', 'D', 'E'],
    [],
    30
);
$splitNos = [];
foreach ($splitRows as $row) {
    $splitNos[] = (string) ($row['No'] ?? '');
}
sort($splitNos);
test_assert(
    'te groot filter wordt gesplitst tot alle sleutels terug zijn',
    $splitNos === ['A', 'B', 'C', 'D', 'E'] && max($seenCounts) >= 3 && min($seenCounts) <= 2,
    'nos=' . json_encode($splitNos) . ' counts=' . json_encode($seenCounts)
);
unset($GLOBALS['MEDUSA_GK_ODATA_GET_MANY']);

$futureUrls = [];
$GLOBALS['MEDUSA_GK_ODATA_GET_MANY'] = static function (array $urls) use (&$futureUrls): array {
    $futureUrls = $urls;
    return [[
        ['Resource_No' => 'A', 'Starting_Date' => '2026-09-28'],
        ['Resource_No' => 'ZZ', 'Starting_Date' => '2026-09-28'],
    ]];
};
$futureRows = gk_fetch_future_timesheets_for_resources(
    'https://bc.example/Production/ODataV4/Company(\'K\')/',
    [],
    30,
    ['A', 'B'],
    '2026-09-01',
    '2027-09-30'
);
test_assert('toekomstfetch houdt alleen gevraagde resources', count($futureRows) === 1 && ($futureRows[0]['Resource_No'] ?? '') === 'A');
$futureFilter = '';
if (isset($futureUrls[0])) {
    $parts = parse_url((string) $futureUrls[0]);
    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);
    $futureFilter = (string) ($query['$filter'] ?? '');
}
test_assert(
    'kleine toekomstfetch filtert op resource en datum',
    strpos($futureFilter, "Resource_No eq 'A'") !== false
        && strpos($futureFilter, "Resource_No eq 'B'") !== false
        && strpos($futureFilter, 'Starting_Date ge 2026-09-01') !== false,
    $futureFilter
);
unset($GLOBALS['MEDUSA_GK_ODATA_GET_MANY']);

$bcUrls = [];
$GLOBALS['MEDUSA_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$bcUrls): array {
    $bcUrls[] = $url;
    return [['No' => (string) count($bcUrls)]];
};
$many = odata_get_many([
    "https://bc.example/Production/ODataV4/Company('K')/Urenstaten?\$select=No",
    "https://bc.example/Production/ODataV4/Company('K')/Urenstaatregels?\$select=No",
], ['mode' => 'basic', 'user' => 'u', 'pass' => 'p'], 30, 4);
test_assert(
    'zonder Mímir haalt odata_get_many elke URL in volgorde op',
    count($many) === 2
        && ($many[0][0]['No'] ?? '') === '1'
        && ($many[1][0]['No'] ?? '') === '2'
        && count($bcUrls) === 2,
    json_encode($many)
);
unset($GLOBALS['MEDUSA_ODATA_BC_FETCH']);

test_assert(
    'web-BC-timeout blijft onder max_execution_time',
    odata_bc_timeout_for_url('https://bc.example/short', 'fpm-fcgi') === 90
        && odata_bc_timeout_for_url('https://bc.example/short', 'fpm-fcgi') < 120
);
test_assert(
    'te lange web-URL krijgt een kort BC-plafond',
    odata_bc_timeout_for_url(str_repeat('a', 1801), 'fpm-fcgi') === 15
);
test_assert(
    'CLI houdt de lange timeout, ook bij een lange URL',
    odata_bc_timeout_for_url(str_repeat('a', 1801), 'cli') === 600
);

$parallelPort = 18957;
$parallelLog = sys_get_temp_dir() . '/ploutos-goedkeuren-parallel.log';
$parallelScript = sys_get_temp_dir() . '/ploutos-goedkeuren-parallel.php';
$parallelServerLog = sys_get_temp_dir() . '/ploutos-goedkeuren-parallel-server.log';
@unlink($parallelLog);
file_put_contents($parallelScript, '<?php
$log = ' . var_export($parallelLog, true) . ';
$body = json_decode((string) file_get_contents("php://input"), true);
if (!is_array($body)) {
    $body = [];
}
file_put_contents($log, json_encode($body, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
$filter = (string) ($body["filter"] ?? "");
if (substr_count($filter, " or ") >= 3) {
    http_response_code(400);
    header("Content-Type: application/json");
    echo json_encode(["error" => "filter te groot"]);
    exit;
}
header("Content-Type: application/json");
echo json_encode(["value" => [[
    "company" => (string) ($body["company"] ?? ""),
    "table" => (string) ($body["table"] ?? ""),
    "filter" => $filter,
    "max_age" => $body["max_age"] ?? null,
]]]);
');
$parallelProc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $parallelPort, $parallelScript],
    [
        1 => ['file', $parallelServerLog, 'w'],
        2 => ['file', $parallelServerLog, 'a'],
    ],
    $parallelPipes,
    sys_get_temp_dir()
);
$parallelReady = false;
if (is_resource($parallelProc)) {
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $parallelPort, $errno, $errstr, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            $parallelReady = true;
            break;
        }
        usleep(50000);
    }
}
test_assert('parallel-mock luistert', $parallelReady);

if ($parallelReady) {
    $mimirApi = 'mimir_parallel_test';
    $mimirBase = 'http://127.0.0.1:' . $parallelPort . '/mimir/api';
    odata_mimir_circuit_reset();
    $companyBase = 'http://127.0.0.1:' . $parallelPort . "/Production/ODataV4/Company('Koninklijke%20van%20Twist')/";
    $smallUrls = [];
    foreach (['A', 'B', 'C'] as $resourceNo) {
        $smallUrls[] = gk_odata_entity_url(
            $companyBase,
            'Urenstaten',
            'Resource_No,Starting_Date',
            "Resource_No eq '{$resourceNo}'"
        );
    }
    $parallelGroups = [];
    $parallelError = '';
    try {
        $parallelGroups = odata_get_many($smallUrls, [], 86400, 1);
    } catch (Throwable $error) {
        $parallelError = $error->getMessage();
    }
    test_assert(
        'parallelle Mímir-query geeft elke URL terug',
        $parallelError === ''
            && count($parallelGroups) === 3
            && ($parallelGroups[0][0]['filter'] ?? '') === "Resource_No eq 'A'"
            && ($parallelGroups[1][0]['company'] ?? '') === 'Koninklijke van Twist'
            && ($parallelGroups[1][0]['table'] ?? '') === 'Urenstaten'
            && ($parallelGroups[2][0]['max_age'] ?? null) === 86400
            && !odata_mimir_circuit_open(),
        $parallelError . ' ' . json_encode($parallelGroups)
    );

    $tooWide = gk_odata_entity_url(
        $companyBase,
        'Urenstaatregels',
        'Time_Sheet_No',
        "Time_Sheet_No eq '1' or Time_Sheet_No eq '2' or Time_Sheet_No eq '3' or Time_Sheet_No eq '4'"
    );
    $rejected = null;
    try {
        odata_get_many([$tooWide], [], 30, 1);
    } catch (OdataFilterRejected $error) {
        $rejected = $error;
    } catch (Throwable $error) {
        $parallelError = $error->getMessage();
    }
    test_assert(
        'HTTP 400 blijft een filterfout zonder circuit en zonder BC-hang',
        $rejected instanceof OdataFilterRejected
            && $rejected->failedIndexes === [0]
            && !odata_mimir_circuit_open(),
        ($rejected instanceof OdataFilterRejected ? $rejected->getMessage() : $parallelError)
    );
    odata_mimir_circuit_reset();
}

if (is_resource($parallelProc)) {
    proc_terminate($parallelProc);
    proc_close($parallelProc);
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) gefaald\n");
    exit(1);
}

echo "Alle goedkeuren-load tests geslaagd\n";
