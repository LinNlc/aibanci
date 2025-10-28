<?php
require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'method_not_allowed'], 405);
}

$team = trim($_GET['team'] ?? '');
$day = trim($_GET['day'] ?? '');
$emp = trim($_GET['emp'] ?? '');

if ($team === '' || $day === '' || $emp === '') {
    json_response(['error' => 'invalid_params'], 400);
}
if (!is_valid_date($day)) {
    json_response(['error' => 'invalid_date'], 400);
}

$db = get_db();
$stmt = $db->prepare('SELECT value, version, updated_at, updated_by FROM schedule_cells WHERE team=:team AND day=:day AND emp=:emp');
$stmt->execute([':team' => $team, ':day' => $day, ':emp' => $emp]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$result) {
    json_response(['value' => null, 'version' => 0, 'updated_at' => null, 'updated_by' => null]);
}

json_response($result);
