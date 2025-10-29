<?php
require __DIR__ . '/common.php';

require_method('GET');

$team = input_string('team');
$day = input_string('day');
$emp = input_string('emp');

if (!$team || !$day || !$emp || !validate_date($day)) {
    respond_json(['error' => 'invalid_parameter'], 422);
}

$pdo = acquire_db();
$stmt = $pdo->prepare('SELECT value, version, updated_at, updated_by FROM schedule_cells WHERE team = :team AND day = :day AND emp = :emp');
$stmt->execute([':team' => $team, ':day' => $day, ':emp' => $emp]);
$cell = $stmt->fetch();

if (!$cell) {
    respond_json(['error' => 'not_found'], 404);
}

respond_json([
    'value' => $cell['value'],
    'version' => (int)$cell['version'],
    'updated_at' => (int)$cell['updated_at'],
    'updated_by' => $cell['updated_by'],
]);
