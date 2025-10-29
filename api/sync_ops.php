<?php
require __DIR__ . '/common.php';

require_method('GET');

$team = input_string('team');
$day = input_string('day');
$sinceTs = input_string('since_ts', false);

if (!$team || !$day || !validate_date($day)) {
    respond_json(['error' => 'invalid_parameter'], 422);
}

$since = 0;
if ($sinceTs !== null) {
    if (!ctype_digit($sinceTs)) {
        respond_json(['error' => 'invalid_since_ts'], 422);
    }
    $since = (int)$sinceTs;
}

$pdo = acquire_db();
$stmt = $pdo->prepare('SELECT op_id, team, day, emp, base_version, new_value, user_id, ts FROM schedule_ops WHERE team = :team AND day = :day AND ts > :since ORDER BY ts ASC, op_id ASC');
$stmt->execute([':team' => $team, ':day' => $day, ':since' => $since]);
$ops = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row['ts'] = (int)$row['ts'];
    $row['base_version'] = (int)$row['base_version'];
    $ops[] = $row;
}

respond_json(['ops' => $ops, 'since' => $since]);
