<?php
require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'method_not_allowed'], 405);
}

$team = trim($_GET['team'] ?? '');
$day = trim($_GET['day'] ?? '');
$sinceTs = isset($_GET['since_ts']) ? (int)$_GET['since_ts'] : (time() - 1);

if ($team === '' || $day === '') {
    json_response(['error' => 'invalid_params'], 400);
}
if (!is_valid_date($day)) {
    json_response(['error' => 'invalid_date'], 400);
}

global $config;
$db = get_db();

ignore_user_abort(true);
set_time_limit(0);

while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

$lastPing = 0;
$cursor = $sinceTs;

while (!connection_aborted()) {
    $stmt = $db->prepare('SELECT op_id, emp, new_value, user_id, ts, base_version FROM schedule_ops WHERE team=:team AND day=:day AND ts > :cursor ORDER BY ts ASC LIMIT 100');
    $stmt->execute([
        ':team' => $team,
        ':day' => $day,
        ':cursor' => $cursor,
    ]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($events as $event) {
        $cursor = max($cursor, (int)$event['ts']);
        $payload = [
            'type' => 'cell.update',
            'team' => $team,
            'day' => $day,
            'emp' => $event['emp'],
            'value' => $event['new_value'],
            'by' => $event['user_id'],
            'ts' => (int)$event['ts'],
            'op_id' => $event['op_id'],
            'base_version' => (int)$event['base_version'],
        ];
        echo "event: message\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    $now = time();
    if ($now - $lastPing >= (int)$config['sse_ping_interval']) {
        echo ":ping\n\n";
        $lastPing = $now;
    }

    if (empty($events)) {
        usleep(500000);
    }
}
