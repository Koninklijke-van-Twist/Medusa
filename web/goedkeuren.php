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
    $refreshUrl = htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? 'goedkeuren.php'), ENT_QUOTES, 'UTF-8');
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
require __DIR__ . "/logincheck.php";
require_once __DIR__ . "/loadingscreen.php";

$day = 3600 * 24;
$selfPath = basename((string) ($_SERVER['PHP_SELF'] ?? 'goedkeuren.php'));

// =========================================================
// AJAX: markeer vakantie
// =========================================================
if (trim((string) ($_GET['action'] ?? '')) === 'markeer_vakantie') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Alleen POST toegestaan']);
        exit;
    }
    $resourceNo = trim((string) ($_POST['resourceNo'] ?? ''));
    $weekStart = trim((string) ($_POST['weekStart'] ?? ''));
    if ($resourceNo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldige invoer']);
        exit;
    }
    $vakantieDir = __DIR__ . '/cache/vakantie';
    if (!is_dir($vakantieDir)) {
        mkdir($vakantieDir, 0777, true);
    }
    $key = hash('sha256', $resourceNo . '|' . $weekStart);
    $file = $vakantieDir . '/' . $key . '.json';
    $data = ['resourceNo' => $resourceNo, 'weekStart' => $weekStart, 'markedAt' => date('Y-m-d H:i:s')];
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    echo json_encode(['success' => true]);
    exit;
}

// =========================================================
// DATUMBEREIK
// =========================================================
$today = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-1 month'));
$recentActivityFrom = $defaultFrom;

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = $defaultFrom;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = $today;
}

$selectedApproverUserId = trim((string) ($_GET['approverUserId'] ?? ''));

// =========================================================
// HELPERS
// =========================================================
function gk_odata_or_filter(string $field, array $values): string
{
    $parts = array_map(
        fn($value) => $field . " eq '" . str_replace("'", "''", (string) $value) . "'",
        $values
    );

    return rawurlencode(implode(' or ', $parts));
}

