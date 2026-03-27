<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";
require __DIR__ . "/logincheck.php";
require __DIR__ . "/lib_expenses.php";

$resourceNo = trim((string) ($_GET['resourceNo'] ?? $_POST['resourceNo'] ?? ''));
$from = trim((string) ($_GET['from'] ?? $_POST['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? $_POST['to'] ?? ''));
$month = trim((string) ($_GET['month'] ?? $_POST['month'] ?? ''));
$selectedApproverUserId = trim((string) ($_GET['approverUserId'] ?? $_POST['approverUserId'] ?? ''));
$returnPerson = trim((string) ($_GET['returnPerson'] ?? $_POST['returnPerson'] ?? ''));
$returnPage = trim((string) ($_GET['returnPage'] ?? $_POST['returnPage'] ?? 'overzicht'));
$returnTsNo = trim((string) ($_GET['returnTsNo'] ?? $_POST['returnTsNo'] ?? ''));

if (!in_array($returnPage, ['overzicht', 'weekinspectie'], true)) {
    $returnPage = 'overzicht';
}

if ($resourceNo === '' || $from === '' || $to === '') {
    die('Ontbrekende parameters. Vereist: resourceNo, from en to.');
}

function formatDateNl(string $dateStr): string
{
    if ($dateStr === '') {
        return '';
    }

    $dt = DateTime::createFromFormat('Y-m-d', $dateStr);
    if (!$dt) {
        return htmlspecialchars($dateStr);
    }

    $months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    return $dt->format('d') . ' ' . $months[(int) $dt->format('n') - 1] . ' ' . $dt->format('Y');
}

$day = 3600 * 24;
$escapedResourceNo = str_replace("'", "''", $resourceNo);
$filterDecoded = "Ending_Date ge $from and Starting_Date le $to and Resource_No eq '$escapedResourceNo'";
$filter = rawurlencode($filterDecoded);
$tsUrl = $base . "Urenstaten?\$select=No,Starting_Date,Description,Resource_Name&\$filter={$filter}&\$format=json";
$tsRows = odata_get_all($tsUrl, $auth, $day) ?? [];

usort($tsRows, fn($a, $b) => strcmp((string) ($b['Starting_Date'] ?? ''), (string) ($a['Starting_Date'] ?? '')));

$types = expenses_types();
$defaults = expenses_defaults();
$tsNos = [];
foreach ($tsRows as $t) {
    $no = (string) ($t['No'] ?? '');
    if ($no !== '') {
        $tsNos[] = $no;
    }
}

$db = expenses_db();
$existingByTsNo = expenses_get_for_resource_weeks($db, $resourceNo, $tsNos);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedExpenses = $_POST['expenses'] ?? [];

    foreach ($tsRows as $t) {
        $tsNo = (string) ($t['No'] ?? '');
        $weekStart = (string) ($t['Starting_Date'] ?? '');
        if ($tsNo === '' || $weekStart === '') {
            continue;
        }

        $values = is_array($postedExpenses[$tsNo] ?? null) ? $postedExpenses[$tsNo] : $defaults;
        expenses_save_for_resource_week($db, $resourceNo, $tsNo, $weekStart, $values);
    }

    $redirectUrl = 'onkosten_editor.php?resourceNo=' . rawurlencode($resourceNo)
        . '&from=' . rawurlencode($from)
        . '&to=' . rawurlencode($to)
        . ($month !== '' ? '&month=' . rawurlencode($month) : '')
        . ($selectedApproverUserId !== '' ? '&approverUserId=' . rawurlencode($selectedApproverUserId) : '')
        . ($returnPerson !== '' ? '&returnPerson=' . rawurlencode($returnPerson) : '')
        . '&returnPage=' . rawurlencode($returnPage)
        . '&returnTsNo=' . rawurlencode($returnTsNo)
        . '&saved=1';
    header('Location: ' . $redirectUrl);
    exit;
}

$resourceName = (string) ($tsRows[0]['Resource_Name'] ?? $resourceNo);
$saved = (string) ($_GET['saved'] ?? '') === '1';
if ($returnPage === 'weekinspectie' && $returnTsNo !== '') {
    $backUrl = 'weekinspectie.php?tsNo=' . rawurlencode($returnTsNo)
        . '&resourceNo=' . rawurlencode($resourceNo)
        . ($month !== '' ? '&month=' . rawurlencode($month) : '')
        . '&from=' . rawurlencode($from)
        . '&to=' . rawurlencode($to)
        . ($selectedApproverUserId !== '' ? '&approverUserId=' . rawurlencode($selectedApproverUserId) : '')
        . ($returnPerson !== '' ? '&returnPerson=' . rawurlencode($returnPerson) : '');
    $backLabel = 'Terug naar weekinspectie';
} else {
    $backUrl = 'overzicht.php?from=' . rawurlencode($from)
        . '&to=' . rawurlencode($to)
        . ($month !== '' ? '&month=' . rawurlencode($month) : '')
        . ($selectedApproverUserId !== '' ? '&approverUserId=' . rawurlencode($selectedApproverUserId) : '')
        . ($returnPerson !== '' ? '&returnPerson=' . rawurlencode($returnPerson) : '');
    $backLabel = 'Terug naar overzicht';
}
$typeKeys = array_keys($types);
$splitAt = (int) ceil(count($typeKeys) / 2);
$leftTypeKeys = array_slice($typeKeys, 0, $splitAt);
$rightTypeKeys = array_slice($typeKeys, $splitAt);
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Onkosten-editor</title>
    <style>
        body {
            margin: 0;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
            background: #f8fafc;
            color: #0f172a;
        }

        .wrap {
            max-width: 1400px;
            margin: 24px auto;
            padding: 0 16px;
        }

        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
        }

        h1 {
            margin: 0 0 10px;
        }

        .muted {
            color: #64748b;
            margin: 0 0 14px;
        }

        .ok {
            margin: 0 0 12px;
            background: #dcfce7;
            color: #14532d;
            border: 1px solid #86efac;
            border-radius: 10px;
            padding: 10px 12px;
        }

        input[type="number"] {
            width: 84px;
            padding: 6px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            text-align: right;
        }

        .actions {
            margin-top: 14px;
            display: flex;
            gap: 8px;
        }

        .btn {
            display: inline-block;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 8px 12px;
            text-decoration: none;
            color: #0f172a;
            background: #fff;
            cursor: pointer;
            font-size: 13px;
        }

        .btn-primary {
            background: #1e3a8a;
            border-color: #1e3a8a;
            color: #fff;
        }

        .weeks {
            display: grid;
            gap: 12px;
        }

        .week-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px;
            background: #fff;
        }

        .week-header {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 10px;
            font-weight: bold;
            font-size: 13px;
        }

        .expense-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 20px;
        }

        .expense-col {
            display: grid;
            gap: 8px;
        }

        .expense-field {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 13px;
        }

        .expense-label {
            color: #334155;
            line-height: 1.3;
            padding-right: 8px;
        }

        @media (max-width: 900px) {
            .expense-grid {
                grid-template-columns: 1fr;
            }

            .week-header {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="card">
            <h1>Onkosten-editor: <?= htmlspecialchars($resourceName) ?></h1>
            <p class="muted">Per week integers invullen (0, 1, 2, ...). Periode: <?= formatDateNl($from) ?> t/m
                <?= formatDateNl($to) ?>
            </p>
            <?php if ($saved): ?>
                <div class="ok">Opgeslagen.</div>
            <?php endif; ?>

            <?php if (!$tsRows): ?>
                <p>Geen weken gevonden voor deze werknemer in deze periode.</p>
                <div class="actions">
                    <a class="btn" href="<?= htmlspecialchars($backUrl) ?>"><?= htmlspecialchars($backLabel) ?></a>
                </div>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="resourceNo" value="<?= htmlspecialchars($resourceNo) ?>">
                    <input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>">
                    <input type="hidden" name="to" value="<?= htmlspecialchars($to) ?>">
                    <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
                    <input type="hidden" name="approverUserId" value="<?= htmlspecialchars($selectedApproverUserId) ?>">
                    <input type="hidden" name="returnPerson" value="<?= htmlspecialchars($returnPerson) ?>">
                    <input type="hidden" name="returnPage" value="<?= htmlspecialchars($returnPage) ?>">
                    <input type="hidden" name="returnTsNo" value="<?= htmlspecialchars($returnTsNo) ?>">

                    <div class="weeks">
                        <?php foreach ($tsRows as $t): ?>
                            <?php
                            $tsNo = (string) ($t['No'] ?? '');
                            if ($tsNo === '') {
                                continue;
                            }
                            $desc = (string) ($t['Description'] ?? '');
                            preg_match('/\bWeek\s*(\d+)\b/i', $desc, $m);
                            $weekNo = isset($m[1]) ? (int) $m[1] : 0;
                            $weekStart = (string) ($t['Starting_Date'] ?? '');
                            $values = $existingByTsNo[$tsNo] ?? $defaults;
                            ?>
                            <div class="week-card">
                                <div class="week-header">
                                    <div><?= $weekNo > 0 ? 'Week ' . $weekNo : 'Week -' ?></div>
                                    <div><?= htmlspecialchars(formatDateNl($weekStart)) ?></div>
                                </div>
                                <div class="expense-grid">
                                    <div class="expense-col">
                                        <?php foreach ($leftTypeKeys as $typeKey): ?>
                                            <label class="expense-field">
                                                <span class="expense-label"><?= htmlspecialchars($types[$typeKey]) ?></span>
                                                <input type="number" min="0" step="1"
                                                    name="expenses[<?= htmlspecialchars($tsNo) ?>][<?= htmlspecialchars($typeKey) ?>]"
                                                    value="<?= (int) ($values[$typeKey] ?? 0) ?>">
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="expense-col">
                                        <?php foreach ($rightTypeKeys as $typeKey): ?>
                                            <label class="expense-field">
                                                <span class="expense-label"><?= htmlspecialchars($types[$typeKey]) ?></span>
                                                <input type="number" min="0" step="1"
                                                    name="expenses[<?= htmlspecialchars($tsNo) ?>][<?= htmlspecialchars($typeKey) ?>]"
                                                    value="<?= (int) ($values[$typeKey] ?? 0) ?>">
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Opslaan</button>
                        <a class="btn" href="<?= htmlspecialchars($backUrl) ?>"><?= htmlspecialchars($backLabel) ?></a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>