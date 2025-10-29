<?php
require __DIR__ . '/common.php';

require_method('GET');
require_page_view('schedule');

$pdo = db();
$teamId = input_int('team_id');
$start = input_string('start');
$end = input_string('end');

if (!$teamId || !$start || !$end) {
    respond_json(['error' => 'missing_parameters'], 422);
}
if (!validate_date($start) || !validate_date($end)) {
    respond_json(['error' => 'invalid_date'], 422);
}
if (strcmp($start, $end) > 0) {
    respond_json(['error' => 'invalid_range'], 422);
}

$teamAccess = ensure_team_access($teamId, false);

$teamStmt = $pdo->prepare('SELECT id, code, name FROM teams WHERE id = :id AND is_active = 1');
$teamStmt->execute([':id' => $teamId]);
$teamRow = $teamStmt->fetch();
if (!$teamRow) {
    respond_json(['error' => 'team_not_found'], 404);
}

$peopleStmt = $pdo->prepare('SELECT id, name FROM people WHERE team_id = :team AND active = 1 AND show_in_schedule = 1 ORDER BY sort_index ASC, id ASC');
$peopleStmt->execute([':team' => $teamId]);
$people = [];
while ($row = $peopleStmt->fetch()) {
    $people[] = [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
    ];
}

$cellStmt = $pdo->prepare('SELECT sc.day, sc.person_id, sc.value, sc.version, sc.updated_at, sc.updated_by, a.display_name, a.username FROM schedule_cells sc LEFT JOIN accounts a ON sc.updated_by = a.id WHERE sc.team_id = :team AND sc.day BETWEEN :start AND :end');
$cellStmt->execute([
    ':team' => $teamId,
    ':start' => $start,
    ':end' => $end,
]);

$cellsByDay = [];
while ($row = $cellStmt->fetch()) {
    $day = (string)$row['day'];
    $personId = (int)$row['person_id'];
    if (!isset($cellsByDay[$day])) {
        $cellsByDay[$day] = [];
    }
    $cellsByDay[$day][$personId] = [
        'value' => (string)$row['value'],
        'version' => (int)$row['version'],
        'updated_at' => (int)$row['updated_at'],
        'updated_by' => $row['updated_by'] !== null ? [
            'id' => (int)$row['updated_by'],
            'display_name' => $row['display_name'] ?? $row['username'] ?? '',
        ] : null,
    ];
}

$days = [];
$cursor = new DateTimeImmutable($start);
$endDate = new DateTimeImmutable($end);

while ($cursor <= $endDate) {
    $date = $cursor->format('Y-m-d');
    $weekday = (int)$cursor->format('N');
    $days[] = [
        'date' => $date,
        'weekday' => $weekday,
        'cells' => $cellsByDay[$date] ?? [],
    ];
    $cursor = $cursor->modify('+1 day');
}

respond_json([
    'team' => [
        'id' => (int)$teamRow['id'],
        'code' => (string)$teamRow['code'],
        'name' => (string)$teamRow['name'],
        'access' => $teamAccess['access'],
    ],
    'people' => $people,
    'days' => $days,
]);
