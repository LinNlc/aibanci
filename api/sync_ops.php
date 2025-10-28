<?php
require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'method_not_allowed'], 405);
}

$team = trim($_GET['team'] ?? '');
$day = trim($_GET['day'] ?? '');
$sinceTs = isset($_GET['since_ts']) ? (int)$_GET['since_ts'] : 0;
$limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 200;

if ($team === '' || $day === '') {
    json_response(['error' => 'invalid_params'], 400);
}
if (!is_valid_date($day)) {
    json_response(['error' => 'invalid_date'], 400);
}

$db = get_db();
$stmt = $db->prepare('SELECT op_id, team, day, emp, base_version, new_value, user_id, ts FROM schedule_ops WHERE team=:team AND day=:day AND ts > :ts ORDER BY ts ASC LIMIT :limit');
$stmt->bindValue(':team', $team, PDO::PARAM_STR);
$stmt->bindValue(':day', $day, PDO::PARAM_STR);
$stmt->bindValue(':ts', $sinceTs, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$ops = $stmt->fetchAll(PDO::FETCH_ASSOC);

json_response(['ops' => $ops, 'next_since_ts' => $ops ? (int)end($ops)['ts'] : $sinceTs]);
