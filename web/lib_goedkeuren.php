<?php

/**
 * Query-planning voor goedkeuren.php.
 *
 * Grote bedrijven (KVT) liepen vast doordat Urenstaatregels in OR-filters van
 * tientallen Time_Sheet_No's werden opgehaald. Mímir antwoordt daar HTTP 400
 * op; de BC-fallback heeft geen kort timeout en houdt het verzoek vast tot
 * max_execution_time. Deze helpers houden filters kort, beperken het aantal
 * rondes en laten cutoff-detectie op dezelfde regels werken.
 */

function gk_odata_max_clauses(): int
{
    return 24;
}

function gk_odata_max_filter_chars(): int
{
    return 1000;
}

function gk_odata_fetch_concurrency(): int
{
    return 8;
}

function gk_future_precise_chunk_limit(): int
{
    return 4;
}

function gk_is_valid_ymd(string $value): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
}

function gk_normalize_termination_date(string $terminationDate): string
{
    if (!gk_is_valid_ymd($terminationDate)) {
        return '';
    }

    if ($terminationDate === '0001-01-01') {
        return '';
    }

    return $terminationDate;
}

function gk_odata_quote(string $value): string
{
    return str_replace("'", "''", $value);
}

/**
 * @param list<mixed> $values
 * @return list<string>
 */
function gk_odata_normalize_values(array $values): array
{
    $out = [];
    $seen = [];
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value === '' || isset($seen[$value])) {
            continue;
        }
        $seen[$value] = true;
        $out[] = $value;
    }

    return $out;
}

function gk_odata_clause(string $field, string $value): string
{
    return $field . " eq '" . gk_odata_quote($value) . "'";
}

/**
 * @param list<mixed> $values
 */
function gk_odata_or_filter_decoded(string $field, array $values): string
{
    $parts = [];
    foreach (gk_odata_normalize_values($values) as $value) {
        $parts[] = gk_odata_clause($field, $value);
    }

    return implode(' or ', $parts);
}

/**
 * @param list<mixed> $values
 */
function gk_odata_or_filter(string $field, array $values): string
{
    return rawurlencode(gk_odata_or_filter_decoded($field, $values));
}

/**
 * @param list<mixed> $values
 * @return list<list<string>>
 */
function gk_odata_chunk_values(string $field, array $values, ?int $maxClauses = null, ?int $maxDecodedChars = null): array
{
    $maxClauses = $maxClauses === null || $maxClauses < 1 ? gk_odata_max_clauses() : $maxClauses;
    $maxDecodedChars = $maxDecodedChars === null || $maxDecodedChars < 1 ? gk_odata_max_filter_chars() : $maxDecodedChars;
    $values = gk_odata_normalize_values($values);
    if ($values === []) {
        return [];
    }

    $chunks = [];
    $current = [];
    $currentLen = 0;
    foreach ($values as $value) {
        $clause = gk_odata_clause($field, $value);
        $extra = strlen($clause) + ($current === [] ? 0 : 4);
        $wouldExceed = $current !== [] && (
            count($current) >= $maxClauses
            || ($currentLen + $extra) > $maxDecodedChars
        );
        if ($wouldExceed) {
            $chunks[] = $current;
            $current = [];
            $currentLen = 0;
            $extra = strlen($clause);
        }
        $current[] = $value;
        $currentLen += $extra;
    }
    if ($current !== []) {
        $chunks[] = $current;
    }

    return $chunks;
}

function gk_odata_entity_url(string $base, string $entity, string $select, string $filterDecoded): string
{
    return $base . $entity
        . '?$select=' . $select
        . '&$filter=' . rawurlencode($filterDecoded)
        . '&$format=json';
}

function gk_timesheet_header_select(): string
{
    return 'No,Starting_Date,Ending_Date,Resource_No,Resource_Name,'
        . 'Quantity_Open,Quantity_Submitted,Quantity_Approved,Quantity_Rejected,'
        . 'LVS_Approved_Exists,LVS_Open_Exists,LVS_Rejected_Exists';
}

/**
 * @return array{from: string, to: string}
 */
function gk_timesheet_query_window(string $recentFrom, string $from, string $today, string $to): array
{
    $padFrom = $recentFrom;
    $stamp = strtotime($recentFrom . ' -7 days');
    if ($stamp !== false) {
        $padFrom = date('Y-m-d', $stamp);
    }

    return [
        'from' => min($padFrom, $from),
        'to' => max($today, $to),
    ];
}

function gk_timesheet_matches_window(array $row, string $from, string $to): bool
{
    $start = (string) ($row['Starting_Date'] ?? '');
    $end = (string) ($row['Ending_Date'] ?? '');
    if ($start === '' || $end === '') {
        return false;
    }

    return $end >= $from && $start <= $to;
}