function gk_odata_fetch_by_or_filter(
    string $base,
    string $entity,
    string $select,
    string $field,
    array $values,
    array $auth,
    int $ttl,
    int $chunkSize = 60
): array {
    $values = array_values(array_unique(array_filter(array_map(
        fn($value) => (string) $value,
        $values
    ), fn($value) => $value !== '')));

    if (!$values) {
        return [];
    }

    $rows = [];
    foreach (array_chunk($values, $chunkSize) as $chunk) {
        $filter = gk_odata_or_filter($field, $chunk);
        if ($filter === '') {
            continue;
        }

        $url = $base . $entity . "?\$select={$select}&\$filter={$filter}&\$format=json";
        $chunkRows = odata_get_all($url, $auth, $ttl);
        if (!$chunkRows) {
            continue;
        }

        foreach ($chunkRows as $row) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function gk_formatDate(string $ymd): string
{
    if ($ymd === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    if (!$dt) {
        return htmlspecialchars($ymd);
    }
    $months = [
        'januari',
        'februari',
        'maart',
        'april',
        'mei',
        'juni',
        'juli',
        'augustus',
        'september',
        'oktober',
        'november',
        'december'
    ];
    return $dt->format('j') . ' ' . $months[$dt->format('n') - 1] . ' ' . $dt->format('Y');
}

function gk_hhmm(float $hours): string
{
    return minutes_to_hhmm((int) round($hours * 60));
}

function gk_status_class(string $status): string
{
    return match ($status) {
        'Open' => 'openStatus',
        'Submitted' => 'submittedStatus',
        'Rejected' => 'rejectedStatus',
        'Approved' => 'approvedStatus',
        default => '',
    };
}

function gk_is_vakantie(string $resourceNo, string $weekStart): bool
{
    $key = hash('sha256', $resourceNo . '|' . $weekStart);
    return is_file(__DIR__ . '/cache/vakantie/' . $key . '.json');
}

// =========================================================
// OPHALEN: ALLE RESOURCES (voor goedkeurder-dropdown)
// =========================================================
$allResourcesUrl = $base . "AppResource?\$select=No,Name,Time_Sheet_Approver_User_ID&\$format=json";
$allResources = odata_get_all($allResourcesUrl, $auth, $day);

$approverUserIds = [];
foreach ($allResources as $r) {
    $auid = trim((string) ($r['Time_Sheet_Approver_User_ID'] ?? ''));
    if ($auid !== '') {
        $approverUserIds[$auid] = true;
    }
}
$approverUserIds = array_keys($approverUserIds);
sort($approverUserIds, SORT_NATURAL | SORT_FLAG_CASE);

// =========================================================
// AUTO-SELECT OP BASIS VAN SESSION-EMAIL
// =========================================================
if ($selectedApproverUserId === '') {
    $sessionEmail = (string) ($_SESSION['user']['email'] ?? '');
    if ($sessionEmail !== '') {
        $emailPrefix = strtoupper(explode('@', $sessionEmail)[0]);
        foreach ($approverUserIds as $auid) {
            if (strtoupper($auid) === $emailPrefix) {
                $selectedApproverUserId = $auid;
                break;
            }
        }
    }
}

// =========================================================
// RESOURCES VOOR GESELECTEERDE GOEDKEURDER
// =========================================================
$resourcesForApprover = []; // resourceNo => naam
if ($selectedApproverUserId !== '') {
    foreach ($allResources as $r) {
        $auid = trim((string) ($r['Time_Sheet_Approver_User_ID'] ?? ''));
        $no = trim((string) ($r['No'] ?? ''));
        if ($auid === $selectedApproverUserId && $no !== '') {
            $resourcesForApprover[$no] = (string) ($r['Name'] ?? $no);
        }
    }
}

if ($resourcesForApprover) {
    $recentFilterDecoded = "Ending_Date ge $recentActivityFrom and Starting_Date le $today";
    $recentTsUrl = $base . "Urenstaten?\$select=No,Starting_Date,Ending_Date,Resource_No"
        . "&\$filter=" . rawurlencode($recentFilterDecoded) . "&\$format=json";
    $recentTsRows = odata_get_all($recentTsUrl, $auth, $day);

    $recentTsByNo = [];
    $recentTsNos = [];
    foreach ($recentTsRows as $row) {
        $tsNo = trim((string) ($row['No'] ?? ''));
        if ($tsNo === '') {
            continue;
        }
        $recentTsByNo[$tsNo] = $row;
        $recentTsNos[] = $tsNo;
    }

    $recentResourceNos = [];
    if ($recentTsNos) {
        $recentLines = gk_odata_fetch_by_or_filter(
            $base,
            'Urenstaatregels',
            'Time_Sheet_No,Header_Resource_No',
            'Time_Sheet_No',
            $recentTsNos,
            $auth,
            $day
        );

        foreach ($recentLines as $line) {
            $resourceNo = trim((string) ($line['Header_Resource_No'] ?? ''));
            if ($resourceNo !== '' && isset($resourcesForApprover[$resourceNo])) {
                $recentResourceNos[$resourceNo] = true;
            }
        }
    }

    foreach ($recentTsByNo as $row) {
        $resourceNo = trim((string) ($row['Resource_No'] ?? ''));
        if ($resourceNo !== '' && isset($resourcesForApprover[$resourceNo])) {
            $recentResourceNos[$resourceNo] = true;
        }
    }

    if ($recentResourceNos) {
        $resourcesForApprover = array_intersect_key($resourcesForApprover, $recentResourceNos);
    } else {
        $resourcesForApprover = [];
    }
}

// =========================================================
// OPBOUWEN WEEKSTRUCTUUR
// Columns per rij: [indicator | naam | Ma..Zo (7) | btn] = 10
// =========================================================
$weekStarts = week_starts_for_range($from, $to);
$byWeek = []; // weekStart => [ rno => {...} ]

foreach ($weekStarts as $ws) {
    $byWeek[$ws] = [];
    foreach ($resourcesForApprover as $rno => $rname) {
        $byWeek[$ws][$rno] = [
            'name' => $rname,
            'tsNo' => null,
            'dayTotals' => [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
            'unapprovedCount' => 0,
            'unapprovedActionableCount' => 0,
            'hasUnapproved' => false,
            'lines' => [],
            'present' => false,
            'isVakantie' => gk_is_vakantie($rno, $ws),
        ];
    }
}

// =========================================================
// OPHALEN: URENSTATEN + REGELS
// =========================================================
if ($resourcesForApprover && $weekStarts) {
    // Haal alle urenstaten op die het datumbereik overlappen
    $filterDecoded = "Ending_Date ge $from and Starting_Date le $to";
    $tsUrl = $base . "Urenstaten?\$select=No,Starting_Date,Ending_Date,Resource_No,Resource_Name"
        . "&\$filter=" . rawurlencode($filterDecoded) . "&\$format=json";
    $tsRows = odata_get_all($tsUrl, $auth, $day);

    $tsByNo = [];
    $tsNos = [];
    foreach ($tsRows as $t) {
        $no = (string) ($t['No'] ?? '');
        if ($no === '') {
            continue;
        }
        $tsByNo[$no] = $t;
        $tsNos[] = $no;
    }

    // Haal regels op
    if ($tsNos) {
        $linesAll = gk_odata_fetch_by_or_filter(
            $base,
            'Urenstaatregels',
            'Time_Sheet_No,Line_No,Status,Header_Resource_No,Work_Type_Code,'
            . 'Field1,Field2,Field3,Field4,Field5,Field6,Field7,Total_Quantity,'
            . 'Description,Job_No,Job_Task_No,Type',
            'Time_Sheet_No',
            $tsNos,
            $auth,
            $day
        );

        foreach ($linesAll as $l) {
            $tsNo = (string) ($l['Time_Sheet_No'] ?? '');
            $rno = (string) ($l['Header_Resource_No'] ?? '');
            if (!isset($tsByNo[$tsNo]) || !isset($resourcesForApprover[$rno])) {
                continue;
            }
            $weekStart = (string) ($tsByNo[$tsNo]['Starting_Date'] ?? '');
            if (!isset($byWeek[$weekStart][$rno])) {
                continue;
            }

            $workType = (string) ($l['Work_Type_Code'] ?? '');
            $status = (string) ($l['Status'] ?? '');

            $byWeek[$weekStart][$rno]['present'] = true;
            if ($byWeek[$weekStart][$rno]['tsNo'] === null) {
                $byWeek[$weekStart][$rno]['tsNo'] = $tsNo;
            }
            $byWeek[$weekStart][$rno]['lines'][] = $l;

            if ($status !== 'Approved') {
                $byWeek[$weekStart][$rno]['hasUnapproved'] = true;
                $byWeek[$weekStart][$rno]['unapprovedCount']++;

                $hasAnyHours = false;
                for ($i = 1; $i <= 7; $i++) {
                    if ((float) ($l["Field{$i}"] ?? 0) != 0.0) {
                        $hasAnyHours = true;
                        break;
                    }
                }
                if ($hasAnyHours) {
                    $byWeek[$weekStart][$rno]['unapprovedActionableCount']++;
                }
            } elseif ($workType !== 'KM') {
                for ($i = 1; $i <= 7; $i++) {
                    $byWeek[$weekStart][$rno]['dayTotals'][$i - 1] += (float) ($l["Field{$i}"] ?? 0);
                }
            }
        }
    }

    // Fallback: als een urenstaat geen regels heeft, gebruik dan alsnog de header-resource.
    foreach ($tsByNo as $tsNo => $t) {
        $rno = trim((string) ($t['Resource_No'] ?? ''));
        $weekStart = (string) ($t['Starting_Date'] ?? '');
        if ($rno === '' || !isset($resourcesForApprover[$rno]) || !isset($byWeek[$weekStart][$rno])) {
            continue;
        }
        $byWeek[$weekStart][$rno]['present'] = true;
        if ($byWeek[$weekStart][$rno]['tsNo'] === null) {
            $byWeek[$weekStart][$rno]['tsNo'] = $tsNo;
        }
    }
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

// Weken aflopend sorteren (nieuwste eerst)
krsort($byWeek);

// Resources per week op naam sorteren
foreach ($byWeek as &$resources) {
    uasort($resources, fn($a, $b) => strcmp($a['name'], $b['name']));
}
unset($resources);

$actionItems = [];
foreach ($byWeek as $weekStart => $resources) {
    $weekDates = [];
    for ($dayIndex = 0; $dayIndex < 7; $dayIndex++) {
        $weekDates[] = ymd_add_days($weekStart, $dayIndex);
    }
    $weekEnd = $weekDates[6];
    $isPastWeek = ($weekEnd < $today);
    $weekNo = (new DateTimeImmutable($weekStart))->format('W');

    foreach ($resources as $resourceNo => $resourceData) {
        $rowId = 'row-' . md5($resourceNo . $weekStart);

        if (!$resourceData['isVakantie'] && !$resourceData['present'] && $isPastWeek) {
            $actionItems[] = [
                'rowId' => $rowId,
                'detailId' => null,
                'weekLabel' => 'Week ' . $weekNo,
                'resourceName' => (string) ($resourceData['name'] ?? $resourceNo),
                'type' => 'missing',
                'label' => 'Ontbrekende urenstaat',
            ];
            continue;
        }

        if ((int) ($resourceData['unapprovedActionableCount'] ?? 0) > 0) {
            $count = (int) $resourceData['unapprovedActionableCount'];
            $actionItems[] = [
                'rowId' => $rowId,
                'detailId' => 'detail-' . md5($resourceNo . $weekStart),
                'weekLabel' => 'Week ' . $weekNo,
                'resourceName' => (string) ($resourceData['name'] ?? $resourceNo),
                'type' => 'unapproved',
                'label' => $count . ' regel' . ($count === 1 ? '' : 's') . ' goed te keuren',
            ];
        }
    }
}

$DAY_NAMES = ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo'];
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Goedkeuren Urenstaten</title>
    <style>
        body {
            margin: 0;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
        }

        .wrap {
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 16px;
        }

        .layout {
            display: block;
        }

        .main-content {
            min-width: 0;
            width: 100%;
        }

        .sidebar {
            position: fixed;
            top: 16px;
            right: 16px;
            width: 300px;
            z-index: 1000;
            overflow-x: hidden;
        }

        .sidebar-card {
            background: #fff7ed;
            border: 1px solid #fdba74;
            border-radius: 16px;
            padding: 14px;
            max-height: calc(100vh - 32px);
            overflow-y: auto;
            overflow-x: hidden;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.14);
        }

        .sidebar-title {
            margin: 0 0 10px;
            font-size: 14px;
            font-weight: 700;
            color: #9a3412;
        }

        .sidebar-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .sidebar-link {
            display: block;
            width: 100%;
            text-align: left;
            border: 1px solid #fed7aa;
            background: #fff;
            border-radius: 12px;
            padding: 10px 12px;
            cursor: pointer;
            font-family: inherit;
            transform-origin: right center;
            transition: transform 0.35s ease, opacity 0.35s ease, max-height 0.35s ease, margin 0.35s ease, padding 0.35s ease, border-width 0.35s ease;
            max-height: 140px;
            overflow: hidden;
        }

        .sidebar-link:hover {
            background: #fffbeb;
        }

        .sidebar-link.sidebar-link-removing {
            opacity: 0;
            transform: translateX(42px) scale(0.96);
            max-height: 0;
            margin: 0;
            padding-top: 0;
            padding-bottom: 0;
            border-width: 0;
            pointer-events: none;
        }

        .sidebar-link-week {
            display: block;
            font-size: 11px;
            color: #9a3412;
            margin-bottom: 2px;
        }

        .sidebar-link-name {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
        }

        .sidebar-link-label {
            display: block;
            font-size: 12px;
            color: #7c2d12;
            margin-top: 2px;
        }

        h1 {
            background-color: #e4ecf8;
            background-image: url("images/kvtlogo.png");
            background-size: contain;
            background-repeat: no-repeat;
            background-position: right;
            border-radius: 16px;
            padding: 5px 16px;
            margin: 0 0 16px;
        }

        .filter-form {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .filter-form label {
            font-size: 13px;
            color: #64748b;
        }

        .btn {
            display: inline-block;
            padding: 6px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            text-decoration: none;
            color: #0f172a;
            background: #fff;
            cursor: pointer;
            font-size: 13px;
            font-family: inherit;
        }

        .btn:hover {
            background: #f8fafc;
        }

        .week-card {
            background-color: #e4ecf8;
            border: 1px solid #c7d9f0;
            border-radius: 16px;
            padding: 14px 16px;
            margin: 16px 0;
        }

        .week-title {
            margin: 0 0 2px;
            font-size: 15px;
            font-weight: 700;
        }

        .week-dates {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
        }

        th,
        td {
            border-bottom: 1px solid #e2e8f0;
            padding: 6px 8px;
            font-size: 12px;
            text-align: center;
        }

        th {
            background: #f8fafc;
            font-size: 11px;
            font-weight: 600;
        }

        /* Eerste kolom (indicator): smal */
        th:first-child,
        td:first-child {
            width: 44px;
        }

        /* Tweede kolom (naam): links uitgelijnd */
        th:nth-child(2),
        td:nth-child(2) {
            text-align: left;
            min-width: 140px;
        }

        .resource-row {
            cursor: pointer;
            transition: background 0.1s;
        }

        .resource-row:hover {
            background: #f0f5ff;
        }

        /* Rij zonder urenstaat in het verleden: rood */
        .resource-row.missing-past {
            background: #fee2e2;
            cursor: default;
        }

        .resource-row.missing-past:hover {
            background: #fecaca;
        }

        /* Rij zonder urenstaat in de toekomst / huidige week: grijs */
        .resource-row.missing-future {
            background: #f1f5f9;
            color: #94a3b8;
            cursor: default;
        }

        .resource-row.missing-future:hover {
            background: #f1f5f9;
        }

        /* Vakantierij: lichtgroen */
        .resource-row.vakantie-row {
            background: #dcfce7;
            cursor: default;
        }

        /* Badge: te goedkeuren regels */
        .unapproved-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            border-radius: 8px;
            padding: 2px 6px;
            font-size: 11px;
            font-weight: 700;
        }

        .unapproved-badge.unapproved-badge-zero {
            background: #dbeafe;
            border-color: #60a5fa;
            color: #1e3a8a;
        }

        .approved-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 22px;
            padding: 0 8px;
            background: #dcfce7;
            border: 1px solid #4ade80;
            color: #166534;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
        }

        /* Detailrij (accordion) */
        .detail-row>td {
            padding: 0;
            background: #f8fafc;
        }

        .detail-inner {
            padding: 10px 14px 14px;
        }

        .detail-table {
            margin: 0;
            border-radius: 0;
            font-size: 11px;
        }

        .detail-table th {
            background: #e4ecf8;
            font-size: 10px;
        }

        .zeroHours {
            color: #ccc;
        }

        .openStatus {
            background-color: #ffa;
            color: #770;
        }

        .submittedStatus {
            background-color: #fa8;
            color: #750;
        }

        .approvedStatus {
            color: #070;
        }

        .rejectedStatus {
            background-color: #f88;
            color: #700;
        }

        .vakantie-btn {
            background: none;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 2px 7px;
            cursor: pointer;
            font-size: 16px;
            line-height: 1.4;
        }

        .vakantie-btn:hover {
            background: #f0fdf4;
            border-color: #86efac;
        }

        .missing-label {
            font-size: 12px;
            font-style: italic;
        }

        .no-data {
            text-align: center;
            color: #94a3b8;
            padding: 40px;
            font-size: 14px;
        }

        .muted {
            color: #64748b;
        }

        .scroll-target-highlight {
            box-shadow: 0 0 0 3px #f59e0b inset;
        }

        .vakantie-row.vakantie-row-pulse {
            animation: vakantie-row-pulse 0.85s ease;
        }

        @keyframes vakantie-row-pulse {
            0% {
                box-shadow: inset 0 0 0 0 rgba(34, 197, 94, 0.00);
                background-color: #dcfce7;
            }

            35% {
                box-shadow: inset 0 0 0 999px rgba(134, 239, 172, 0.55);
                background-color: #bbf7d0;
            }

            100% {
                box-shadow: inset 0 0 0 0 rgba(34, 197, 94, 0.00);
                background-color: #dcfce7;
            }
        }

        @media (max-width: 1450px) {
            .sidebar {
                position: static;
                width: auto;
                margin-bottom: 16px;
            }

            .sidebar-card {
                max-height: none;
            }
        }
    </style>
</head>

<body>
    <?= render_loading_screen([
        'id' => 'page-loading-screen',
        'title' => 'Gegevens verversen...',
        'subtitle' => 'Even geduld aub',
        'visible' => false,
    ]) ?>

    <div class="wrap">
        <?= injectTimerHtml([
            'statusUrl' => 'odata.php?action=cache_status',
            'title' => 'Cachebestanden',
            'label' => 'Cache',
        ]) ?>

        <h1>Goedkeuren Urenstaten</h1>

        <!-- Filter formulier -->
        <form class="filter-form" method="get">
            <label for="approverUserId">Goedkeurder:</label>
            <select id="approverUserId" name="approverUserId" class="btn">
                <option value="">— selecteer —</option>
                <?php foreach ($approverUserIds as $auid): ?>
                    <option value="<?= htmlspecialchars($auid) ?>" <?= $selectedApproverUserId === $auid ? 'selected' : '' ?>>
                        <?= htmlspecialchars($auid) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label for="fromDate">Vanaf:</label>
            <input type="date" id="fromDate" name="from" value="<?= htmlspecialchars($from) ?>" class="btn">
            <label for="toDate">Tot:</label>
            <input type="date" id="toDate" name="to" value="<?= htmlspecialchars($to) ?>" class="btn">
            <a href="<?= htmlspecialchars($selfPath) ?>" class="btn">Reset</a>
        </form>

        <div class="layout">
            <div class="main-content">

                <?php if ($selectedApproverUserId === ''): ?>
                    <div class="no-data">Selecteer een goedkeurder om urenstaten te bekijken.</div>
                <?php elseif (empty($resourcesForApprover)): ?>
                    <div class="no-data">Voor deze goedkeurder zijn geen resources met urenstaten in de afgelopen maand
                        gevonden.</div>
                <?php elseif (empty($byWeek)): ?>
                    <div class="no-data">Geen weken gevonden in de geselecteerde periode.</div>
                <?php else: ?>

                    <?php foreach ($byWeek as $weekStart => $resources): ?>
                        <?php
                        $weekDates = [];
                        for ($d = 0; $d < 7; $d++) {
                            $weekDates[] = ymd_add_days($weekStart, $d);
                        }
                        $weekEnd = $weekDates[6];

                        $wdt = new DateTimeImmutable($weekStart);
                        $weekNo = $wdt->format('W');
                        $weekYr = $wdt->format('Y');

                        $isPastWeek = ($weekEnd < $today);
                        $isCurrentWeek = ($weekStart <= $today && $weekEnd >= $today);
                        ?>
                        <div class="week-card">
                            <div class="week-title">Week <?= htmlspecialchars($weekNo) ?> &ndash;
                                <?= htmlspecialchars($weekYr) ?></div>
                            <div class="week-dates">
                                <?= gk_formatDate($weekStart) ?> t/m <?= gk_formatDate($weekEnd) ?>
                            </div>

                            <table>
                                <thead>
                                    <tr>
                                        <th></th><!-- indicator kolom -->
                                        <th style="text-align:left">Resource</th>
                                        <?php for ($d = 0; $d < 7; $d++): ?>
                                            <th>
                                                <?= $DAY_NAMES[$d] ?><br>
                                                <span
                                                    class="muted"><?= (new DateTimeImmutable($weekDates[$d]))->format('j/n') ?></span>
                                            </th>
                                        <?php endfor; ?>
                                        <th></th><!-- vakantieknop kolom -->
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($resources as $rno => $rdata): ?>
                                        <?php
                                        $rowId = 'row-' . md5($rno . $weekStart);
                                        $detailId = 'detail-' . md5($rno . $weekStart);
                                        ?>

                                        <?php if ($rdata['isVakantie']): ?>
                                            <!-- ======= VAKANTIERIJ ======= -->
                                            <tr class="resource-row vakantie-row">
                                                <td></td>
                                                <td><?= htmlspecialchars($rdata['name']) ?></td>
                                                <td colspan="8" style="text-align:center; color:#15803d; font-weight:600;">
                                                    🌴 Vakantie
                                                </td>
                                            </tr>

                                        <?php elseif (!$rdata['present'] && $isPastWeek): ?>
                                            <!-- ======= ONTBREKENDE RIJ – VERLEDEN (rood) ======= -->
                                            <tr class="resource-row missing-past" id="<?= $rowId ?>">
                                                <td></td>
                                                <td><?= htmlspecialchars($rdata['name']) ?></td>
                                                <td colspan="7" class="missing-label" style="color:#dc2626;">
                                                    Ontbreekt urenstaat
                                                </td>
                                                <td>
                                                    <button class="vakantie-btn" title="Markeren als vakantie"
                                                        data-resource-no="<?= htmlspecialchars($rno) ?>"
                                                        data-week-start="<?= htmlspecialchars($weekStart) ?>"
                                                        data-row-id="<?= $rowId ?>"
                                                        onclick="markVakantie(this); event.stopPropagation();">
                                                        🌴
                                                    </button>
                                                </td>
                                            </tr>

                                        <?php elseif (!$rdata['present']): ?>
                                            <!-- ======= ONTBREKENDE RIJ – TOEKOMST / HUIDIGE WEEK (grijs) ======= -->
                                            <tr class="resource-row missing-future">
                                                <td></td>
                                                <td><?= htmlspecialchars($rdata['name']) ?></td>
                                                <td colspan="8" class="missing-label">
                                                    <?= $isCurrentWeek ? 'Urenstaat nog niet ontvangen' : 'Nog geen urenstaat' ?>
                                                </td>
                                            </tr>

                                        <?php else: ?>
                                            <!-- ======= NORMALE RIJ MET URENSTAAT ======= -->
                                            <tr class="resource-row" id="<?= $rowId ?>" onclick="toggleDetail('<?= $detailId ?>')">
                                                <td>
                                                    <?php if ($rdata['unapprovedCount'] > 0): ?>
                                                        <?php $onlyZeroHourUnapproved = (int) ($rdata['unapprovedActionableCount'] ?? 0) === 0; ?>
                                                        <span
                                                            class="unapproved-badge <?= $onlyZeroHourUnapproved ? 'unapproved-badge-zero' : '' ?>"
                                                            title="<?= (int) $rdata['unapprovedCount'] ?> regel(s) nog niet goedgekeurd">
                                                            <?= $onlyZeroHourUnapproved ? '✓' : '▶' ?>
                                                            <?= (int) $rdata['unapprovedCount'] ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="approved-badge" title="Alle regels goedgekeurd">✓</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($rdata['name']) ?></td>
                                                <?php for ($d = 0; $d < 7; $d++): ?>
                                                    <td class="<?= $rdata['dayTotals'][$d] <= 0 ? 'zeroHours' : '' ?>">
                                                        <?= htmlspecialchars(gk_hhmm($rdata['dayTotals'][$d])) ?>
                                                    </td>
                                                <?php endfor; ?>
                                                <td></td>
                                            </tr>

                                            <!-- ======= DETAILRIJ (accordion, standaard verborgen) ======= -->
                                            <tr class="detail-row" id="<?= $detailId ?>" style="display:none;">
                                                <td colspan="10">
                                                    <div class="detail-inner">
                                                        <?php if (empty($rdata['lines'])): ?>
                                                            <span class="muted">Geen regels gevonden in deze urenstaat.</span>
                                                        <?php else: ?>
                                                            <table class="detail-table">
                                                                <thead>
                                                                    <tr>
                                                                        <th style="text-align:left">Regel</th>
                                                                        <th style="text-align:left">Type</th>
                                                                        <th style="text-align:left">Omschrijving</th>
                                                                        <th>Project</th>
                                                                        <th>Status</th>
                                                                        <?php foreach ($DAY_NAMES as $dn): ?>
                                                                            <th><?= $dn ?></th>
                                                                        <?php endforeach; ?>
                                                                        <th>Totaal</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($rdata['lines'] as $l): ?>
                                                                        <?php
                                                                        $wt = (string) ($l['Work_Type_Code'] ?? '');
                                                                        $status = (string) ($l['Status'] ?? '');
                                                                        ?>
                                                                        <tr>
                                                                            <td style="text-align:left; white-space:nowrap">
                                                                                <?= htmlspecialchars(
                                                                                    (string) ($l['Time_Sheet_No'] ?? '') . '-' .
                                                                                    (int) ($l['Line_No'] ?? 0)
                                                                                ) ?>
                                                                            </td>
                                                                            <td style="text-align:left">
                                                                                <?= htmlspecialchars($wt) ?>
                                                                            </td>
                                                                            <td style="text-align:left">
                                                                                <?= htmlspecialchars((string) ($l['Description'] ?? '')) ?>
                                                                            </td>
                                                                            <td>
                                                                                <?= htmlspecialchars((string) ($l['Job_Task_No'] ?? '')) ?>
                                                                            </td>
                                                                            <td class="<?= gk_status_class($status) ?>">
                                                                                <?= htmlspecialchars($status) ?>
                                                                            </td>
                                                                            <?php for ($i = 1; $i <= 7; $i++):
                                                                                $h = (float) ($l["Field{$i}"] ?? 0);
                                                                                ?>
                                                                                <td class="<?= $h <= 0 ? 'zeroHours' : '' ?>">
                                                                                    <?= $wt === 'KM'
                                                                                        ? htmlspecialchars((string) $h) . '&nbsp;km'
                                                                                        : htmlspecialchars(gk_hhmm($h)) ?>
                                                                                </td>
                                                                            <?php endfor; ?>
                                                                            <td>
                                                                                <strong>
                                                                                    <?php $tot = (float) ($l['Total_Quantity'] ?? 0); ?>
                                                                                    <?= $wt === 'KM'
                                                                                        ? htmlspecialchars((string) $tot) . '&nbsp;km'
                                                                                        : htmlspecialchars(gk_hhmm($tot)) ?>
                                                                                </strong>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                                <tfoot>
                                                                    <tr>
                                                                        <td colspan="5" style="text-align:left; font-weight:700;">Totaal per
                                                                            dag
                                                                        </td>
                                                                        <?php for ($d = 0; $d < 7; $d++): ?>
                                                                            <td class="<?= $rdata['dayTotals'][$d] <= 0 ? 'zeroHours' : '' ?>"
                                                                                style="font-weight:700;">
                                                                                <?= htmlspecialchars(gk_hhmm($rdata['dayTotals'][$d])) ?>
                                                                            </td>
                                                                        <?php endfor; ?>
                                                                        <td style="font-weight:700;">
                                                                            <?= htmlspecialchars(gk_hhmm(array_sum($rdata['dayTotals']))) ?>
                                                                        </td>
                                                                    </tr>
                                                                </tfoot>
                                                            </table>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>

                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>

                <?php endif; ?>
            </div>

            <?php if (!empty($actionItems)): ?>
                <aside class="sidebar" id="approval-sidebar">
                    <div class="sidebar-card">
                        <h2 class="sidebar-title">Nog te behandelen</h2>
                        <div class="sidebar-list" id="approval-sidebar-list">
                            <?php foreach ($actionItems as $item): ?>
                                <button type="button" class="sidebar-link"
                                    data-row-id="<?= htmlspecialchars($item['rowId']) ?>"
                                    onclick="scrollToActionRow('<?= htmlspecialchars($item['rowId']) ?>', <?= $item['detailId'] !== null ? '\'' . htmlspecialchars($item['detailId']) . '\'' : 'null' ?>)">
                                    <span class="sidebar-link-week"><?= htmlspecialchars($item['weekLabel']) ?></span>
                                    <span class="sidebar-link-name"><?= htmlspecialchars($item['resourceName']) ?></span>
                                    <span class="sidebar-link-label"><?= htmlspecialchars($item['label']) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </aside>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const filterForm = document.querySelector('.filter-form');
        const filterApprover = document.getElementById('approverUserId');
        const filterFromDate = document.getElementById('fromDate');
        const filterToDate = document.getElementById('toDate');
        const approvalSidebar = document.getElementById('approval-sidebar');
        const approvalSidebarList = document.getElementById('approval-sidebar-list');
        let filterSubmitting = false;
        let loadingScreenTimer = null;

        function submitFiltersNow ()
        {
            if (!filterForm || filterSubmitting) return;
            filterSubmitting = true;

            loadingScreenTimer = window.setTimeout(() => {
                if (window.showLoadingScreen)
                {
                    window.showLoadingScreen('page-loading-screen');
                }
            }, 1000);

            filterForm.submit();
        }

        if (filterApprover) filterApprover.addEventListener('change', submitFiltersNow);
        if (filterFromDate) filterFromDate.addEventListener('change', submitFiltersNow);
        if (filterToDate) filterToDate.addEventListener('change', submitFiltersNow);

        // ── Accordion: maximaal 1 detailrij tegelijk open ──────────────────────
        let openDetailId = null;

        function toggleDetail (detailId)
        {
            if (openDetailId && openDetailId !== detailId)
            {
                const prev = document.getElementById(openDetailId);
                if (prev) prev.style.display = 'none';
            }
            const el = document.getElementById(detailId);
            if (!el) return;
            const isOpen = el.style.display !== 'none';
            el.style.display = isOpen ? 'none' : 'table-row';
            openDetailId = isOpen ? null : detailId;
        }

        function scrollToActionRow (rowId, detailId)
        {
            const row = document.getElementById(rowId);
            if (!row) return;

            if (detailId)
            {
                const detailRow = document.getElementById(detailId);
                if (detailRow && detailRow.style.display === 'none')
                {
                    toggleDetail(detailId);
                }
            }

            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            row.classList.add('scroll-target-highlight');
            window.setTimeout(() =>
            {
                row.classList.remove('scroll-target-highlight');
            }, 1800);
        }

        function removeSidebarItemForRow (rowId)
        {
            if (!approvalSidebarList) return;

            const selector = '[data-row-id="' + CSS.escape(rowId) + '"]';
            const sidebarItem = approvalSidebarList.querySelector(selector);
            if (!sidebarItem) return;

            sidebarItem.classList.add('sidebar-link-removing');
            window.setTimeout(() =>
            {
                sidebarItem.remove();

                if (approvalSidebarList.children.length === 0 && approvalSidebar)
                {
                    approvalSidebar.remove();
                }
            }, 360);
        }

        // ── Vakantie markeren ───────────────────────────────────────────────────
        function markVakantie (btn)
        {
            const resourceNo = btn.dataset.resourceNo;
            const weekStart = btn.dataset.weekStart;
            const rowId = btn.dataset.rowId;
            const resourceName = btn.closest('tr').querySelector('td:nth-child(2)').textContent.trim();

            if (!confirm('Ontbrekende urenregel markeren als vakantie?'))
            {
                return;
            }

            const body = new URLSearchParams({ resourceNo, weekStart });

            fetch(window.location.pathname + '?action=markeer_vakantie', { method: 'POST', body })
                .then(r => r.json())
                .then(data =>
                {
                    if (data.success)
                    {
                        const row = document.getElementById(rowId);
                        if (row)
                        {
                            // Vervang rijinhoud door vakantielabel
                            const nameEsc = document.createTextNode(resourceName);
                            const nameTd = document.createElement('td');
                            nameTd.appendChild(nameEsc);

                            const labelTd = document.createElement('td');
                            labelTd.colSpan = 8;
                            labelTd.style.textAlign = 'center';
                            labelTd.style.color = '#15803d';
                            labelTd.style.fontWeight = '600';
                            labelTd.textContent = '🌴 Vakantie';

                            row.className = 'resource-row vakantie-row';
                            row.innerHTML = '';
                            row.appendChild(document.createElement('td')); // lege indicator
                            row.appendChild(nameTd);
                            row.appendChild(labelTd);
                            row.classList.add('vakantie-row-pulse');
                            window.setTimeout(() =>
                            {
                                row.classList.remove('vakantie-row-pulse');
                            }, 900);
                        }

                        removeSidebarItemForRow(rowId);
                    } else
                    {
                        alert('Er is een fout opgetreden: ' + (data.error || 'onbekend'));
                    }
                })
                .catch(() => alert('Kon de vakantie niet opslaan. Probeer het opnieuw.'));
        }
    </script>
</body>

</html>