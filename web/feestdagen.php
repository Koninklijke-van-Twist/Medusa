<?php
// feestdagen.php - single-file UI + API
// Slaat op als feestdagen_JAARTAL.json in dezelfde directory.

declare(strict_types=1);
require __DIR__ . "/auth.php";
require __DIR__ . "/logincheck.php";

// -------------------- Config --------------------
$storageDir = __DIR__ . "/feestdagen";
$filenamePrefix = "feestdagen_";

// -------------------- Helpers --------------------
function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function safe_year(string $y): ?string
{
    $y = trim($y);
    if (preg_match('/^\d{4}$/', $y))
        return $y;
    return null;
}

function safe_date(string $d): ?string
{
    $d = trim($d);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d))
        return null;
    $dt = DateTime::createFromFormat("Y-m-d", $d);
    if (!$dt)
        return null;
    // check exact (prevents 2026-02-31)
    if ($dt->format("Y-m-d") !== $d)
        return null;
    return $d;
}

function file_path(string $dir, string $prefix, string $year): string
{
    return rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $prefix . $year . ".json";
}

function load_holidays(string $path): array
{
    if (!file_exists($path))
        return [];
    $raw = file_get_contents($path);
    if ($raw === false)
        return [];
    $data = json_decode($raw, true);
    if (!is_array($data))
        return [];
    // Normalise to [{name,date}]
    $out = [];
    foreach ($data as $row) {
        if (!is_array($row))
            continue;
        $name = trim((string) ($row['name'] ?? ''));
        $date = (string) ($row['date'] ?? '');
        $date = safe_date($date);
        if ($name === '' || !$date)
            continue;
        $out[] = ['name' => $name, 'date' => $date];
    }
    // sort by date asc then name
    usort($out, function ($a, $b) {
        $c = strcmp($a['date'], $b['date']);
        if ($c !== 0)
            return $c;
        return strcmp($a['name'], $b['name']);
    });
    return $out;
}

function save_holidays(string $path, array $rows): void
{
    // Ensure directory exists
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new Exception("Kon opslagmap niet aanmaken: $dir");
        }
    }

    $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new Exception("JSON encode faalde");
    }

    // Write atomically
    $tmp = $path . ".tmp";
    if (file_put_contents($tmp, $json) === false) {
        throw new Exception("Schrijven naar tmp faalde: $tmp");
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new Exception("Kon tmp niet naar definitief bestand verplaatsen: $path");
    }
}

