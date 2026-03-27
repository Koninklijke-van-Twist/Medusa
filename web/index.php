<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";
require __DIR__ . "/logincheck.php";

$hour = 3600;
$day = $hour * 24;

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

function odata_fetch_by_or_filter_safe(string $base, string $entity, string $select, string $field, array $values, array $auth, int $ttl, int $chunkSize = 40): array
{
    $values = array_values(array_unique(array_filter(array_map(fn($v) => (string) $v, $values), fn($v) => $v !== '')));
    if (!$values) {
        return [];
    }

    $rows = [];
    foreach (array_chunk($values, $chunkSize) as $chunk) {
        try {
            $chunkRows = odata_fetch_by_or_filter($base, $entity, $select, $field, $chunk, $auth, $ttl, $chunkSize);
            if ($chunkRows) {
                foreach ($chunkRows as $row) {
                    $rows[] = $row;
                }
            }
        } catch (Exception $e) {
            continue;
        }
    }

    return $rows;
}

function get_valid_months(array $auth, string $base, int $day): array
{
    $now = new DateTimeImmutable("now");
    $from = $now->modify("-24 months")->format("Y-m-d");

    $filter = rawurlencode("Starting_Date ge $from");
    $url = $base . "Urenstaten?\$select=No,Starting_Date,Ending_Date&\$filter={$filter}&\$format=json";
    $rows = odata_get_all($url, $auth, $day);

    $timesheetsByNo = [];
    $tsNos = [];
    foreach ($rows as $r) {
        $no = (string) ($r['No'] ?? '');
        if ($no === '') {
            continue;
        }
        $timesheetsByNo[$no] = $r;
        $tsNos[] = $no;
    }

    $lines = odata_fetch_by_or_filter_safe($base, 'Urenstaatregels', 'Time_Sheet_No,Header_Resource_No', 'Time_Sheet_No', $tsNos, $auth, 3600);
    $validTsNos = [];
    foreach ($lines as $line) {
        $lineTsNo = (string) ($line['Time_Sheet_No'] ?? '');
        if ($lineTsNo === '' || !isset($timesheetsByNo[$lineTsNo])) {
            continue;
        }

        $resourceNo = (string) ($line['Header_Resource_No'] ?? '');
        if ($resourceNo === '') {
            continue;
        }

        $sd = (string) ($timesheetsByNo[$lineTsNo]['Starting_Date'] ?? '');
        $ed = (string) ($timesheetsByNo[$lineTsNo]['Ending_Date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed)) {
            continue;
        }

        $validTsNos[$lineTsNo] = true;
    }

    $months = [];
    foreach ($timesheetsByNo as $tsNo => $r) {
        if (!isset($validTsNos[$tsNo])) {
            continue;
        }

        $sd = (string) ($r['Starting_Date'] ?? '');
        $ed = (string) ($r['Ending_Date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed)) {
            continue;
        }

        try {
            $start = new DateTimeImmutable($sd);
            $end = new DateTimeImmutable($ed);
        } catch (Exception $e) {
            continue;
        }

        if ($end < $start) {
            continue;
        }

        $cursor = $start->modify('first day of this month');
        $lastMonth = $end->modify('first day of this month');

        while ($cursor <= $lastMonth) {
            $months[$cursor->format('Y-m')] = true;
            $cursor = $cursor->modify('+1 month');
        }
    }

    $monthList = array_keys($months);
    rsort($monthList);
    return $monthList;
}

if ((string) ($_GET['action'] ?? '') === 'months') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        echo json_encode(['ok' => true, 'months' => get_valid_months($auth, $base, $day)], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'months' => []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Selectie</title>
    <style>
        @media print {
            noprint {
                display: none !important;
            }
        }

        body {
            font-family: Verdana, Geneva, Tahoma, sans-serif;
            margin: 0;
            background: #f6f7fb
        }

        .wrap {
            max-width: 820px;
            margin: 40px auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 24px
        }

        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px
        }

        label {
            display: block;
            font-weight: 700;
            font-size: 13px;
            margin: 10px 0 6px
        }

        select,
        input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 12px
        }

        button {
            margin-top: 14px;
            padding: 12px 16px;
            border: 0;
            border-radius: 12px;
            background: #4338ca;
            color: #fff;
            font-weight: 800;
            cursor: pointer
        }

        .sep {
            margin: 18px 0;
            border: none;
            border-top: 1px solid #e2e8f0
        }

        .hint {
            color: #64748b;
            font-size: 13px
        }

        .progress-wrap {
            margin-top: 8px;
            display: none;
        }

        .progress-wrap.active {
            display: block;
        }

        .progress-track {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 999px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            width: 0%;
            background: #4338ca;
            transition: width 200ms linear;
        }

        .page-loader {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
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
    </style>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    </body>
</head>

<body>
    <?= injectTimerHtml([
        'statusUrl' => 'odata.php?action=cache_status',
        'title' => 'Cachebestanden',
        'label' => 'Cache',
    ]) ?>
    <div id="pageLoader" class="page-loader" aria-live="polite" aria-busy="true">
        <div class="page-loader-box">Bezig met laden…</div>
    </div>
    <div class="wrap">
        <noprint><a href="feestdagen.php">Beheer Feestdagen</a></noprint>
        <h1>Overzicht genereren</h1>
        <p class="hint">Kies een maand, of geef een periode op.</p>

        <form id="overviewForm" method="get" action="overzicht.php">
            <label>Maand</label>
            <select id="monthSelect" name="month" disabled>
                <option value="">Maanden laden…</option>
            </select>
            <div id="monthStatus" class="hint">Maanden worden opgehaald uit Business Central…</div>
            <div id="monthProgressWrap" class="progress-wrap active" aria-hidden="false">
                <div class="progress-track">
                    <div id="monthProgressFill" class="progress-fill"></div>
                </div>
            </div>

            <hr class="sep">

            <div class="row">
                <div>
                    <label>Periode van</label>
                    <input type="date" name="from" placeholder="2026-01-01">
                </div>
                <div>
                    <label>t/m</label>
                    <input type="date" name="to" placeholder="2026-01-31">
                </div>
            </div>

            <button type="submit">Toon overzicht</button>
        </form>
    </div>

    <script>
        (function ()
        {
            const monthSelect = document.getElementById('monthSelect');
            const monthStatus = document.getElementById('monthStatus');
            const monthProgressWrap = document.getElementById('monthProgressWrap');
            const monthProgressFill = document.getElementById('monthProgressFill');
            const form = document.getElementById('overviewForm');
            const loader = document.getElementById('pageLoader');

            // Instelblok voor voortgangsbalk-pauzes
            const monthProgressConfig = {
                minPauseCount: 8,
                maxPauseCount: 24,
                minPauseMs: 180,
                maxPauseMs: 3720,
                tickMs: 180,
                milestones: [9, 13, 18, 24, 27, 33, 39, 44, 48, 55, 61, 66, 71, 77, 82, 86, 89, 92]
            };

            let monthProgressTimer = null;
            let monthProgressValue = 0;
            let monthProgressStepIndex = 0;
            let monthProgressPauseUntil = 0;
            let monthProgressPausePlan = new Map();
            let monthReachedWaitState = false;

            function randomInt (min, max)
            {
                return Math.floor(Math.random() * (max - min + 1)) + min;
            }

            function buildPausePlan (milestoneCount)
            {
                const plan = new Map();
                if (milestoneCount <= 0)
                {
                    return plan;
                }

                const minCount = Math.max(0, Math.min(monthProgressConfig.minPauseCount, milestoneCount));
                const maxCount = Math.max(minCount, Math.min(monthProgressConfig.maxPauseCount, milestoneCount));
                const pauseCount = randomInt(minCount, maxCount);
                const used = new Set();

                while (used.size < pauseCount)
                {
                    const stepIndex = randomInt(0, milestoneCount - 1);
                    used.add(stepIndex);
                }

                used.forEach(function (stepIndex)
                {
                    plan.set(stepIndex, randomInt(monthProgressConfig.minPauseMs, monthProgressConfig.maxPauseMs));
                });

                return plan;
            }

            function showLoader ()
            {
                loader?.classList.add('active');
            }

            function hideLoader ()
            {
                loader?.classList.remove('active');
            }

            function setMonthOptions (months)
            {
                monthSelect.innerHTML = '';

                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = '— Kies maand —';
                monthSelect.appendChild(placeholder);

                months.forEach((ym) =>
                {
                    const opt = document.createElement('option');
                    opt.value = ym;
                    opt.textContent = ym;
                    monthSelect.appendChild(opt);
                });

                monthSelect.disabled = false;
                monthStatus.textContent = months.length > 0
                    ? 'Alleen maanden met geldige urenstaatregels worden getoond.'
                    : 'Geen geldige maanden gevonden.';
            }

            function startMonthProgress ()
            {
                monthProgressValue = 6;
                monthProgressStepIndex = 0;
                monthProgressPauseUntil = 0;
                monthReachedWaitState = false;
                monthProgressWrap?.classList.add('active');
                if (monthProgressFill)
                {
                    monthProgressFill.style.width = `${monthProgressValue}%`;
                }
                monthStatus.textContent = 'Maanden worden opgehaald uit Business Central…';

                if (monthProgressTimer)
                {
                    clearInterval(monthProgressTimer);
                }

                monthProgressPausePlan = buildPausePlan(monthProgressConfig.milestones.length);

                monthProgressTimer = setInterval(function ()
                {
                    if (Date.now() < monthProgressPauseUntil)
                    {
                        return;
                    }

                    if (monthProgressStepIndex < monthProgressConfig.milestones.length)
                    {
                        monthProgressValue = monthProgressConfig.milestones[monthProgressStepIndex];
                        const pauseMs = monthProgressPausePlan.get(monthProgressStepIndex) ?? 0;
                        if (pauseMs > 0)
                        {
                            monthProgressPauseUntil = Date.now() + pauseMs;
                        }
                        monthProgressStepIndex++;
                    }

                    if (monthProgressFill)
                    {
                        monthProgressFill.style.width = `${monthProgressValue}%`;
                    }

                    if (!monthReachedWaitState && monthProgressValue >= 92)
                    {
                        monthReachedWaitState = true;
                        monthStatus.textContent = 'Maanden worden opgehaald uit Business Central… cache opbouwen…';
                    }
                }, monthProgressConfig.tickMs);
            }

            function finishMonthProgress ()
            {
                if (monthProgressTimer)
                {
                    clearInterval(monthProgressTimer);
                    monthProgressTimer = null;
                }

                if (monthProgressFill)
                {
                    monthProgressFill.style.width = '100%';
                }

                monthProgressWrap?.classList.remove('active');
            }

            async function loadMonths ()
            {
                startMonthProgress();
                try
                {
                    const response = await fetch('index.php?action=months', { cache: 'no-store' });
                    const payload = await response.json();

                    if (!payload || !Array.isArray(payload.months))
                    {
                        throw new Error('Invalid months payload');
                    }

                    setMonthOptions(payload.months);
                } catch (e)
                {
                    monthSelect.innerHTML = '<option value="">— Kies maand —</option>';
                    monthSelect.disabled = false;
                    monthStatus.textContent = 'Maanden laden mislukt. Gebruik eventueel datum-range.';
                } finally
                {
                    finishMonthProgress();
                }
            }

            form?.addEventListener('submit', function ()
            {
                showLoader();
            });

            window.addEventListener('pageshow', function ()
            {
                hideLoader();
            });

            loadMonths();
        })();
    </script>
</body>

</html>