<?php
require __DIR__ . '/common.php';

require_method('GET');

$team = input_string('team');
$day = input_string('day');

if (!$team || !$day || !validate_date($day)) {
    respond_json(['error' => 'invalid_parameter'], 422);
}

$pdo = acquire_db();
$stmt = $pdo->prepare('SELECT emp, value, version, updated_at, updated_by FROM schedule_cells WHERE team = :team AND day = :day ORDER BY emp ASC');
$stmt->execute([':team' => $team, ':day' => $day]);
$cells = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row['version'] = (int)$row['version'];
    $row['updated_at'] = (int)$row['updated_at'];
    $cells[] = $row;
}

respond_json(['team' => $team, 'day' => $day, 'cells' => $cells]);