/**
 * @param array<string, mixed> $header
 */
function gk_odata_flag_is_true($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (float) $value != 0.0;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'ja'], true);
}

/**
 * Regels zijn alleen nodig als de header uren of een statusvlag heeft.
 * Ontbrekende signalen: wel ophalen, zodat een kale header niets verbergt.
 *
 * @param array<string, mixed> $header
 */
function gk_timesheet_needs_lines(array $header): bool
{
    $sawSignal = false;
    foreach (['Quantity_Open', 'Quantity_Submitted', 'Quantity_Approved', 'Quantity_Rejected'] as $field) {
        if (!array_key_exists($field, $header)) {
            continue;
        }
        $sawSignal = true;
        $raw = $header[$field];
        if (is_string($raw)) {
            $raw = str_replace(',', '.', trim($raw));
        }
        if ((float) $raw != 0.0) {
            return true;
        }
    }
    foreach (['LVS_Approved_Exists', 'LVS_Open_Exists', 'LVS_Rejected_Exists'] as $field) {
        if (!array_key_exists($field, $header)) {
            continue;
        }
        $sawSignal = true;
        if (gk_odata_flag_is_true($header[$field])) {
            return true;
        }
    }

    return !$sawSignal;
}

/**
 * @param array<string, array<string, mixed>> $timesheetRowsByNo
 * @param array<string, mixed> $resourcesForApprover
 * @return list<string>
 */
function gk_timesheet_numbers_for_lines(array $timesheetRowsByNo, array $resourcesForApprover): array
{
    $nos = [];
    foreach ($timesheetRowsByNo as $no => $row) {
        if (!is_array($row)) {
            continue;
        }
        $no = trim((string) $no);
        if ($no === '') {
            $no = trim((string) ($row['No'] ?? ''));
        }
        if ($no === '') {
            continue;
        }
        $resourceNo = trim((string) ($row['Resource_No'] ?? ''));
        if ($resourceNo !== '' && !isset($resourcesForApprover[$resourceNo])) {
            continue;
        }
        if (!gk_timesheet_needs_lines($row)) {
            continue;
        }
        $nos[] = $no;
    }

    return $nos;
}

/**
 * @param list<array<string, mixed>> $timesheetRows
 * @param array<string, mixed> $resourcesForApprover
 * @return array{resourceNos: array<string, true>, weekStarts: array<string, true>}
 */
