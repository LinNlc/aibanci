<?php
require __DIR__ . '/common.php';

require_method('POST');
require_auth();

$payload = require_post_json();
$team = isset($payload['team']) ? trim((string)$payload['team']) : '';
$day = isset($payload['day']) ? trim((string)$payload['day']) : '';
$emp = isset($payload['emp']) ? trim((string)$payload['emp']) : '';
$newValue = isset($payload['new_value']) ? trim((string)$payload['new_value']) : '';
$baseVersion = isset($payload['base_version']) ? (int)$payload['base_version'] : null;
$opId = isset($payload['op_id']) ? trim((string)$payload['op_id']) : '';

if ($team === '' || $day === '' || $emp === '' || $opId === null || $opId === '') {
    respond_json(['error' => 'invalid_parameter'], 422);
}
if ($baseVersion === null) {
    respond_json(['error' => 'missing_parameter', 'field' => 'base_version'], 422);
}
if (!validate_date($day)) {
    respond_json(['error' => 'invalid_date'], 422);
}
ensure_value_allowed($newValue);

$pdo = acquire_db();
$userId = get_current_user_id() ?? 'guest';
ensure_rate_limit($pdo, $userId);
$now = time();

try {
    begin_transaction($pdo);

    $stmtOp = $pdo->prepare('SELECT op_id FROM schedule_ops WHERE op_id = :op_id');
    $stmtOp->execute([':op_id' => $opId]);
    if ($stmtOp->fetch()) {
        // 幂等：操作已存在，直接返回当前状态
        $stmtCell = $pdo->prepare('SELECT value, version, updated_at, updated_by FROM schedule_cells WHERE team = :team AND day = :day AND emp = :emp');
        $stmtCell->execute([':team' => $team, ':day' => $day, ':emp' => $emp]);
        $cell = $stmtCell->fetch();
        commit($pdo);
        if ($cell) {
            respond_json([
                'applied' => true,
                'value' => $cell['value'],
                'version' => (int)$cell['version'],
                'updated_at' => (int)$cell['updated_at'],
                'updated_by' => $cell['updated_by'],
            ]);
        }
        respond_json([
            'applied' => false,
            'reason' => 'missing_cell',
        ], 404);
    }

    // 若不存在单元格，先插入初始行
    $stmtInsertCell = $pdo->prepare('INSERT OR IGNORE INTO schedule_cells (team, day, emp, value, version, updated_at, updated_by) VALUES (:team, :day, :emp, :value, 0, :ts, :by)');
    $stmtInsertCell->execute([
        ':team' => $team,
        ':day' => $day,
        ':emp' => $emp,
        ':value' => $newValue,
        ':ts' => $now,
        ':by' => $userId,
    ]);

    $stmtInsertOp = $pdo->prepare('INSERT OR IGNORE INTO schedule_ops (op_id, team, day, emp, base_version, new_value, user_id, ts) VALUES (:op_id, :team, :day, :emp, :base_version, :new_value, :user_id, :ts)');
    $stmtInsertOp->execute([
        ':op_id' => $opId,
        ':team' => $team,
        ':day' => $day,
        ':emp' => $emp,
        ':base_version' => $baseVersion,
        ':new_value' => $newValue,
        ':user_id' => $userId,
        ':ts' => $now,
    ]);

    if ($stmtInsertOp->rowCount() === 0) {
        // 再次检查防止竞态
        $stmtOp2 = $pdo->prepare('SELECT op_id FROM schedule_ops WHERE op_id = :op_id');
        $stmtOp2->execute([':op_id' => $opId]);
        if ($stmtOp2->fetch()) {
            $stmtCell = $pdo->prepare('SELECT value, version, updated_at, updated_by FROM schedule_cells WHERE team = :team AND day = :day AND emp = :emp');
            $stmtCell->execute([':team' => $team, ':day' => $day, ':emp' => $emp]);
            $cell = $stmtCell->fetch();
            commit($pdo);
            if ($cell) {
                respond_json([
                    'applied' => true,
                    'value' => $cell['value'],
                    'version' => (int)$cell['version'],
                    'updated_at' => (int)$cell['updated_at'],
                    'updated_by' => $cell['updated_by'],
                ]);
            }
            respond_json([
                'applied' => false,
                'reason' => 'missing_cell',
            ], 404);
        }
    }

    $stmtUpdate = $pdo->prepare('UPDATE schedule_cells SET value = :value, version = version + 1, updated_at = :ts, updated_by = :by WHERE team = :team AND day = :day AND emp = :emp AND version = :base_version');
    $stmtUpdate->execute([
        ':value' => $newValue,
        ':ts' => $now,
        ':by' => $userId,
        ':team' => $team,
        ':day' => $day,
        ':emp' => $emp,
        ':base_version' => $baseVersion,
    ]);

    if ($stmtUpdate->rowCount() === 0) {
        // CAS 失败：删除刚插入的操作记录
        $pdo->prepare('DELETE FROM schedule_ops WHERE op_id = :op_id')->execute([':op_id' => $opId]);
        rollback($pdo);
        respond_json([
            'applied' => false,
            'reason' => 'conflict',
        ], 409);
    }

    // 更新操作时间戳（确保排序）
    $pdo->prepare('UPDATE schedule_ops SET ts = :ts WHERE op_id = :op_id')->execute([
        ':ts' => $now,
        ':op_id' => $opId,
    ]);

    $stmtCell = $pdo->prepare('SELECT value, version, updated_at, updated_by FROM schedule_cells WHERE team = :team AND day = :day AND emp = :emp');
    $stmtCell->execute([':team' => $team, ':day' => $day, ':emp' => $emp]);
    $cell = $stmtCell->fetch();

    commit($pdo);

    log_op($opId, [
        'team' => $team,
        'day' => $day,
        'emp' => $emp,
        'new_value' => $newValue,
        'user_id' => $userId,
        'version' => $cell ? (int)$cell['version'] : null,
    ]);

    respond_json([
        'applied' => true,
        'value' => $cell['value'],
        'version' => (int)$cell['version'],
        'updated_at' => (int)$cell['updated_at'],
        'updated_by' => $cell['updated_by'],
    ]);
} catch (Throwable $e) {
    rollback($pdo);
    respond_json(['error' => 'internal_error', 'message' => $e->getMessage()], 500);
}
