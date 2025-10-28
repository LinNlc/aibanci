<?php
require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'method_not_allowed'], 405);
}

$user = is_authenticated();
if (!$user) {
    json_response(['error' => 'unauthorized', 'message' => '请先登录'], 401);
}

global $config;

enforce_rate_limit($user['user_id'], 'update_cell', (int)$config['write_rate_limit_per_second']);

$team = trim($_POST['team'] ?? '');
$day = trim($_POST['day'] ?? '');
$emp = trim($_POST['emp'] ?? '');
$newValue = trim($_POST['new_value'] ?? '');
$baseVersion = isset($_POST['base_version']) ? (int)$_POST['base_version'] : null;
$opId = trim($_POST['op_id'] ?? '');

if ($team === '' || $day === '' || $emp === '' || $opId === '' || $baseVersion === null) {
    json_response(['error' => 'invalid_params', 'message' => '参数缺失'], 400);
}
if (!is_valid_date($day)) {
    json_response(['error' => 'invalid_date', 'message' => '日期格式错误'], 400);
}
assert_value_allowed($newValue);

$db = get_db();
$now = time();

try {
    $db->beginTransaction();

    // 幂等处理：若已存在相同操作，直接返回
    $stmt = $db->prepare('SELECT * FROM schedule_ops WHERE op_id = :op_id');
    $stmt->execute([':op_id' => $opId]);
    $existingOp = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existingOp) {
        $cellStmt = $db->prepare('SELECT value, version, updated_at, updated_by FROM schedule_cells WHERE team=:team AND day=:day AND emp=:emp');
        $cellStmt->execute([':team' => $team, ':day' => $day, ':emp' => $emp]);
        $cell = $cellStmt->fetch(PDO::FETCH_ASSOC);
        $db->commit();
        json_response([
            'applied' => true,
            'op_reused' => true,
            'value' => $cell['value'] ?? null,
            'version' => $cell['version'] ?? null,
            'updated_at' => $cell['updated_at'] ?? null,
            'updated_by' => $cell['updated_by'] ?? null,
        ]);
    }

    // 插入操作日志（若失败则说明重复）
    $opStmt = $db->prepare('INSERT INTO schedule_ops (op_id, team, day, emp, base_version, new_value, user_id, ts) VALUES (:op_id, :team, :day, :emp, :base_version, :new_value, :user_id, :ts)');
    $opStmt->execute([
        ':op_id' => $opId,
        ':team' => $team,
        ':day' => $day,
        ':emp' => $emp,
        ':base_version' => $baseVersion,
        ':new_value' => $newValue,
        ':user_id' => $user['user_id'],
        ':ts' => $now,
    ]);

    // CAS：判断当前版本
    $cellStmt = $db->prepare('SELECT value, version FROM schedule_cells WHERE team=:team AND day=:day AND emp=:emp');
    $cellStmt->execute([':team' => $team, ':day' => $day, ':emp' => $emp]);
    $currentCell = $cellStmt->fetch(PDO::FETCH_ASSOC);

    if ($currentCell) {
        if ((int)$currentCell['version'] !== $baseVersion) {
            $db->rollBack();
            json_response(['applied' => false, 'reason' => 'conflict', 'current_version' => (int)$currentCell['version']], 200);
        }
        $updateStmt = $db->prepare('UPDATE schedule_cells SET value=:value, version=version+1, updated_at=:updated_at, updated_by=:updated_by WHERE team=:team AND day=:day AND emp=:emp');
        $updateStmt->execute([
            ':value' => $newValue,
            ':updated_at' => $now,
            ':updated_by' => $user['user_id'],
            ':team' => $team,
            ':day' => $day,
            ':emp' => $emp,
        ]);
    } else {
        if ($baseVersion !== 0) {
            $db->rollBack();
            json_response(['applied' => false, 'reason' => 'conflict', 'current_version' => null], 200);
        }
        $insertStmt = $db->prepare('INSERT INTO schedule_cells (team, day, emp, value, version, updated_at, updated_by) VALUES (:team, :day, :emp, :value, :version, :updated_at, :updated_by)');
        $insertStmt->execute([
            ':team' => $team,
            ':day' => $day,
            ':emp' => $emp,
            ':value' => $newValue,
            ':version' => 1,
            ':updated_at' => $now,
            ':updated_by' => $user['user_id'],
        ]);
    }

    $db->commit();

    audit_log('update_cell', [
        'team' => $team,
        'day' => $day,
        'emp' => $emp,
        'new_value' => $newValue,
        'user_id' => $user['user_id'],
        'op_id' => $opId,
    ]);

    json_response([
        'applied' => true,
        'value' => $newValue,
        'version' => $currentCell ? $baseVersion + 1 : 1,
        'updated_at' => $now,
        'updated_by' => $user['user_id'],
        'op_id' => $opId,
        'ts' => $now,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($config['debug']) {
        json_response(['error' => 'server_error', 'message' => $e->getMessage()], 500);
    }
    json_response(['error' => 'server_error', 'message' => '服务器内部错误'], 500);
}
