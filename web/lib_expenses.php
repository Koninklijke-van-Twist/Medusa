<?php

function expenses_types(): array
{
    return [
        'coffee' => 'Koffievergoeding',
        'lunch' => 'Lunchvergoeding',
        'dinner' => 'Dinervergoeding',
        'separation_lt_eu' => 'Scheidingsvergoeding <EU',
        'separation_gt_eu' => 'Scheidingsvergoeding >EU',
        'weekend' => 'Weekendtoeslag',
        'on_call' => 'Consignatiedienst',
        'night' => 'Nachttoeslag',
    ];
}

function expenses_defaults(): array
{
    $defaults = [];
    foreach (array_keys(expenses_types()) as $key) {
        $defaults[$key] = 0;
    }
    return $defaults;
}

function expenses_db_path(): string
{
    return __DIR__ . '/cache/onkosten.sqlite';
}

function expenses_db(): ?SQLite3
{
    if (!class_exists('SQLite3')) {
        return null;
    }

    $db = new SQLite3(expenses_db_path());
    $db->busyTimeout(5000);
    expenses_init_schema($db);
    return $db;
}

function expenses_init_schema(SQLite3 $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS week_expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        resource_no TEXT NOT NULL,
        ts_no TEXT NOT NULL,
        week_start TEXT NOT NULL,
        coffee INTEGER NOT NULL DEFAULT 0,
        lunch INTEGER NOT NULL DEFAULT 0,
        dinner INTEGER NOT NULL DEFAULT 0,
        separation_lt_eu INTEGER NOT NULL DEFAULT 0,
        separation_gt_eu INTEGER NOT NULL DEFAULT 0,
        weekend INTEGER NOT NULL DEFAULT 0,
        on_call INTEGER NOT NULL DEFAULT 0,
        night INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(resource_no, ts_no)
    )');
}

function expenses_normalize(array $raw): array
{
    $normalized = expenses_defaults();
    foreach ($normalized as $key => $defaultValue) {
        $value = $raw[$key] ?? 0;
        $normalized[$key] = max(0, (int) $value);
    }
    return $normalized;
}

function expenses_get_for_pairs(?SQLite3 $db, array $pairs): array
{
    if (!$db || !$pairs) {
        return [];
    }

    $keys = [];
    $placeholders = [];
    $stmt = $db->prepare('SELECT resource_no, ts_no, coffee, lunch, dinner, separation_lt_eu, separation_gt_eu, weekend, on_call, night FROM week_expenses WHERE resource_no = :resource_no AND ts_no = :ts_no');
    if (!$stmt) {
        return [];
    }

    foreach ($pairs as $pair) {
        $resourceNo = (string) ($pair['resource_no'] ?? '');
        $tsNo = (string) ($pair['ts_no'] ?? '');
        if ($resourceNo === '' || $tsNo === '') {
            continue;
        }

        $stmt->reset();
        $stmt->clear();
        $stmt->bindValue(':resource_no', $resourceNo, SQLITE3_TEXT);
        $stmt->bindValue(':ts_no', $tsNo, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

        if ($row) {
            $mapKey = $resourceNo . '|' . $tsNo;
            $keys[$mapKey] = expenses_normalize($row);
        }

        if ($result) {
            $result->finalize();
        }
    }

    return $keys;
}

function expenses_get_for_resource_weeks(?SQLite3 $db, string $resourceNo, array $tsNos): array
{
    if (!$db || !$tsNos) {
        return [];
    }

    $stmt = $db->prepare('SELECT ts_no, coffee, lunch, dinner, separation_lt_eu, separation_gt_eu, weekend, on_call, night FROM week_expenses WHERE resource_no = :resource_no AND ts_no = :ts_no');
    if (!$stmt) {
        return [];
    }

    $rows = [];
    foreach ($tsNos as $tsNo) {
        $tsNo = (string) $tsNo;
        if ($tsNo === '') {
            continue;
        }

        $stmt->reset();
        $stmt->clear();
        $stmt->bindValue(':resource_no', $resourceNo, SQLITE3_TEXT);
        $stmt->bindValue(':ts_no', $tsNo, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

        if ($row) {
            $rows[$tsNo] = expenses_normalize($row);
        }

        if ($result) {
            $result->finalize();
        }
    }

    return $rows;
}

function expenses_save_for_resource_week(?SQLite3 $db, string $resourceNo, string $tsNo, string $weekStart, array $values): bool
{
    if (!$db || $resourceNo === '' || $tsNo === '' || $weekStart === '') {
        return false;
    }

    $v = expenses_normalize($values);

    $stmt = $db->prepare('INSERT INTO week_expenses (
        resource_no, ts_no, week_start,
        coffee, lunch, dinner, separation_lt_eu, separation_gt_eu, weekend, on_call, night, updated_at
    ) VALUES (
        :resource_no, :ts_no, :week_start,
        :coffee, :lunch, :dinner, :separation_lt_eu, :separation_gt_eu, :weekend, :on_call, :night, CURRENT_TIMESTAMP
    )
    ON CONFLICT(resource_no, ts_no) DO UPDATE SET
        week_start = excluded.week_start,
        coffee = excluded.coffee,
        lunch = excluded.lunch,
        dinner = excluded.dinner,
        separation_lt_eu = excluded.separation_lt_eu,
        separation_gt_eu = excluded.separation_gt_eu,
        weekend = excluded.weekend,
        on_call = excluded.on_call,
        night = excluded.night,
        updated_at = CURRENT_TIMESTAMP');

    if (!$stmt) {
        return false;
    }

    $stmt->bindValue(':resource_no', $resourceNo, SQLITE3_TEXT);
    $stmt->bindValue(':ts_no', $tsNo, SQLITE3_TEXT);
    $stmt->bindValue(':week_start', $weekStart, SQLITE3_TEXT);
    foreach ($v as $key => $value) {
        $stmt->bindValue(':' . $key, $value, SQLITE3_INTEGER);
    }

    return (bool) $stmt->execute();
}
