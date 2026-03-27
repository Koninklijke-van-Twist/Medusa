<?php
ob_start();
register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error || ($error['type'] ?? 0) !== E_ERROR) {
        return;
    }

    $message = (string) ($error['message'] ?? '');
    $isTimeout = stripos($message, 'Maximum execution time') !== false
        && stripos($message, '120') !== false
        && stripos($message, 'second') !== false;

    if (!$isTimeout) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $refreshUrl = htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? 'overzicht.php'), ENT_QUOTES, 'UTF-8');
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 5');

    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta http-equiv="refresh" content="5;url=' . $refreshUrl . '">';
    echo '<title>Even geduld</title></head><body style="font-family:Verdana,Geneva,Tahoma,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">';
    echo '<div style="text-align:center;padding:24px">Er is meer tijd nodig om gegevens te laden.<br>De pagina wordt automatisch vernieuwd...</div>';
    echo '<script>setTimeout(function(){location.reload();},5000);</script>';
    echo '</body></html>';
});

require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";
require __DIR__ . "/lib_times.php";
require __DIR__ . "/lib_expenses.php";
require __DIR__ . "/logincheck.php";

$hour = 3600;
$day = $hour * 24;

$month = trim((string) ($_GET['month'] ?? ''));
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$selectedApproverUserId = trim((string) ($_GET['approverUserId'] ?? ''));
$returnPerson = trim((string) ($_GET['returnPerson'] ?? ''));
$expenseDefaults = expenses_defaults();

// Bepaal range
if ($from && $to) {
    // ok
} elseif ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $from = $month . "-01";
    $dt = new DateTimeImmutable($from);
    $to = $dt->modify("last day of this month")->format("Y-m-d");
} else {
    die("Geef month=YYYY-MM of from/to op.");
}

function formatDate(string $dateStr): string
{
    if (!$dateStr)
        return '';
    $dt = DateTime::createFromFormat('Y-m-d', $dateStr);
    if (!$dt)
        return htmlspecialchars($dateStr);
    $months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    return $dt->format('d') . ' ' . $months[$dt->format('n') - 1] . ' ' . $dt->format('Y');
}

