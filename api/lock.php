<?php
require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'method_not_allowed'], 405);
}

$user = is_authenticated();
if (!$user) {
    json_response(['error' => 'unauthorized'], 401);
}

$team = trim($_POST['team'] ?? '');
$day = trim($_POST['day'] ?? '');
$emp = trim($_POST['emp'] ?? '');
$action = trim($_POST['action'] ?? '');

if ($team === '' || $day === '' || $emp === '' || $action === '') {
    json_response(['error' => 'invalid_params'], 400);
}
if (!is_valid_date($day)) {
    json_response(['error' => 'invalid_date'], 400);
}

global $config;
$duration = (int)$config['lock_duration'];
$now = time();
$db = get_db();

try {
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT locked_by, lock_until FROM schedule_softlocks WHERE team=:team AND day=:day AND emp=:emp');
    $stmt->execute([':team' => $team, ':day' => $day, ':emp' => $emp]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    $risk = false;
    if ($current && (int)$current['lock_until'] > $now && $current['locked_by'] !== $user['user_id']) {
        $risk = true;
    }

    if ($action === 'acquire') {
        $lockUntil = $now + $duration;
        $up = $db->prepare('REPLACE INTO schedule_softlocks (team, day, emp, locked_by, lock_until) VALUES (:team, :day, :emp, :locked_by, :lock_until)');
        $up->execute([
            ':team' => $team,
            ':day' => $day,
            ':emp' => $emp,
            ':locked_by' => $user['user_id'],
            ':lock_until' => $lockUntil,
        ]);
        $db->commit();
        json_response(['locked' => true, 'lock_until' => $lockUntil, 'risk' => $risk]);
    } elseif ($action === 'renew') {
        if (!$current || $current['locked_by'] !== $user['user_id']) {
            $db->rollBack();
            json_response(['error' => 'not_owner', 'message' => '当前用户不持有锁'], 409);
        }
        $lockUntil = $now + $duration;
        $up = $db->prepare('UPDATE schedule_softlocks SET lock_until=:lock_until WHERE team=:team AND day=:day AND emp=:emp');
        $up->execute([
            ':lock_until' => $lockUntil,
            ':team' => $team,
            ':day' => $day,
            ':emp' => $emp,
        ]);
        $db->commit();
        json_response(['locked' => true, 'lock_until' => $lockUntil, 'risk' => false]);
    } elseif ($action === 'release') {
        $del = $db->prepare('DELETE FROM schedule_softlocks WHERE team=:team AND day=:day AND emp=:emp AND locked_by=:locked_by');
        $del->execute([
            ':team' => $team,
            ':day' => $day,
            ':emp' => $emp,
            ':locked_by' => $user['user_id'],
        ]);
        $db->commit();
        json_response(['locked' => false]);
    } else {
        $db->rollBack();
        json_response(['error' => 'invalid_action'], 400);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    json_response(['error' => 'server_error', 'message' => $config['debug'] ? $e->getMessage() : '服务器内部错误'], 500);
}
