<?php
require __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($method === 'GET' ? 'list' : 'create');

$user = is_authenticated();
if (!$user) {
    json_response(['error' => 'unauthorized'], 401);
}

$db = get_db();
$now = time();

try {
    if ($method === 'POST' && $action === 'create') {
        $team = trim($_GET['team'] ?? $_POST['team'] ?? '');
        $day = trim($_GET['day'] ?? $_POST['day'] ?? '');
        $note = trim($_GET['note'] ?? $_POST['note'] ?? '');
        if ($team === '' || $day === '') {
            json_response(['error' => 'invalid_params'], 400);
        }
        if (!is_valid_date($day)) {
            json_response(['error' => 'invalid_date'], 400);
        }
        $payload = export_day_payload($db, $team, $day);
        $snapId = sprintf('%s_%s_%s', md5($team), $day, $now);
        $stmt = $db->prepare('INSERT INTO schedule_snapshots (snap_id, team, day, created_at, note, payload) VALUES (:snap_id, :team, :day, :created_at, :note, :payload)');
        $stmt->execute([
            ':snap_id' => $snapId,
            ':team' => $team,
            ':day' => $day,
            ':created_at' => $now,
            ':note' => $note,
            ':payload' => $payload,
        ]);
        audit_log('snapshot_create', ['snap_id' => $snapId, 'team' => $team, 'day' => $day, 'user' => $user['user_id']]);
        json_response(['snap_id' => $snapId, 'created_at' => $now]);
    } elseif ($method === 'POST' && $action === 'restore') {
        $snapId = trim($_GET['snap_id'] ?? $_POST['snap_id'] ?? '');
        if ($snapId === '') {
            json_response(['error' => 'invalid_params'], 400);
        }
        $stmt = $db->prepare('SELECT team, day, payload FROM schedule_snapshots WHERE snap_id=:snap_id');
        $stmt->execute([':snap_id' => $snapId]);
        $snapshot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$snapshot) {
            json_response(['error' => 'not_found'], 404);
        }
        $db->beginTransaction();
        restore_day_payload($db, $snapshot['team'], $snapshot['day'], $snapshot['payload'], $user['user_id']);
        $db->commit();
        audit_log('snapshot_restore', ['snap_id' => $snapId, 'user' => $user['user_id']]);
        json_response(['restored' => true, 'team' => $snapshot['team'], 'day' => $snapshot['day']]);
    } elseif ($method === 'GET' && $action === 'list') {
        $team = trim($_GET['team'] ?? '');
        $day = trim($_GET['day'] ?? '');
        if ($team === '' || $day === '') {
            json_response(['error' => 'invalid_params'], 400);
        }
        if (!is_valid_date($day)) {
            json_response(['error' => 'invalid_date'], 400);
        }
        $stmt = $db->prepare('SELECT snap_id, created_at, note FROM schedule_snapshots WHERE team=:team AND day=:day ORDER BY created_at DESC');
        $stmt->execute([':team' => $team, ':day' => $day]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_response(['snapshots' => $rows]);
    } else {
        json_response(['error' => 'invalid_action'], 400);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    json_response(['error' => 'server_error', 'message' => $config['debug'] ? $e->getMessage() : '服务器内部错误'], 500);
}
