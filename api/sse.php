<?php
require __DIR__ . '/common.php';

require_method('GET');

$team = input_string('team');
$day = input_string('day');
$sinceTs = input_string('since_ts', false);

if (!$team || !$day || !validate_date($day)) {
    http_response_code(422);
    echo "event: error\n";
    echo 'data: {"error":"invalid_parameter"}' . "\n\n";
    exit;
}

$since = 0;
if ($sinceTs !== null && ctype_digit($sinceTs)) {
    $since = (int)$sinceTs;
}

$cfg = load_config();
$heartbeat = (int)($cfg['sse_heartbeat'] ?? 15);
if ($heartbeat < 5) {
    $heartbeat = 5;
}

set_time_limit(0);
ignore_user_abort(true);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

echo ":connected\n\n";
flush();

$pdo = acquire_db();
$lastSent = $since;
$lastPing = time();

while (!connection_aborted()) {
    $stmt = $pdo->prepare('SELECT op_id, emp, new_value, user_id, ts, base_version FROM schedule_ops WHERE team = :team AND day = :day AND ts > :ts ORDER BY ts ASC, op_id ASC');
    $stmt->execute([':team' => $team, ':day' => $day, ':ts' => $lastSent]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $payload = [
            'type' => 'cell.update',
            'team' => $team,
            'day' => $day,
            'emp' => $row['emp'],
            'value' => $row['new_value'],
            'base_version' => (int)$row['base_version'],
            'by' => $row['user_id'],
            'ts' => (int)$row['ts'],
        ];
        $lastSent = max($lastSent, (int)$row['ts']);
        echo "event: message\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        flush();
    }

    $now = time();
    if ($now - $lastPing >= $heartbeat) {
        echo ":ping\n\n";
        flush();
        $lastPing = $now;
    }

    usleep(500000);
}