// -------------------- API mode --------------------
if (isset($_GET['api']) && $_GET['api'] === '1') {
    try {
        $year = safe_year((string) ($_GET['year'] ?? ''));
        if (!$year)
            json_response(['ok' => false, 'error' => 'Ongeldig jaar'], 400);

        $path = file_path($storageDir, $filenamePrefix, $year);

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            $rows = load_holidays($path);
            json_response(['ok' => true, 'year' => $year, 'items' => $rows, 'file' => basename($path)]);
        }

        // POST: save full list (simple + robust)
        $raw = file_get_contents("php://input");
        $body = json_decode($raw ?: "{}", true);
        if (!is_array($body))
            json_response(['ok' => false, 'error' => 'Body is geen JSON object'], 400);

        $items = $body['items'] ?? null;
        if (!is_array($items))
            json_response(['ok' => false, 'error' => 'items ontbreekt'], 400);

        $rows = [];
        foreach ($items as $row) {
            if (!is_array($row))
                continue;
            $name = trim((string) ($row['name'] ?? ''));
            $date = safe_date((string) ($row['date'] ?? ''));
            if ($name === '' || !$date)
                continue;
            $rows[] = ['name' => $name, 'date' => $date];
        }

        // de-dup op datum+naam
        $uniq = [];
        foreach ($rows as $r) {
            $k = $r['date'] . "|" . strtolower($r['name']);
            $uniq[$k] = $r;
        }
        $rows = array_values($uniq);

        usort($rows, function ($a, $b) {
            $c = strcmp($a['date'], $b['date']);
            if ($c !== 0)
                return $c;
            return strcmp($a['name'], $b['name']);
        });

        save_holidays($path, $rows);

        json_response(['ok' => true, 'saved' => count($rows), 'file' => basename($path)]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// -------------------- UI mode --------------------
$defaultYear = (new DateTimeImmutable("now"))->format("Y");
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Feestdagen</title>
    <style>
        :root {
            --bg: #f6f7fb;
            --card: #fff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
            --shadow: 0 10px 30px rgba(2, 6, 23, .08);
            --r: 16px;
            --pri: #4338ca;
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
            background: var(--bg);
            color: var(--text)
        }

        .wrap {
            max-width: 900px;
            margin: 28px auto;
            padding: 0 16px
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--r);
            box-shadow: var(--shadow);
            padding: 18px
        }

        h1 {
            margin: 0 0 told?
        }

        h1 {
            margin: 0 0 6px;
            font-size: 22px
        }

        .muted {
            color: var(--muted);
            font-size: 13px
        }

        .topbar {
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            margin-top: 14px
        }

        .left {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap
        }

        select {
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            min-height: 42px
        }

        .btn {
            min-height: 42px;
            padding: 0 14px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: #fff;
            font-weight: 800;
            cursor: pointer
        }

        .btn-primary {
            border: 0;
            background: linear-gradient(180deg, #4f46e5 0%, var(--pri) 100%);
            color: #fff;
            box-shadow: 0 10px 20px rgba(67, 56, 202, .18)
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px
        }

        th,
        td {
            padding: 10px 10px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
            vertical-align: top
        }

        th {
            background: #f8fafc;
            text-align: left
        }

        .actions {
            white-space: nowrap
        }

        .pill {
            display: inline-block;
            padding: 2px 8px;
            border: 1px solid var(--border);
            border-radius: 999px;
            color: var(--muted);
            font-size: 12px
        }

        .empty {
            padding: 14px;
            border: 1px dashed var(--border);
            border-radius: 12px;
            margin-top: 14px;
            color: var(--muted);
            font-size: 13px
        }

        .hr {
            border: none;
            border-top: 1px solid var(--border);
            margin: 14px 0
        }

        /* Modal */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .35);
            display: none;
            place-items: center;
            padding: 16px
        }

        .modal {
            width: min(520px, 100%);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 16px
        }

        .modal h2 {
            margin: 0 0 10px;
            font-size: 18px
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px
        }

        label {
            font-size: 13px;
            font-weight: 800
        }

        input[type="text"],
        input[type="date"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            min-height: 42px
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 12px;
            flex-wrap: wrap
        }

        .danger {
            border-color: #fecaca;
            background: #fff5f5
        }

        @media (max-width:520px) {
            .actions .btn {
                width: 100%
            }

            .topbar {
                justify-content: flex-start
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="card">
            <h1>Feestdagen beheren</h1>
            <div class="muted">Kies een jaar en voeg feestdagen toe of bewerk ze. Opslag: <span class="pill"
                    id="filePill">—</span></div>

            <div class="topbar">
                <div class="left">
                    <select id="yearSelect"> <?php
                    $y0 = (int) $defaultYear - 5;
                    $y1 = (int) $defaultYear + 5;
                    for ($y = $y1; $y >= $y0; $y--) {
                        $sel = ((string) $y === $defaultYear) ? "selected" : "";
                        echo "<option value=\"" . htmlspecialchars((string) $y) . "\" $sel>" . htmlspecialchars((string) $y) . "</option>";
                    }
                    ?>
                        </select>
                        <button class="btn" id="reloadBtn" type="button">Verversen</button>
                </div>
                <button class="btn btn-primary" id="addBtn" type="button">+ Feestdag</button>
            </div>

            <hr class="hr">

            <div id="status" class="muted"></div>

            <table id="tbl" style="display:none">
                <thead>
                    <tr>
                        <th style="width:50%">Naam</th>
                        <th style="width:25%">Datum</th>
                        <th class="actions" style="width:25%">Acties</th>
                    </tr>
                </thead>
                <tbody id="tbody"></tbody>
            </table>

            <div id="empty" class="empty" style="display:none">
                Geen feestdagen gevonden voor dit jaar. Klik op <b>+ Feestdag</b>.
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal-backdrop" id="modalBackdrop" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
            <h2 id="modalTitle">Feestdag</h2>

            <div class="grid">
                <div>
                    <label for="mName">Naam</label>
                    <input id="mName" type="text" placeholder="Bijv. Nieuwjaarsdag">
                </div>
                <div>
                    <label for="mDate">Datum</label>
                    <input id="mDate" type="date">
                    <div class="muted" style="margin-top:6px">Tip: datum moet binnen het geselecteerde jaar vallen.
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button class="btn" type="button" id="cancelBtn">Annuleren</button>
                <button class="btn btn-primary" type="button" id="saveBtn">Opslaan</button>
            </div>
        </div>
    </div>

    <script>
        let items = []; // [{name,date}]
        let editIndex = -1;

        const yearSelect = document.getElementById('yearSelect');
        const statusEl = document.getElementById('status');
        const filePill = document.getElementById('filePill');
        const tbody = document.getElementById('tbody');
        const tbl = document.getElementById('tbl');
        const empty = document.getElementById('empty');

        const modalBackdrop = document.getElementById('modalBackdrop');
        const mName = document.getElementById('mName');
        const mDate = document.getElementById('mDate');

        function setStatus (msg) { statusEl.textContent = msg || ''; }

        function openModal (index)
        {
            editIndex = index;
            const y = yearSelect.value;

            if (index >= 0)
            {
                document.getElementById('modalTitle').textContent = "Feestdag bewerken";
                mName.value = items[index].name || '';
                mDate.value = items[index].date || (y + "-01-01");
            } else
            {
                document.getElementById('modalTitle').textContent = "Feestdag toevoegen";
                mName.value = '';
                mDate.value = y + "-01-01";
            }

            modalBackdrop.style.display = 'grid';
            modalBackdrop.setAttribute('aria-hidden', 'false');
            setTimeout(() => mName.focus(), 0);
        }

        function closeModal ()
        {
            modalBackdrop.style.display = 'none';
            modalBackdrop.setAttribute('aria-hidden', 'true');
            editIndex = -1;
        }

        function render ()
        {
            tbody.innerHTML = '';
            if (!items || items.length === 0)
            {
                tbl.style.display = 'none';
                empty.style.display = 'block';
                return;
            }

            tbl.style.display = '';
            empty.style.display = 'none';

            items.forEach((it, idx) =>
            {
                const tr = document.createElement('tr');

                const tdName = document.createElement('td');
                tdName.textContent = it.name;

                const tdDate = document.createElement('td');
                tdDate.textContent = it.date;

                const tdAct = document.createElement('td');
                tdAct.className = 'actions';

                const btnEdit = document.createElement('button');
                btnEdit.className = 'btn';
                btnEdit.type = 'button';
                btnEdit.textContent = 'Bewerk';
                btnEdit.onclick = () => openModal(idx);

                const btnDel = document.createElement('button');
                btnDel.className = 'btn danger';
                btnDel.type = 'button';
                btnDel.style.marginLeft = '8px';
                btnDel.textContent = 'Verwijder';
                btnDel.onclick = () =>
                {
                    if (!confirm(`Verwijder "${it.name}" (${it.date})?`)) return;
                    items.splice(idx, 1);
                    saveAll();
                };

                tdAct.appendChild(btnEdit);
                tdAct.appendChild(btnDel);

                tr.appendChild(tdName);
                tr.appendChild(tdDate);
                tr.appendChild(tdAct);

                tbody.appendChild(tr);
            });
        }

        async function loadYear ()
        {
            const y = yearSelect.value;
            setStatus('Laden…');

            const r = await fetch(`feestdagen.php?api=1&year=${encodeURIComponent(y)}`);
            const j = await r.json();

            if (!j.ok)
            {
                setStatus('Fout: ' + (j.error || 'onbekend'));
                return;
            }

            items = j.items || [];
            filePill.textContent = j.file || `feestdagen_${y}.json`;
            setStatus('');
            render();
        }

        async function saveAll ()
        {
            const y = yearSelect.value;
            setStatus('Opslaan…');

            const r = await fetch(`feestdagen.php?api=1&year=${encodeURIComponent(y)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ items })
            });
            const j = await r.json();
            if (!j.ok)
            {
                setStatus('Opslaan faalde: ' + (j.error || 'onbekend'));
                return;
            }
            filePill.textContent = j.file || `feestdagen_${y}.json`;
            setStatus(`Opgeslagen (${j.saved} regels).`);
            // herladen om sortering/normalisatie te zien
            await loadYear();
        }

        function sameYear (dateStr, year)
        {
            return (dateStr || '').startsWith(year + '-');
        }

        document.getElementById('addBtn').addEventListener('click', () => openModal(-1));
        document.getElementById('reloadBtn').addEventListener('click', () => loadYear());

        document.getElementById('cancelBtn').addEventListener('click', () => closeModal());
        modalBackdrop.addEventListener('click', (e) => { if (e.target === modalBackdrop) closeModal(); });

        document.getElementById('saveBtn').addEventListener('click', async () =>
        {
            const y = yearSelect.value;
            const name = (mName.value || '').trim();
            const date = (mDate.value || '').trim();

            if (!name) { alert('Naam is verplicht.'); mName.focus(); return; }
            if (!date) { alert('Datum is verplicht.'); mDate.focus(); return; }
            if (!sameYear(date, y))
            {
                if (!confirm(`Datum valt niet in ${y}. Toch opslaan?`)) return;
            }

            const row = { name, date };

            if (editIndex >= 0) items[editIndex] = row;
            else items.push(row);

            closeModal();
            await saveAll();
        });

        yearSelect.addEventListener('change', () => loadYear());

        // init
        loadYear();
    </script>
</body>

</html>