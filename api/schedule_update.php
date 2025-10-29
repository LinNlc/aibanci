<?php
require __DIR__ . '/common.php';

require_method('POST');
require_page_edit('schedule');

$data = require_post_json();
$teamId = isset($data['team_id']) ? (int)$data['team_id'] : 0;
$day = isset($data['day']) ? (string)$data['day'] : '';
$personId = isset($data['person_id']) ? (int)$data['person_id'] : 0;
$value = array_key_exists('value', $data) ? (string)$data['value'] : '';
$baseVersion = isset($data['base_version']) ? (int)$data['base_version'] : 0;
$opId = isset($data['op_id']) ? trim((string)$data['op_id']) : '';

if ($teamId <= 0 || $personId <= 0 || $day === '' || $opId === '') {
    respond_json(['error' => 'missing_parameters'], 422);
}
if (!validate_date($day)) {
    respond_json(['error' => 'invalid_date'], 422);
}

$pdo = db();
$user = require_auth();
ensure_team_access($teamId, true);

ensure_value_allowed($value, $pdo);
ensure_rate_limit($pdo, (int)$user['id']);

begin_transaction($pdo);
try {
    $opStmt = $pdo->prepare('SELECT op_id FROM schedule_ops WHERE op_id = :op');
    $opStmt->execute([':op' => $opId]);
    if ($opStmt->fetch()) {
        rollback($pdo);
        respond_json(['error' => 'duplicate_operation'], 409);
    }

    $select = $pdo->prepare('SELECT version FROM schedule_cells WHERE team_id = :team AND day = :day AND person_id = :person');
    $select->execute([
        ':team' => $teamId,
        ':day' => $day,
        ':person' => $personId,
    ]);
    $existing = $select->fetch();
    $now = time();
    if ($existing) {
        $currentVersion = (int)$existing['version'];
        if ($currentVersion !== $baseVersion) {
            rollback($pdo);
            respond_json(['error' => 'version_conflict', 'current_version' => $currentVersion], 409);
        }
        $nextVersion = $currentVersion + 1;
        $update = $pdo->prepare('UPDATE schedule_cells SET value = :value, version = :version, updated_at = :ts, updated_by = :uid WHERE team_id = :team AND day = :day AND person_id = :person AND version = :base_version');
        $update->execute([
            ':value' => $value,
            ':version' => $nextVersion,
            ':ts' => $now,
            ':uid' => (int)$user['id'],
            ':team' => $teamId,
            ':day' => $day,
            ':person' => $personId,
            ':base_version' => $baseVersion,
        ]);
        if ($update->rowCount() === 0) {
            rollback($pdo);
            respond_json(['error' => 'version_conflict'], 409);
        }
    } else {
        if ($baseVersion !== 0) {
            rollback($pdo);
            respond_json(['error' => 'version_conflict'], 409);
        }
        $nextVersion = 1;
        $insert = $pdo->prepare('INSERT INTO schedule_cells(team_id, day, person_id, value, version, updated_at, updated_by) VALUES (:team, :day, :person, :value, :version, :ts, :uid)');
        $insert->execute([
            ':team' => $teamId,
            ':day' => $day,
            ':person' => $personId,
            ':value' => $value,
            ':version' => $nextVersion,
            ':ts' => $now,
            ':uid' => (int)$user['id'],
        ]);
    }

    $pdo->prepare('INSERT INTO schedule_ops(op_id, team_id, day, person_id, base_version, new_value, user_id, ts) VALUES (:op, :team, :day, :person, :base, :value, :user, :ts)')->execute([
        ':op' => $opId,
        ':team' => $teamId,
        ':day' => $day,
        ':person' => $personId,
        ':base' => $baseVersion,
        ':value' => $value,
        ':user' => (int)$user['id'],
        ':ts' => $now,
    ]);

    commit($pdo);
} catch (Throwable $e) {
    rollback($pdo);
    throw $e;
}

log_op($opId, [
    'team_id' => $teamId,
    'day' => $day,
    'person_id' => $personId,
    'value' => $value,
    'user_id' => (int)$user['id'],
]);

respond_json([
    'team_id' => $teamId,
    'day' => $day,
    'person_id' => $personId,
    'value' => $value,
    'version' => $existing ? $baseVersion + 1 : 1,
    'updated_at' => time(),
    'updated_by' => [
        'id' => (int)$user['id'],
        'display_name' => $user['display_name'],
    ],
]);