function week_starts_for_range(string $from, string $to): array
{
    try {
        $fromDt = new DateTimeImmutable($from);
        $toDt = new DateTimeImmutable($to);
    } catch (Exception $e) {
        return [];
    }

    if ($toDt < $fromDt) {
        return [];
    }

    $starts = [];
    $cursor = $fromDt->modify('monday this week');
    $endWeek = $toDt->modify('monday this week');
    while ($cursor <= $endWeek) {
        $starts[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+7 days');
    }

    return $starts;
}

function week_label_for_ymd(string $dateStr): string
{
    try {
        $dt = new DateTimeImmutable($dateStr);
    } catch (Exception $e) {
        return $dateStr;
    }

    return 'W' . $dt->format('W');
}

function round_to_quarters(float $h): string
{
    if (!is_numeric($h)) {
        return '0.00';
    }

    $value = (float) $h;
    $rounded = round($value * 4) / 4;
    return number_format($rounded, 2, '.', '');
}

function csv_decimal_quarters(float $h): string
{
    return str_replace('.', ',', round_to_quarters($h));
}

function expense_export_columns(): array
{
    return [
        ['key' => 'lunch', 'label' => 'Lunch', 'adp' => 'V407'],
        ['key' => 'dinner', 'label' => 'Diner', 'adp' => 'V408'],
        ['key' => 'coffee', 'label' => 'Koffie', 'adp' => 'V406'],
        ['key' => 'weekend', 'label' => 'Weekend', 'adp' => 'V410'],
        ['key' => 'separation', 'label' => 'Scheiding', 'adp' => 'V11'],
        ['key' => 'foreign', 'label' => 'Buitenland', 'adp' => 'V412'],
        ['key' => 'night', 'label' => 'Nacht', 'adp' => 'V419'],
        ['key' => 'on_call', 'label' => 'Consignatie', 'adp' => 'V409'],
    ];
}

function calculate_time_allowances_for_week(array $week, array $webfleetData): array
{
    $allowance009 = 0; // 18:00-21:00
    $allowance018 = 0; // 21:00-24:00
    $allowance030 = 0; // 00:00-06:00

    if (!$webfleetData) {
        return ['allowance009' => 0, 'allowance018' => 0, 'allowance030' => 0];
    }

    $weekStartRaw = (string) ($week['weekStart'] ?? '');
    if ($weekStartRaw === '') {
        return ['allowance009' => 0, 'allowance018' => 0, 'allowance030' => 0];
    }

    $weekDates = [];
    for ($d = 0; $d < 7; $d++) {
        $weekDates[] = ymd_add_days($weekStartRaw, $d);
    }

    foreach ($webfleetData as $wf) {
        $activityDate = (string) ($wf['KVT_Date_Webfleet_Activity'] ?? '');
        if ($activityDate === '' || !in_array($activityDate, $weekDates, true)) {
            continue;
        }

        $workType = (string) ($wf['Work_Type_Code'] ?? '');
        if ($workType === 'SOT125' || $workType === 'SOT150' || $workType === 'SOT200' || $workType === 'KM') {
            continue;
        }
        if ($workType !== '' && $workType !== 'SNT') {
            continue;
        }

        $startTime = (string) ($wf['KVT_Start_time_Webfleet_Act'] ?? '');
        $endTime = (string) ($wf['KVT_End_time_Webfleet_Act'] ?? '');
        if ($startTime === '' || $endTime === '' || ($startTime === '00:00:00' && $endTime === '00:00:00')) {
            continue;
        }

        $startParts = explode(':', $startTime);
        $endParts = explode(':', $endTime);
        if (count($startParts) < 2 || count($endParts) < 2) {
            continue;
        }

        $startMinutes = ((int) $startParts[0]) * 60 + (int) $startParts[1];
        $endMinutes = ((int) $endParts[0]) * 60 + (int) $endParts[1];
        if ($endMinutes < $startMinutes) {
            $endMinutes += 24 * 60;
        }

        $overlap1 = max(0, min($endMinutes, 21 * 60) - max($startMinutes, 18 * 60));
        $allowance009 += $overlap1;

        $overlap2 = max(0, min($endMinutes, 24 * 60) - max($startMinutes, 21 * 60));
        $allowance018 += $overlap2;

        $overlap3 = 0;
        if ($endMinutes > 24 * 60) {
            $overlap3 = max(0, min($endMinutes, 24 * 60 + 6 * 60) - max($startMinutes, 24 * 60));
        } elseif ($startMinutes < 6 * 60) {
            $overlap3 = max(0, min($endMinutes, 6 * 60) - max($startMinutes, 0));
        }
        $allowance030 += $overlap3;
    }

    return [
        'allowance009' => $allowance009 / 60,
        'allowance018' => $allowance018 / 60,
        'allowance030' => $allowance030 / 60,
    ];
}

// Timesheets die overlappen: Ending_Date ge from AND Starting_Date le to
$filterDecoded = "Ending_Date ge $from and Starting_Date le $to";
$filter = rawurlencode($filterDecoded);
$tsUrl = $base . "Urenstaten?\$select=No,Starting_Date,Ending_Date,Description,Resource_No,Resource_Name&\$filter={$filter}&\$format=json";
$tsRows = odata_get_all($tsUrl, $auth, $day);

if (!$tsRows)
    die("Geen urenstaten in dit tijdvak.");

// Index timesheets op No
$tsByNo = [];
$tsNos = [];
foreach ($tsRows as $t) {
    $no = (string) ($t['No'] ?? '');
    if ($no === '')
        continue;
    $tsByNo[$no] = $t;
    $tsNos[] = $no;
}

if (!$tsNos)
    die("Geen geldige urenstaten in dit tijdvak.");

$expectedWeekStarts = week_starts_for_range($from, $to);

// Helper OR filter
function odata_or_filter(string $field, array $values): string
{
    $parts = array_map(fn($v) => "$field eq '" . str_replace("'", "''", $v) . "'", $values);
    return rawurlencode(implode(" or ", $parts));
}

function odata_fetch_by_or_filter(string $base, string $entity, string $select, string $field, array $values, array $auth, int $ttl, int $chunkSize = 60): array
{
    $values = array_values(array_unique(array_filter(array_map(fn($v) => (string) $v, $values), fn($v) => $v !== '')));
    if (!$values) {
        return [];
    }

    $rows = [];
    foreach (array_chunk($values, $chunkSize) as $chunk) {
        $filter = odata_or_filter($field, $chunk);
        if ($filter === '') {
            continue;
        }
        $url = $base . $entity . "?\$select={$select}&\$filter={$filter}&\$format=json";
        $chunkRows = odata_get_all($url, $auth, $ttl);
        if ($chunkRows) {
            foreach ($chunkRows as $row) {
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

// Lines voor alle timesheets
$lines = odata_fetch_by_or_filter(
    $base,
    'Urenstaatregels',
    'Time_Sheet_No,Status,Header_Resource_No,Work_Type_Code,Job_Task_No,Field1,Field2,Field3,Field4,Field5,Field6,Field7,Total_Quantity',
    'Time_Sheet_No',
    $tsNos,
    $auth,
    $day
);

if (!$lines)
    die("Geen urenstaatregels in dit tijdvak.");

// Codes voor “onkosten” en “verlet” (pas aan aan jouw BC codes)
$CODES_ONKOSTEN = ['ONK', 'ONKOST', 'EXP']; // voorbeeld
$CODES_VERLET = ['VER', 'VERLET'];       // voorbeeld

// Verzamel resources die we nodig hebben
$needRes = [];
foreach ($lines as $l) {
    $rno = (string) ($l['Header_Resource_No'] ?? '');
    if ($rno !== '')
        $needRes[$rno] = true;
}
$needResNos = array_keys($needRes);

// Resource lookup (naam, etc.)
$resourcesByNo = [];
if ($needResNos) {
    foreach (odata_fetch_by_or_filter($base, 'AppResource', 'No,Name,Time_Sheet_Approver_User_ID', 'No', $needResNos, $auth, $day) as $r) {
        $resourcesByNo[(string) $r['No']] = $r;
    }
}

$approverUserIdOptions = [];
foreach ($resourcesByNo as $resource) {
    $approverUserId = trim((string) ($resource['Time_Sheet_Approver_User_ID'] ?? ''));
    if ($approverUserId !== '') {
        $approverUserIdOptions[$approverUserId] = true;
    }
}
if ($selectedApproverUserId !== '') {
    $approverUserIdOptions[$selectedApproverUserId] = true;
}
$approverUserIds = array_keys($approverUserIdOptions);
sort($approverUserIds, SORT_NATURAL | SORT_FLAG_CASE);

// Aggregatie: personNo + timesheetNo (week)
$byPerson = []; // personNo => ['name'=>..., 'weeks'=>[tsNo=>row]]
foreach ($lines as $l) {
    $tsNo = (string) ($l['Time_Sheet_No'] ?? '');
    if ($tsNo === '' || !isset($tsByNo[$tsNo]))
        continue;

    $personNo = (string) ($l['Header_Resource_No'] ?? '');
    if ($personNo === '')
        continue;

    $name = (string) ($resourcesByNo[$personNo]['Name'] ?? $personNo);

    $workType = (string) ($l['Work_Type_Code'] ?? '');
    if ($workType == "KM")
        continue;

    $weekStart = (string) ($tsByNo[$tsNo]['Starting_Date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart))
        continue;

    // Init struct
    if (!isset($byPerson[$personNo])) {
        $byPerson[$personNo] = ['personNo' => $personNo, 'name' => $name, 'weeks' => [], 'webfleet' => []];
    }

    $byPerson[$personNo]['timesheetApproverUserId'] = trim((string) ($resourcesByNo[$personNo]['Time_Sheet_Approver_User_ID'] ?? ''));

    if (!isset($byPerson[$personNo]['weeks'][$tsNo])) {
        // weeknummer uit Description (fallback)
        $desc = (string) ($tsByNo[$tsNo]['Description'] ?? '');
        preg_match('/\bWeek\s*(\d+)\b/i', $desc, $m);
        $weekNo = isset($m[1]) ? (int) $m[1] : 0;

        $byPerson[$personNo]['weeks'][$tsNo] = [
            'tsNo' => $tsNo,
            'weekNo' => $weekNo,
            'weekStart' => $weekStart,
            'p285' => 0,
            'p47' => 0,
            'p85' => 0,
            'onkosten' => 0.0,
            'verlet' => 0.0,
            'expenses' => $expenseDefaults,
            'lines' => 0,
            'dayHours' => [0, 0, 0, 0, 0, 0, 0],
            'hasUnapproved' => false,
            'unapprovedCount' => 0,
            'validationLines' => [],
            'hasHourIssues' => false,
        ];
    }

    $isApproved = ((string) ($l['Status'] ?? '') === 'Approved');
    if (!$isApproved) {
        $byPerson[$personNo]['weeks'][$tsNo]['hasUnapproved'] = true;
        $byPerson[$personNo]['weeks'][$tsNo]['unapprovedCount']++;
        continue;
    }

    // Uren per dag (Field1..7)
    $dayHours = $byPerson[$personNo]['weeks'][$tsNo]['dayHours'];
    $byPerson[$personNo]['weeks'][$tsNo]['validationLines'][] = $l;

    $workType = (string) ($l['Work_Type_Code'] ?? '');

    for ($i = 1; $i <= 7; $i++) {
        if ($workType === 'SOT125')
            $byPerson[$personNo]['weeks'][$tsNo]['p285'] += $l["Field{$i}"] * 60;
        else if ($workType === 'SOT150')
            $byPerson[$personNo]['weeks'][$tsNo]['p47'] += $l["Field{$i}"] * 60;
        else if ($workType === 'SOT200')
            $byPerson[$personNo]['weeks'][$tsNo]['p85'] += $l["Field{$i}"] * 60;
        else
            $dayHours[$i - 1] = ($dayHours[$i - 1] ?? 0) + (float) ($l["Field{$i}"] ?? 0);
    }

    $byPerson[$personNo]['weeks'][$tsNo]['dayHours'] = $dayHours;

    // Onkosten / verlet (voorbeeld: via Work_Type_Code en Total_Quantity als bedrag)
    if (in_array($workType, $CODES_ONKOSTEN, true)) {
        $byPerson[$personNo]['weeks'][$tsNo]['onkosten'] += (float) ($l['Total_Quantity'] ?? 0);
        continue;
    }
    if (in_array($workType, $CODES_VERLET, true)) {
        $byPerson[$personNo]['weeks'][$tsNo]['verlet'] += (float) ($l['Total_Quantity'] ?? 0);
        continue;
    }

    $byPerson[$personNo]['weeks'][$tsNo]['lines']++;
}

// Fetch webfleet data for each person
foreach ($byPerson as $pKey => $person) {
    $allTsNos = array_keys($person['weeks']);
    $webfleetForPerson = [];

    // Collect all Job_Task_No's for this person's timesheets
    $jobTaskNos = [];
    foreach ($lines as $l) {
        $linePersonNo = (string) ($l['Header_Resource_No'] ?? '');
        $lineTsNo = (string) ($l['Time_Sheet_No'] ?? '');
        if ((string) ($l['Status'] ?? '') !== 'Approved') {
            continue;
        }
        if ($linePersonNo === $person['personNo'] && in_array($lineTsNo, $allTsNos)) {
            $jobTaskNo = (string) ($l['Job_Task_No'] ?? '');
            if ($jobTaskNo !== '') {
                $jobTaskNos[$jobTaskNo] = true;
            }
        }
    }
    $jobTaskNos = array_keys($jobTaskNos);

    // Fetch webfleet data for these job tasks
    if (!empty($jobTaskNos)) {
        foreach ($jobTaskNos as $jobTaskNo) {
            $wfFilter = rawurlencode("Job_Task_No eq '" . str_replace("'", "''", $jobTaskNo) . "'");
            $wfUrl = $base . "WebfleetHours?\$select=Job_Task_No,KVT_Date_Webfleet_Activity,KVT_Start_time_Webfleet_Act,KVT_End_time_Webfleet_Act,KVT_Pause,Work_Type_Code,KVT_Calculated_Hours&\$filter={$wfFilter}&\$format=json";
            $wf = (odata_get_all($wfUrl, $auth, $day) ?? []);

            foreach ($wf as $wfLine) {
                // Filter by dates from all timesheets
                foreach ($allTsNos as $tsNo) {
                    if (!isset($tsByNo[$tsNo]))
                        continue;
                    $startDate = $tsByNo[$tsNo]['Starting_Date'];
                    $endDate = $tsByNo[$tsNo]['Ending_Date'];

                    $activityDate = (string) ($wfLine['KVT_Date_Webfleet_Activity'] ?? '');
                    if ($activityDate >= $startDate && $activityDate <= $endDate) {
                        $webfleetForPerson[] = $wfLine;
                        break; // Don't add the same line multiple times
                    }
                }
            }
        }
    }

    $byPerson[$pKey]['webfleet'] = $webfleetForPerson;

    foreach ($person['weeks'] as $tsKey => $ts) {
        // Dagdatums (Ma..Zo) uit weekStart
        $dates = [];
        for ($d = 0; $d < 7; $d++)
            $dates[$d] = ymd_add_days($ts['weekStart'], $d);

        $weekTotal = ($byPerson[$pKey]['weeks'][$tsKey]['p285'] / 60) + ($byPerson[$pKey]['weeks'][$tsKey]['p47'] / 60) + ($byPerson[$pKey]['weeks'][$tsKey]['p85'] / 60);
        // Premies verdelen per dag
        for ($d = 0; $d < 7; $d++) {
            $weekTotal += +$byPerson[$pKey]['weeks'][$tsKey]['dayHours'][$d];
            $split = split_premiums_for_day($byPerson[$pKey]['weeks'][$tsKey]['dayHours'][$d], $dates[$d]);
            $byPerson[$pKey]['weeks'][$tsKey]['p285'] += $split['p285'];
            $byPerson[$pKey]['weeks'][$tsKey]['p47'] += $split['p47'];
            $byPerson[$pKey]['weeks'][$tsKey]['p85'] += $split['p85'];
        }

        $hourIssueData = detect_hour_entry_issues((array) ($byPerson[$pKey]['weeks'][$tsKey]['validationLines'] ?? []));
        $byPerson[$pKey]['weeks'][$tsKey]['hasHourIssues'] = (bool) ($hourIssueData['hasIssues'] ?? false);
        $byPerson[$pKey]['weeks'][$tsKey]['hourIssueData'] = $hourIssueData;
        unset($byPerson[$pKey]['weeks'][$tsKey]['validationLines']);

        $byPerson[$pKey]['weeks'][$tsKey]["weekTotaal"] = $weekTotal;
    }
}

$expenseDb = expenses_db();
$expensePairs = [];
foreach ($byPerson as $personNo => $person) {
    foreach (array_keys($person['weeks']) as $tsNo) {
        $expensePairs[] = ['resource_no' => $personNo, 'ts_no' => $tsNo];
    }
}
$expensesByPair = expenses_get_for_pairs($expenseDb, $expensePairs);
foreach ($byPerson as $personNo => &$person) {
    foreach ($person['weeks'] as $tsNo => &$week) {
        $pairKey = $personNo . '|' . $tsNo;
        $week['expenses'] = $expensesByPair[$pairKey] ?? $expenseDefaults;
        $week['allowances'] = calculate_time_allowances_for_week($week, (array) ($person['webfleet'] ?? []));
    }
    unset($week);
}
unset($person);

foreach ($byPerson as $personNo => &$person) {
    $presentWeekStarts = [];
    foreach ($person['weeks'] as $week) {
        $weekStart = (string) ($week['weekStart'] ?? '');
        if ($weekStart !== '') {
            $presentWeekStarts[$weekStart] = true;
        }
    }

    $missingWeekStarts = [];
    $missingWeekLabels = [];
    foreach ($expectedWeekStarts as $weekStart) {
        if (!isset($presentWeekStarts[$weekStart])) {
            $missingWeekStarts[] = $weekStart;
            $missingWeekLabels[] = week_label_for_ymd($weekStart);
        }
    }

    $person['missingWeekStarts'] = $missingWeekStarts;
    $person['missingWeekLabels'] = $missingWeekLabels;
    $person['missingWeekCount'] = count($missingWeekStarts);
}
unset($person);

if ($selectedApproverUserId !== '') {
    $byPerson = array_filter(
        $byPerson,
        fn($person) => (string) ($person['timesheetApproverUserId'] ?? '') === $selectedApproverUserId
    );
}

// Sortering: personen op naam, weken per persoon op weeknummer desc
usort($byPerson, fn($a, $b) => strcmp($a['name'], $b['name']));
foreach ($byPerson as &$p) {
    $weeks = array_values($p['weeks']);
    usort($weeks, fn($a, $b) => ($b['weekNo'] <=> $a['weekNo']));
    $p['weeks'] = $weeks;
}
unset($p);

if ((string) ($_GET['export'] ?? '') === 'csv') {
    $filenameSuffix = $month !== '' ? $month : ($from . '_' . $to);
    $filenameSuffix = preg_replace('/[^0-9A-Za-z_-]/', '_', (string) $filenameSuffix);
    $filename = 'overzicht_' . $filenameSuffix . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        die('Kon CSV niet genereren.');
    }

    $expenseColumns = expense_export_columns();
    $expenseLabels = array_map(fn($c) => (string) ($c['label'] ?? ''), $expenseColumns);
    $expenseAdpCodes = array_map(fn($c) => (string) ($c['adp'] ?? ''), $expenseColumns);

    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_merge(['personeelsnummer', 'Naam', 'Totaal 28.5%', 'Totaal 47%', 'Totaal 85%', '100% uren', 'meeruren', 'Tsl 18/21', 'Tsl 21/24', 'Tsl 00/06'], $expenseLabels), ';');
    fputcsv($out, array_merge(['ADP Rubriek', '', 'V161', 'V162', 'V163', 'V165', 'V166', 'V174', 'V175', 'V176'], $expenseAdpCodes), ';');

    foreach ($byPerson as $person) {
        $tot285 = 0;
        $tot47 = 0;
        $tot85 = 0;
        $tot009 = 0.0;
        $tot018 = 0.0;
        $tot030 = 0.0;
        $expenseTotals = [];
        foreach ($expenseColumns as $column) {
            $expenseKey = (string) ($column['key'] ?? '');
            if ($expenseKey !== '') {
                $expenseTotals[$expenseKey] = 0;
            }
        }

        foreach ($person['weeks'] as $w) {
            $tot285 += (int) ($w['p285'] ?? 0);
            $tot47 += (int) ($w['p47'] ?? 0);
            $tot85 += (int) ($w['p85'] ?? 0);

            $allowances = (array) ($w['allowances'] ?? ['allowance009' => 0, 'allowance018' => 0, 'allowance030' => 0]);
            $tot009 += (float) ($allowances['allowance009'] ?? 0);
            $tot018 += (float) ($allowances['allowance018'] ?? 0);
            $tot030 += (float) ($allowances['allowance030'] ?? 0);

            $expenses = (array) ($w['expenses'] ?? []);
            $expenses["separation"] = $expenses["separation_gt_eu"] + $expenses["separation_lt_eu"];
            foreach ($expenseColumns as $column) {
                $expenseKey = (string) ($column['key'] ?? '');

                if ($expenseKey === '' || !array_key_exists($expenseKey, $expenseTotals)) {
                    continue;
                }
                $expenseTotals[$expenseKey] += max(0, (int) ($expenses[$expenseKey] ?? 0));
            }
        }

        $expenseValues = [];
        foreach ($expenseColumns as $column) {
            $expenseKey = (string) ($column['key'] ?? '');
            $expenseValues[] = (string) ($expenseTotals[$expenseKey] ?? 0);
        }

        fputcsv($out, [
            (string) ($person['personNo'] ?? ''),
            (string) ($person['name'] ?? ''),
            csv_decimal_quarters($tot285 / 60),
            csv_decimal_quarters($tot47 / 60),
            csv_decimal_quarters($tot85 / 60),
            '',
            '',
            csv_decimal_quarters($tot009),
            csv_decimal_quarters($tot018),
            csv_decimal_quarters($tot030),
            ...$expenseValues,
        ], ';');
    }

    fclose($out);
    exit;
}

function eur(float $v): string
{
    return "€ " . number_format($v, 2, ",", ".");
}
function hhmm(int $min): string
{
    return minutes_to_hhmm($min);
}
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Overzicht</title>
    <style>
        @media print {
            noprint {
                display: none !important;
            }

            .card {
                break-inside: avoid-page;
                page-break-inside: avoid;
            }

            table,
            thead,
            tbody,
            tr {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }

        body {
            margin: 0;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
        }

        h2 {
            background-color: #e4ecf8;
            background-image: url("images/kvtlogo.png");
            background-size: contain;
            background-repeat: no-repeat;
            background-position: right;
            border-radius: 16px;
            padding: 5px;
        }

        .wrap {
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 16px
        }

        table {
            background-color: #fff;
        }

        .card {
            background-color: #e4ecf8;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 18px;
            margin: 16px 0
        }

        h1 {
            margin: 12px 0
        }

        h2 {
            margin: 0 0 10px
        }

        table {
            width: 100%;
            border-collapse: collapse
        }

        th,
        td {
            border-bottom: 1px solid #e2e8f0;
            padding: 8px 10px;
            font-size: 13px;
            vertical-align: top
        }

        th {
            background: #f8fafc;
            text-align: left
        }

        tfoot td {
            font-weight: 800
        }

        .muted {
            color: #64748b
        }

        .person-approver {
            margin: -6px 0 10px;
            font-size: 12px;
            color: #7b8797;
        }

        .person-missing-weeks {
            margin: -6px 0 10px;
            font-size: 12px;
            color: #9a3412;
        }

        .zeroTotal {

            color: #c7d1e0
        }

        .btn {
            display: inline-block;
            padding: 6px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            text-decoration: none;
            color: #0f172a;
            background: #fff
        }

        .warn-indicator {
            margin-left: 6px;
            cursor: help;
            font-size: 14px;
            vertical-align: middle;
        }

        .hour-issue-indicator {
            display: inline-block;
            min-width: 1.2em;
            text-align: center;
            color: #b91c1c;
            font-weight: 700;
        }

        .right {
            text-align: right
        }

        .kvt_logo_big {
            max-width: 200px;
        }

        /* Print modal styles */
        .print-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            font-size: small;
            padding: 12px;
            box-sizing: border-box;
        }

        .print-modal.active {
            display: flex;
        }

        .print-modal-content {
            background: white;
            width: min(96vw, 297mm);
            max-height: 96vh;
            padding: 12mm;
            overflow: auto;
            position: relative;
            box-sizing: border-box;
        }

        #salarySlipContent {
            width: 100%;
            overflow-x: auto;
        }

        .print-close-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #333;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            z-index: 1001;
        }

        .print-close-btn:hover {
            background: #555;
        }

        /* Salary slip styles */
        .kvt-banner {
            background: #003da5;
            color: white;
            padding: 15px;
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .salary-slip-header {
            justify-content: space-between;
            margin-bottom: 20px;
            border-bottom: 2px solid #003da5;
            padding-bottom: 15px;
        }

        .salary-slip-header h1 {
            margin: 0;
            display: block;
            font-size: 28px;
            color: #003da5;
        }

        .salary-slip-header .logo {
            width: 200px;
            height: 80px;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            font-size: 12px;
            text-align: center;
            color: #999;
        }

        .employee-info {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 30px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .employee-info-block {
            display: flex;
            flex-direction: column;
        }

        .employee-info-label {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .salary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 12px;
        }

        .salary-table th,
        .salary-table td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: right;
        }

        .salary-table th {
            background: #f0f0f0;
            text-align: center;
            font-weight: bold;
        }

        .salary-table td:first-child,
        .salary-table th:first-child {
            text-align: left;
        }

        .salary-table tr.section-header {
            background: #d4e4f7;
            font-weight: bold;
        }

        .salary-table tr.section-header td {
            border: none;
            border-bottom: 1px solid #999;
        }

        .salary-table tr.section-close {
            background: #1a3a7a;
            border-bottom: 3px solid #1a3a7a;
        }

        .salary-table tr.section-close td {
            border: none;
        }

        .kvt_banner {
            background: #1a3a7a;
            width: 100%;
            color: white;
            font-size: 2pc;
            padding: 5px;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
        }

        .bottomAlign {
            position: relative;
            background-color: #fff;
            width: 250px;
        }

        .bottomAlign div {
            position: absolute;
            bottom: 0;
            left: 50px;
            width: 200px;
            right: 200px;
            height: 200px;
        }

        .page-loader {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1500;
        }

        .page-loader.active {
            display: flex;
        }

        .page-loader-box {
            background: #fff;
            border-radius: 12px;
            padding: 16px 20px;
            font-weight: 700;
            color: #0f172a;
        }

        @media (max-width: 900px) {
            .print-modal-content {
                width: 100%;
                max-height: 100%;
                padding: 8mm;
            }

            .salary-table {
                font-size: 11px;
            }
        }

        @media print {
            .print-modal {
                display: none !important;
            }

            .print-modal-content {
                width: 100%;
                max-height: none;
                padding: 0;
                overflow: visible;
            }

            #salarySlipContent {
                overflow: visible;
            }

            body.salary-slip-open .wrap {
                display: none !important;
            }

            body.salary-slip-open .print-modal {
                position: static;
                width: auto;
                height: auto;
                background: none;
                padding: 0;
                display: block !important;
            }
        }
    </style>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
</head>

<body>
    <?php $expenseColumns = expense_export_columns(); ?>
    <div class="wrap">
        <noprint>
            <?= injectTimerHtml([
                'statusUrl' => 'odata.php?action=cache_status',
                'title' => 'Cachebestanden',
                'label' => 'Cache',
            ]) ?>
            <a class="btn js-nav-loading" href="index.php">← Terug naar selectie</a>
            <a class="btn" href="feestdagen.php">Beheer Feestdagen</a>
            <?php
            $exportUrl = 'overzicht.php?export=csv';
            if ($from !== '') {
                $exportUrl .= '&from=' . rawurlencode($from);
            }
            if ($to !== '') {
                $exportUrl .= '&to=' . rawurlencode($to);
            }
            if ($month !== '') {
                $exportUrl .= '&month=' . rawurlencode($month);
            }
            if ($selectedApproverUserId !== '') {
                $exportUrl .= '&approverUserId=' . rawurlencode($selectedApproverUserId);
            }
            ?>
            <a class="btn" href="<?= htmlspecialchars($exportUrl) ?>">Export totalen als CSV</a>
            <form id="approverFilterForm" method="get" style="display:inline-block; margin-left:8px;">
                <?php if ($from !== ''): ?>
                    <input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>">
                <?php endif; ?>
                <?php if ($to !== ''): ?>
                    <input type="hidden" name="to" value="<?= htmlspecialchars($to) ?>">
                <?php endif; ?>
                <?php if ($month !== ''): ?>
                    <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
                <?php endif; ?>
                <label for="approverUserId" class="muted" style="margin-left:6px;">Approver:</label>
                <select id="approverUserId" name="approverUserId" class="btn" style="padding-right:26px;">
                    <option value="">alle</option>
                    <?php foreach ($approverUserIds as $approverUserId): ?>
                        <option value="<?= htmlspecialchars($approverUserId) ?>"
                            <?= $selectedApproverUserId === $approverUserId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($approverUserId) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </noprint>
        <h1>Overzicht <?= formatDate(htmlspecialchars($from)) ?> t/m <?= formatDate(htmlspecialchars($to)) ?></h1>
        <noprint>
            <div class="muted">Klik op een week om details te bekijken.</div>
        </noprint>

        <?php foreach ($byPerson as $person): ?>
            <?php $personAnchorId = 'person-' . rawurlencode((string) ($person['personNo'] ?? '')); ?>
            <div class="card" id="<?= htmlspecialchars($personAnchorId) ?>"
                data-person-no="<?= htmlspecialchars((string) ($person['personNo'] ?? '')) ?>">
                <h2><?= htmlspecialchars($person['name']) ?></h2>
                <?php if ((string) ($person['timesheetApproverUserId'] ?? '') !== ''): ?>
                    <div class="person-approver">
                        &nbsp;<?= htmlspecialchars((string) $person['timesheetApproverUserId']) ?></div>
                <?php endif; ?>
                <?php if ((int) ($person['missingWeekCount'] ?? 0) > 0): ?>
                    <?php
                    $missingLabels = (array) ($person['missingWeekLabels'] ?? []);
                    $visibleMissingLabels = array_slice($missingLabels, 0, 8);
                    $hasMoreMissing = count($missingLabels) > count($visibleMissingLabels);
                    $missingWeekDates = (array) ($person['missingWeekStarts'] ?? []);
                    ?>
                    <div class="person-missing-weeks"
                        title="Ontbrekende weken: <?= htmlspecialchars(implode(', ', $missingWeekDates)) ?>">
                        Ontbrekende urenstaten:
                        <?= htmlspecialchars(implode(', ', $visibleMissingLabels)) ?>        <?= $hasMoreMissing ? ', ...' : '' ?>
                    </div>
                <?php endif; ?>
                <noprint>
                    <button class="btn"
                        onclick="openPrintModal(event, <?= htmlspecialchars(json_encode($person), ENT_QUOTES) ?>)">Toon
                        Salarisspecificatie</button>
                    <?php
                    $expensesEditorUrl = "onkosten_editor.php?resourceNo=" . rawurlencode($person['personNo'])
                        . "&from=" . rawurlencode($from)
                        . "&to=" . rawurlencode($to)
                        . ($month !== '' ? "&month=" . rawurlencode($month) : '')
                        . ($selectedApproverUserId !== '' ? "&approverUserId=" . rawurlencode($selectedApproverUserId) : '')
                        . "&returnPerson=" . rawurlencode((string) ($person['personNo'] ?? ''))
                        . "&returnPage=overzicht";
                    ?>
                    <a href="<?= htmlspecialchars($expensesEditorUrl) ?>"><button class="btn">Onkosten Invoeren</button></a>
                </noprint>
                <table>
                    <thead>
                        <tr>
                            <th>Overuren</th>
                            <th>Weeknummer</th>
                            <th>#</th>
                            <th>Gewerkt</th>
                            <th class="right">28.5%</th>
                            <th></th>
                            <th class="right">47%</th>
                            <th></th>
                            <th class="right">85%</th>
                            <th></th>
                            <th class="right">Onkosten</th>
                            <th class="right">Verlet</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $i = 0;
                        $tot285 = 0;
                        $tot47 = 0;
                        $tot85 = 0;
                        $totOnk = 0.0;
                        $totVer = 0.0;
                        ?>
                        <?php foreach ($person['weeks'] as $w): ?>
                            <?php
                            $i++;
                            $tot285 += (int) $w['p285'];
                            $tot47 += (int) $w['p47'];
                            $tot85 += (int) $w['p85'];
                            $totOnk += (float) $w['onkosten'];
                            $totVer += (float) $w['verlet'];

                            $inspectUrl = "weekinspectie.php?tsNo=" . rawurlencode($w['tsNo'])
                                . "&resourceNo=" . rawurlencode($person['personNo'])
                                . "&from=" . rawurlencode($from)
                                . "&to=" . rawurlencode($to)
                                . ($month !== '' ? "&month=" . rawurlencode($month) : '')
                                . ($selectedApproverUserId !== '' ? "&approverUserId=" . rawurlencode($selectedApproverUserId) : '')
                                . "&returnPerson=" . rawurlencode((string) ($person['personNo'] ?? ''));
                            ?>
                            <tr>
                                <td>
                                    <a class="btn js-nav-loading" href="<?= htmlspecialchars($inspectUrl) ?>">
                                        <noprint>Bekijk <?= $w['lines'] ?></noprint>&nbsp;
                                    </a>
                                    <?php if (!empty($w['hasUnapproved'])): ?>
                                        <span class="warn-indicator"
                                            title="Deze week bevat niet-goedgekeurde regels.&#10;Deze regels worden niet meegeteld in het overzicht.">⚠️
                                            <noprint><?= (int) ($w['unapprovedCount'] ?? 0) ?>
                                        </span></noprint>
                                    <?php endif; ?>
                                    <?php if (!empty($w['hasHourIssues'])): ?>
                                        <span class="warn-indicator hour-issue-indicator"
                                            title="Onjuist ingevulde uren gedetecteerd!"
                                            aria-label="Onjuist ingevulde uren gedetecteerd!">‼️</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) $w['weekNo'] ?> <span
                                        class="muted">(<?= formatDate(htmlspecialchars($w['weekStart'])) ?>)</span></td>
                                <td><?= $i ?></td>

                                <td <?= hhmm($w['weekTotaal'] * 60) == "0:00" ? "class=\"zeroTotal\"" : "" ?>>
                                    <?= round_to_quarters($w['weekTotaal']) ?>
                                </td>
                                <td class="right <?= $w['p285'] == 0 ? "zeroTotal" : "" ?>">
                                    <?= htmlspecialchars(round_to_quarters((int) $w['p285'] / 60)) ?>
                                </td>
                                <td></td>
                                <td class="right <?= $w['p47'] == 0 ? "zeroTotal" : "" ?>">
                                    <?= htmlspecialchars(round_to_quarters((int) $w['p47'] / 60)) ?>
                                </td>
                                <td></td>
                                <td class="right <?= $w['p85'] == 0 ? "zeroTotal" : "" ?>">
                                    <?= htmlspecialchars(round_to_quarters((int) $w['p85'] / 60)) ?>
                                </td>
                                <td></td>
                                <td class="right <?= $w['onkosten'] == 0 ? "zeroTotal" : "" ?>">
                                    <?= htmlspecialchars(eur((float) $w['onkosten'])) ?>
                                </td>
                                <td class="right <?= $w['verlet'] == 0 ? "zeroTotal" : "" ?>">
                                    <?= htmlspecialchars(eur((float) $w['verlet'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4">Totalen</td>
                            <td class="right <?= $tot285 == 0 ? "zeroTotal" : "" ?>">
                                <?= htmlspecialchars(round_to_quarters($tot285 / 60)) ?>
                            </td>
                            <td></td>
                            <td class="right <?= $tot47 == 0 ? "zeroTotal" : "" ?>">
                                <?= htmlspecialchars(round_to_quarters($tot47 / 60)) ?>
                            </td>
                            <td></td>
                            <td class="right <?= $tot85 == 0 ? "zeroTotal" : "" ?>">
                                <?= htmlspecialchars(round_to_quarters($tot85 / 60)) ?>
                            </td>
                            <td></td>
                            <td class="right <?= $totOnk == 0 ? "zeroTotal" : "" ?>"><?= htmlspecialchars(eur($totOnk)) ?>
                            </td>
                            <td class="right <?= $totVer == 0 ? "zeroTotal" : "" ?>"><?= htmlspecialchars(eur($totVer)) ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>

                <table style="margin-top:12px;">
                    <thead>
                        <tr>
                            <th>Onkosten</th>
                            <th>Weeknummer</th>
                            <th>#</th>
                            <?php foreach ($expenseColumns as $column): ?>
                                <th class="right"><?= htmlspecialchars((string) ($column['label'] ?? '')) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $j = 0;
                        $expenseTotalsWeekTable = [];
                        foreach ($expenseColumns as $column) {
                            $expenseKey = (string) ($column['key'] ?? '');
                            if ($expenseKey !== '') {
                                $expenseTotalsWeekTable[$expenseKey] = 0;
                            }
                        }
                        ?>
                        <?php foreach ($person['weeks'] as $w): ?>
                            <?php
                            $j++;
                            $weekExpenses = (array) ($w['expenses'] ?? []);
                            $weekExpenseValues = [];
                            foreach ($expenseColumns as $column) {
                                $expenseKey = (string) ($column['key'] ?? '');
                                if ($expenseKey === '') {
                                    continue;
                                }
                                $value = max(0, (int) ($weekExpenses[$expenseKey] ?? 0));
                                $weekExpenseValues[$expenseKey] = $value;
                                $expenseTotalsWeekTable[$expenseKey] += $value;
                            }

                            $weekEditExpensesUrl = "onkosten_editor.php?resourceNo=" . rawurlencode($person['personNo'])
                                . "&from=" . rawurlencode($from)
                                . "&to=" . rawurlencode($to)
                                . ($month !== '' ? "&month=" . rawurlencode($month) : '')
                                . ($selectedApproverUserId !== '' ? "&approverUserId=" . rawurlencode($selectedApproverUserId) : '')
                                . "&returnPerson=" . rawurlencode((string) ($person['personNo'] ?? ''))
                                . "&returnPage=overzicht"
                                . "&returnTsNo=" . rawurlencode((string) ($w['tsNo'] ?? ''));
                            ?>
                            <tr>
                                <td>
                                    <a class="btn" href="<?= htmlspecialchars($weekEditExpensesUrl) ?>">
                                        <noprint>Bewerk</noprint>&nbsp;
                                    </a>
                                </td>
                                <td><?= (int) $w['weekNo'] ?> <span
                                        class="muted">(<?= formatDate(htmlspecialchars($w['weekStart'])) ?>)</span></td>
                                <td><?= $j ?></td>
                                <?php foreach ($expenseColumns as $column): ?>
                                    <?php
                                    $expenseKey = (string) ($column['key'] ?? '');
                                    $value = (int) ($weekExpenseValues[$expenseKey] ?? 0);
                                    ?>
                                    <td class="right <?= $value === 0 ? 'zeroTotal' : '' ?>"><?= $value ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3">Totalen</td>
                            <?php foreach ($expenseColumns as $column): ?>
                                <?php
                                $expenseKey = (string) ($column['key'] ?? '');
                                $total = (int) ($expenseTotalsWeekTable[$expenseKey] ?? 0);
                                ?>
                                <td class="right <?= $total === 0 ? 'zeroTotal' : '' ?>"><?= $total ?></td>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endforeach; ?>
    </div>

    <div id="pageLoader" class="page-loader" aria-live="polite" aria-busy="true">
        <div class="page-loader-box">Bezig met laden…</div>
    </div>

    <!-- Print Modal -->
    <div id="printModal" class="print-modal">
        <div class="print-modal-content">
            <noprint>
                <button class="print-close-btn" onclick="window.print()" style="right: 70px;">Afdrukken</button>
                <button class="print-close-btn" onclick="closePrintModal()">Sluiten</button>
            </noprint>
            <div id="salarySlipContent"></div>
        </div>
    </div>

    <script>
        function showPageLoader ()
        {
            document.getElementById('pageLoader')?.classList.add('active');
        }

        function hidePageLoader ()
        {
            document.getElementById('pageLoader')?.classList.remove('active');
        }

        document.querySelectorAll('a.js-nav-loading').forEach(function (link)
        {
            link.addEventListener('click', function (event)
            {
                if (event.defaultPrevented)
                {
                    return;
                }
                if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)
                {
                    return;
                }
                showPageLoader();
            });
        });

        window.addEventListener('pageshow', function ()
        {
            hidePageLoader();
        });

        const approverSelect = document.getElementById('approverUserId');
        const approverForm = document.getElementById('approverFilterForm');
        if (approverSelect && approverForm)
        {
            approverSelect.addEventListener('change', function ()
            {
                showPageLoader();
                approverForm.submit();
            });
        }

        (function restorePersonScroll ()
        {
            const params = new URLSearchParams(window.location.search);
            const returnPerson = params.get('returnPerson');
            if (!returnPerson)
            {
                return;
            }
            const anchorId = `person-${encodeURIComponent(returnPerson)}`;
            const target = document.getElementById(anchorId);
            if (!target)
            {
                return;
            }
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        })();

        (function animateHourIssueIndicators ()
        {
            const indicators = Array.from(document.querySelectorAll('.hour-issue-indicator'));
            if (!indicators.length)
            {
                return;
            }

            const cycleMs = 2000;
            const render = function ()
            {
                const t = Date.now() % cycleMs;
                if (t < 1000)
                {
                    indicators.forEach(function (el)
                    {
                        el.textContent = '‼️';
                    });
                }
                else
                {
                    indicators.forEach(function (el)
                    {
                        el.textContent = '⁉️';
                    });
                }
            };

            render();
            window.setInterval(render, 120);
        })();

        function round_to_quarters (h)
        {
            const value = Number(h);
            if (!Number.isFinite(value))
            {
                return '0.00';
            }
            return (Math.round(value * 4) / 4).toFixed(2);
        }
        function hours_to_minutes (h)
        {
            return Math.round(h * 60);
        }
        function hhmm (h)
        {
            return minutes_to_hhmm(h * 60);
        }
        function minutes_to_hhmm (min)
        {
            const sign = min < 0 ? "-" : "";
            min = Math.abs(min);
            const h = Math.floor(min / 60);
            const m = Math.round(min % 60);
            return `${sign}${h}:${String(m).padStart(2, '0')}`;
        }
        function openPrintModal (event, person)
        {
            event.preventDefault();
            const modal = document.getElementById('printModal');
            const content = document.getElementById('salarySlipContent');

            // Build weeks columns
            let weeksHtml = '';
            let totalWeeks = person.weeks.length;
            person.weeks.forEach(week =>
            {
                const weekYear = week.weekStart.substring(0, 4);
                const weekDate = new Date(week.weekStart);
                const weekNum = Math.ceil((weekDate.getDate() + new Date(weekDate.getFullYear(), weekDate.getMonth(), 1).getDay()) / 7);
                weeksHtml += `<th>${weekYear}${String(weekNum).padStart(2, '0')}</th>`;
            });
            weeksHtml += '<th>Totaal</th>';

            // Calculate totals for each category
            let total285 = 0, total47 = 0, total85 = 0;
            person.weeks.forEach(week =>
            {
                total285 += (week.p285 || 0) / 60;
                total47 += (week.p47 || 0) / 60;
                total85 += (week.p85 || 0) / 60;
            });

            // Build hours per week rows
            let hours285Cells = '';
            let hours47Cells = '';
            let hours85Cells = '';
            let allowance009Cells = '';
            let allowance018Cells = '';
            let allowance030Cells = '';
            let total009 = 0, total018 = 0, total030 = 0;
            const expenseRows = {
                coffee: { label: 'Koffievergoeding', cells: '', total: 0 },
                lunch: { label: 'Lunchvergoeding', cells: '', total: 0 },
                dinner: { label: 'Dinervergoeding', cells: '', total: 0 },
                separation_lt_eu: { label: 'Scheidingsvergoeding <EU', cells: '', total: 0 },
                separation_gt_eu: { label: 'Scheidingsvergoeding >EU', cells: '', total: 0 },
                weekend: { label: 'Weekendtoeslag', cells: '', total: 0 },
                on_call: { label: 'Consignatiedienst', cells: '', total: 0 },
                night: { label: 'Nachttoeslag', cells: '', total: 0 }
            };
            person.weeks.forEach(week =>
            {
                const h285 = ((week.p285 || 0) / 60).toFixed(2);
                const h47 = ((week.p47 || 0) / 60).toFixed(2);
                const h85 = ((week.p85 || 0) / 60).toFixed(2);
                hours285Cells += `<td>${h285 > 0 ? round_to_quarters(h285) : ''}</td>`;
                hours47Cells += `<td>${h47 > 0 ? round_to_quarters(h47) : ''}</td>`;
                hours85Cells += `<td>${h85 > 0 ? round_to_quarters(h85) : ''}</td>`;

                // Use centrally calculated time-based allowances
                const allowances = week.allowances || { allowance009: 0, allowance018: 0, allowance030: 0 };
                total009 += Number(allowances.allowance009 || 0);
                total018 += Number(allowances.allowance018 || 0);
                total030 += Number(allowances.allowance030 || 0);

                const weekExpenses = week.expenses || {};
                Object.keys(expenseRows).forEach(key =>
                {
                    const value = Math.max(0, Math.round(Number(weekExpenses[key] || 0)));
                    expenseRows[key].total += value;
                    expenseRows[key].cells += `<td>${value > 0 ? value : ''}</td>`;
                });

                allowance009Cells += `<td>${allowances.allowance009 > 0 ? round_to_quarters(allowances.allowance009) : ''}</td>`;
                allowance018Cells += `<td>${allowances.allowance018 > 0 ? round_to_quarters(allowances.allowance018) : ''}</td>`;
                allowance030Cells += `<td>${allowances.allowance030 > 0 ? round_to_quarters(allowances.allowance030) : ''}</td>`;
            });
            hours285Cells += `<td><strong>${round_to_quarters(total285.toFixed(2))}</strong></td>`;
            hours47Cells += `<td><strong>${round_to_quarters(total47.toFixed(2))}</strong></td>`;
            hours85Cells += `<td><strong>${round_to_quarters(total85.toFixed(2))}</strong></td>`;
            allowance009Cells += `<td><strong>${total009 > 0 ? round_to_quarters(total009) : ''}</strong></td>`;
            allowance018Cells += `<td><strong>${total018 > 0 ? round_to_quarters(total018) : ''}</strong></td>`;
            allowance030Cells += `<td><strong>${total030 > 0 ? round_to_quarters(total030) : ''}</strong></td>`;
            const reimbursementRowsHtml = Object.values(expenseRows)
                .map(row => `<tr><td>${row.label}</td>${row.cells}<td><strong>${row.total > 0 ? row.total : ''}</strong></td></tr>`)
                .join('');

            // Build salary slip HTML
            const salarySlip = `
                <div class="salary-slip-header">
                    <table>
                        <tr>
                        <td style="padding:0px;">
                        <div class="kvt_banner">Koninklijke van Twist B.V.</div><br/>
                        <h1>&nbsp;Salarisspecificatie</h1>
                        </td><td class="bottomAlign">
                        <div class="logo"><img class="kvt_logo_big" src="images/kvtlogo_l.png"/></div>
                        </td>
                        </tr>
                    </table>
                </div>

                <div class="employee-info">
                    <div class="employee-info-block">
                        <div class="employee-info-label">Werknemer:</div>
                        <div>Salarisstrook</div>
                    </div>
                    <div class="employee-info-block">
                        <div class="employee-info-label">${htmlspecialchars(person.name)}</div>
                        <div>202601</div>
                    </div>
                    <div class="employee-info-block">
                        <div class="employee-info-label"><!--email--></div>
                        <div></div>
                    </div>
                </div>

                <table class="salary-table">
                    <thead>
                        <tr>
                            <th>Uren</th>
                            ${weeksHtml}
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="section-header">
                            <td colspan="${totalWeeks + 2}">Overwerk</td>
                        </tr>
                        <tr>
                            <td>28.5%: ma/vr eerste 2 overuren</td>
                            ${hours285Cells}
                        </tr>
                        <tr>
                            <td>47%: ma/vr overige uren en za</td>
                            ${hours47Cells}
                        </tr>
                        <tr>
                            <td>85%: zon- en feestdagen</td>
                            ${hours85Cells}
                        </tr>
                        <tr class="section-header">
                            <td colspan="${totalWeeks + 2}">Toeslag - buiten dagvenster</td>
                        </tr>
                        <tr>
                            <td>0,09%: 18:00 - 21:00 uur</td>
                            ${allowance009Cells}
                        </tr>
                        <tr>
                            <td>0,18%: 21:00 - 24:00</td>
                            ${allowance018Cells}
                        </tr>
                        <tr>
                            <td>0,30%: 00:00 - 06:00</td>
                            ${allowance030Cells}
                        </tr>
                        <tr class="section-close">
                            <td colspan="${totalWeeks + 2}"></td>
                        </tr>
                    </tbody>
                </table>

                <table class="salary-table">
                    <thead>
                        <tr>
                            <th>Vergoedingen</th>
                            ${weeksHtml}
                        </tr>
                    </thead>
                    <tbody>
                        ${reimbursementRowsHtml}
                        <tr class="section-close">
                            <td colspan="${totalWeeks + 2}"></td>
                        </tr>
                    </tbody>
                </table>

                <!--<table class="salary-table">
                    <tbody>
                        <tr class="section-header">
                            <td colspan="${totalWeeks + 2}">Diversen</td>
                        </tr>
                        <tr>
                            <td></td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr>
                            <td></td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr class="section-header">
                            <td colspan="${totalWeeks + 2}">Inhoudingen</td>
                        </tr>
                        <tr>
                            <td></td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr>
                            <td></td>
                            ${'<td></td>'.repeat(totalWeeks + 1)}
                        </tr>
                        <tr class="section-close">
                            <td colspan="${totalWeeks + 2}"></td>
                        </tr>
                    </tbody>
                </table>-->
            `;

            content.innerHTML = salarySlip;
            modal.classList.add('active');
            document.body.classList.add('salary-slip-open');
            document.body.style.overflow = 'hidden';
        }

        function closePrintModal ()
        {
            const modal = document.getElementById('printModal');
            modal.classList.remove('active');
            document.body.classList.remove('salary-slip-open');
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        document.getElementById('printModal')?.addEventListener('click', function (event)
        {
            if (event.target === this)
            {
                closePrintModal();
            }
        });

        function htmlspecialchars (str)
        {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    </script>
</body>

</html>