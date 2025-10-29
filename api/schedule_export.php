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

ensure_team_access($teamId, false);

$teamStmt = $pdo->prepare('SELECT code, name FROM teams WHERE id = :id');
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

$cellStmt = $pdo->prepare('SELECT day, person_id, value FROM schedule_cells WHERE team_id = :team AND day BETWEEN :start AND :end');
$cellStmt->execute([
    ':team' => $teamId,
    ':start' => $start,
    ':end' => $end,
]);
$cells = [];
while ($row = $cellStmt->fetch()) {
    $date = (string)$row['day'];
    $personId = (int)$row['person_id'];
    if (!isset($cells[$date])) {
        $cells[$date] = [];
    }
    $cells[$date][$personId] = (string)$row['value'];
}

$filename = sprintf('schedule-%s-%s-%s.csv', $teamRow['code'], str_replace('-', '', $start), str_replace('-', '', $end));
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo "\xEF\xBB\xBF"; // UTF-8 BOM

$weekMap = [1 => '一', 2 => '二', 3 => '三', 4 => '四', 5 => '五', 6 => '六', 7 => '日'];

$fh = fopen('php://output', 'w');
$header = ['日期', '星期'];
foreach ($people as $person) {
    $header[] = $person['name'];
}
fputcsv($fh, $header);

$cursor = new DateTimeImmutable($start);
$endDate = new DateTimeImmutable($end);
while ($cursor <= $endDate) {
    $date = $cursor->format('Y-m-d');
    $weekday = (int)$cursor->format('N');
    $row = [$date, '周' . ($weekMap[$weekday] ?? $weekday)];
    foreach ($people as $person) {
        $row[] = $cells[$date][$person['id']] ?? '';
    }
    fputcsv($fh, $row);
    $cursor = $cursor->modify('+1 day');
}

fclose($fh);
exit;