function gk_recent_activity_from_headers(array $timesheetRows, array $resourcesForApprover): array
{
    $resourceNos = [];
    $weekStarts = [];
    foreach ($timesheetRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $resourceNo = trim((string) ($row['Resource_No'] ?? ''));
        if ($resourceNo === '' || !isset($resourcesForApprover[$resourceNo])) {
            continue;
        }
        $resourceNos[$resourceNo] = true;
        $weekStartRaw = (string) ($row['Starting_Date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStartRaw) !== 1) {
            continue;
        }
        try {
            $weekStarts[(new DateTimeImmutable($weekStartRaw))->modify('monday this week')->format('Y-m-d')] = true;
        } catch (Exception $e) {
            continue;
        }
    }

    return [
        'resourceNos' => $resourceNos,
        'weekStarts' => $weekStarts,
    ];
}

/**
 * Eén datumfilter als resource-OR's niet in een paar korte rondes passen.
 * Anders precieze Resource_No-filters, elk binnen het tekenbudget.
 *
 * @param list<mixed> $resourceNos
 * @return list<string>
 */
function gk_plan_future_timesheet_filters(
    array $resourceNos,
    string $fromDate,
    string $toDate,
    ?int $maxClauses = null,
    ?int $maxDecodedChars = null
): array {
    $resourceNos = gk_odata_normalize_values($resourceNos);
    if ($resourceNos === [] || !gk_is_valid_ymd($fromDate) || !gk_is_valid_ymd($toDate)) {
        return [];
    }

    $date = "Starting_Date ge {$fromDate} and Starting_Date le {$toDate}";
    $wrapper = strlen($date . ' and ()');
    $charBudget = ($maxDecodedChars === null || $maxDecodedChars < 1 ? gk_odata_max_filter_chars() : $maxDecodedChars) - $wrapper;
    if ($charBudget < 32) {
        $charBudget = 32;
    }
    $chunks = gk_odata_chunk_values('Resource_No', $resourceNos, $maxClauses, $charBudget);
    if (count($chunks) > gk_future_precise_chunk_limit()) {
        return [$date];
    }

    $filters = [];
    foreach ($chunks as $chunk) {
        $or = gk_odata_or_filter_decoded('Resource_No', $chunk);
        if ($or === '') {
            continue;
        }
        $filters[] = $date . ' and (' . $or . ')';
    }

    return $filters;
}

/**
 * @param list<string> $urls
 * @return list<list<array<string, mixed>>>
 */
function gk_odata_get_many(array $urls, array $auth, int $ttl, int $concurrency = 8): array
{
    if (isset($GLOBALS['MEDUSA_GK_ODATA_GET_MANY']) && is_callable($GLOBALS['MEDUSA_GK_ODATA_GET_MANY'])) {
        $rows = $GLOBALS['MEDUSA_GK_ODATA_GET_MANY']($urls, $auth, $ttl, $concurrency);
        return is_array($rows) ? $rows : [];
    }

    return odata_get_many($urls, $auth, $ttl, $concurrency);
}

/**
 * @param list<list<array<string, mixed>>> $groups
 * @return list<array<string, mixed>>
 */
function gk_odata_merge_rows(array $groups): array
{
    $rows = [];
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }
        foreach ($group as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

/**
 * @param list<string> $urls
 * @param list<list<string>> $chunks
 * @param list<int> $failedIndexes
 * @param array<int, list<array<string, mixed>>> $completed
 * @return list<array<string, mixed>>
 */
function gk_odata_retry_rejected_chunks(
    string $base,
    string $entity,
    string $select,
    string $field,
    array $chunks,
    array $failedIndexes,
    array $completed,
    array $auth,
    int $ttl,
    int $maxClauses
): array {
    $rows = gk_odata_merge_rows($completed);
    foreach ($failedIndexes as $index) {
        $chunk = $chunks[$index] ?? [];
        if (!is_array($chunk) || $chunk === []) {
            continue;
        }
        if (count($chunk) <= 1) {
            throw new OdataFilterRejected('Urenfilter geweigerd voor één sleutel.');
        }
        $mid = intdiv(count($chunk), 2);
        $rows = array_merge(
            $rows,
            gk_odata_fetch_value_list($base, $entity, $select, $field, array_slice($chunk, 0, $mid), $auth, $ttl, $maxClauses),
            gk_odata_fetch_value_list($base, $entity, $select, $field, array_slice($chunk, $mid), $auth, $ttl, $maxClauses)
        );
    }

    return $rows;
}

/**
 * @param list<mixed> $values
 * @return list<array<string, mixed>>
 */
function gk_odata_fetch_value_list(
    string $base,
    string $entity,
    string $select,
    string $field,
    array $values,
    array $auth,
    int $ttl,
    int $maxClauses
): array {
    $chunks = gk_odata_chunk_values($field, $values, $maxClauses, gk_odata_max_filter_chars());
    if ($chunks === []) {
        return [];
    }

    $urls = [];
    foreach ($chunks as $chunk) {
        $filter = gk_odata_or_filter_decoded($field, $chunk);
        if ($filter === '') {
            continue;
        }
        $urls[] = gk_odata_entity_url($base, $entity, $select, $filter);
    }
    if ($urls === []) {
        return [];
    }

    try {
        return gk_odata_merge_rows(gk_odata_get_many($urls, $auth, $ttl, gk_odata_fetch_concurrency()));
    } catch (OdataFilterRejected $rejected) {
        $failed = $rejected->failedIndexes;
        if ($failed === []) {
            $failed = array_keys($chunks);
        }
        $splittable = false;
        foreach ($failed as $index) {
            if (count($chunks[$index] ?? []) > 1) {
                $splittable = true;
                break;
            }
        }
        if (!$splittable) {
            throw $rejected;
        }

        return gk_odata_retry_rejected_chunks(
            $base,
            $entity,
            $select,
            $field,
            $chunks,
            $failed,
            $rejected->completed,
            $auth,
            $ttl,
            $maxClauses
        );
    }
}

/**
 * @param list<mixed> $values
 * @return list<array<string, mixed>>
 */
function gk_odata_fetch_by_or_filter(
    string $base,
    string $entity,
    string $select,
    string $field,
    array $values,
    array $auth,
    int $ttl,
    int $chunkSize = 24
): array {
    $maxClauses = $chunkSize > 0 ? min($chunkSize, gk_odata_max_clauses()) : gk_odata_max_clauses();

    return gk_odata_fetch_value_list($base, $entity, $select, $field, $values, $auth, $ttl, $maxClauses);
}

/**
 * @param list<mixed> $resourceNos
 * @return list<array<string, mixed>>
 */
function gk_fetch_future_timesheets_for_resources(
    string $base,
    array $auth,
    int $ttl,
    array $resourceNos,
    string $fromDate,
    string $toDate,
    int $chunkSize = 24
): array {
    $resourceNos = gk_odata_normalize_values($resourceNos);
    if ($base === '' || $resourceNos === [] || !gk_is_valid_ymd($fromDate) || !gk_is_valid_ymd($toDate)) {
        return [];
    }

    $maxClauses = $chunkSize > 0 ? min($chunkSize, gk_odata_max_clauses()) : gk_odata_max_clauses();
    $filters = gk_plan_future_timesheet_filters($resourceNos, $fromDate, $toDate, $maxClauses, gk_odata_max_filter_chars());
    if ($filters === []) {
        return [];
    }

    $urls = [];
    foreach ($filters as $filter) {
        $urls[] = gk_odata_entity_url($base, 'Urenstaten', 'Resource_No,Starting_Date', $filter);
    }

    try {
        $groups = gk_odata_get_many($urls, $auth, $ttl, gk_odata_fetch_concurrency());
    } catch (OdataFilterRejected $rejected) {
        $dateOnly = true;
        foreach ($filters as $filter) {
            if (strpos($filter, 'Resource_No') !== false) {
                $dateOnly = false;
                break;
            }
        }
        if ($dateOnly || count($resourceNos) <= 1) {
            throw $rejected;
        }
        $mid = intdiv(count($resourceNos), 2);

        return array_merge(
            gk_fetch_future_timesheets_for_resources($base, $auth, $ttl, array_slice($resourceNos, 0, $mid), $fromDate, $toDate, $chunkSize),
            gk_fetch_future_timesheets_for_resources($base, $auth, $ttl, array_slice($resourceNos, $mid), $fromDate, $toDate, $chunkSize)
        );
    }

    $wanted = array_fill_keys($resourceNos, true);
    $rows = [];
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }
        foreach ($group as $row) {
            if (!is_array($row)) {
                continue;
            }
            $resourceNo = trim((string) ($row['Resource_No'] ?? ''));
            if ($resourceNo !== '' && isset($wanted[$resourceNo])) {
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

/**
 * @param array<string, array<string, mixed>> $resources
 * @param list<array<string, mixed>> $futureRows
 * @param list<string> $selectedWeekStarts
 * @return array{resources: array<string, array<string, mixed>>, without: int, cutoff: int}
 */
function gk_apply_future_timesheet_cutoffs(array $resources, array $futureRows, array $selectedWeekStarts, string $lookaheadFrom): array
{
    $resourceHasFutureTimesheet = [];
    $resourceHasTimesheetByWeek = [];
    foreach ($futureRows as $futureTsRow) {
        if (!is_array($futureTsRow)) {
            continue;
        }
        $resourceNo = trim((string) ($futureTsRow['Resource_No'] ?? ''));
        if ($resourceNo === '' || !isset($resources[$resourceNo])) {
            continue;
        }
        $resourceHasFutureTimesheet[$resourceNo] = true;
        $startDate = (string) ($futureTsRow['Starting_Date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) === 1) {
            try {
                $weekStart = (new DateTimeImmutable($startDate))->modify('monday this week')->format('Y-m-d');
                $resourceHasTimesheetByWeek[$resourceNo][$weekStart] = true;
            } catch (Exception $e) {
                // Negeer onparsebare datums in diagnose-logica.
            }
        }
    }

    $without = 0;
    $cutoff = 0;
    foreach ($resources as $resourceNo => &$resourceInfo) {
        $effectiveTerminationDate = gk_normalize_termination_date((string) ($resourceInfo['terminationDate'] ?? ''));
        if ($effectiveTerminationDate !== '') {
            $resourceInfo['effectiveTerminationDate'] = $effectiveTerminationDate;
            $resourceInfo['effectiveTerminationSource'] = 'bc';
            continue;
        }

        $firstMissingAfterExistingWeek = null;
        $seenExistingWeek = false;
        foreach ($selectedWeekStarts as $weekStart) {
            if (isset($resourceHasTimesheetByWeek[$resourceNo][$weekStart])) {
                $seenExistingWeek = true;
                continue;
            }
            if ($seenExistingWeek) {
                $firstMissingAfterExistingWeek = $weekStart;
                break;
            }
        }

        if ($firstMissingAfterExistingWeek !== null) {
            $resourceInfo['effectiveTerminationDate'] = $firstMissingAfterExistingWeek;
            $resourceInfo['effectiveTerminationSource'] = 'missing-future-timesheets';
            $cutoff++;
            continue;
        }

        if (!isset($resourceHasFutureTimesheet[$resourceNo])) {
            $resourceInfo['effectiveTerminationDate'] = $lookaheadFrom;
            $resourceInfo['effectiveTerminationSource'] = 'missing-future-timesheets';
            $without++;
        }
    }
    unset($resourceInfo);

    return [
        'resources' => $resources,
        'without' => $without,
        'cutoff' => $cutoff,
    ];
}
