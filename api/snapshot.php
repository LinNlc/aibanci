<?php
require __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$mode = $_GET['mode'] ?? '';

if ($method === 'POST') {
    require_auth();
    if ($mode === 'restore') {
        restore_snapshot();
    } else {
        create_snapshot();
    }
} elseif ($method === 'GET') {
    list_snapshots();
} else {
    respond_json(['error' => 'method_not_allowed'], 405);
}

function create_snapshot(): void
{
    $team = input_string('team');
    $day = input_string('day');
    $note = input_string('note', false) ?? '';
    if (!$team || !$day || !validate_date($day)) {
        respond_json(['error' => 'invalid_parameter'], 422);
    }
    $pdo = acquire_db();
    $stmt = $pdo->prepare('SELECT emp, value, version, updated_at, updated_by FROM schedule_cells WHERE team = :team AND day = :day ORDER BY emp ASC');
    $stmt->execute([':team' => $team, ':day' => $day]);
    $rows = $stmt->fetchAll();

    $snapId = 'snap_' . bin2hex(random_bytes(8));
    $payload = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = time();

    $pdo->prepare('INSERT INTO schedule_snapshots (snap_id, team, day, created_at, note, payload) VALUES (:id, :team, :day, :created_at, :note, :payload)')->execute([
        ':id' => $snapId,
        ':team' => $team,
        ':day' => $day,
        ':created_at' => $now,
        ':note' => $note,
        ':payload' => $payload,
    ]);

    respond_json(['snap_id' => $snapId, 'created_at' => $now]);
}

function restore_snapshot(): void
{
    $snapId = input_string('snap_id');
    if (!$snapId) {
        respond_json(['error' => 'invalid_parameter'], 422);
    }
    $pdo = acquire_db();
    $stmt = $pdo->prepare('SELECT snap_id, team, day, payload FROM schedule_snapshots WHERE snap_id = :id');
    $stmt->execute([':id' => $snapId]);
    $snap = $stmt->fetch();
    if (!$snap) {
        respond_json(['error' => 'not_found'], 404);
    }

    $team = $snap['team'];
    $day = $snap['day'];
    $payload = json_decode($snap['payload'], true);
    if (!is_array($payload)) {
        respond_json(['error' => 'corrupted_snapshot'], 500);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM schedule_cells WHERE team = :team AND day = :day')->execute([':team' => $team, ':day' => $day]);
        $now = time();
        foreach ($payload as $cell) {
            $emp = $cell['emp'];
            $value = $cell['value'];
            $version = (int)($cell['version'] ?? 0);
            $updatedAt = (int)($cell['updated_at'] ?? $now);
            $updatedBy = $cell['updated_by'] ?? 'snapshot';
            if (!validate_value($value)) {
                continue;
            }
            $pdo->prepare('INSERT OR REPLACE INTO schedule_cells (team, day, emp, value, version, updated_at, updated_by) VALUES (:team, :day, :emp, :value, :version, :updated_at, :updated_by)')
                ->execute([
                    ':team' => $team,
                    ':day' => $day,
                    ':emp' => $emp,
                    ':value' => $value,
                    ':version' => $version,
                    ':updated_at' => $updatedAt,
                    ':updated_by' => $updatedBy,
                ]);
            $opId = 'restore:' . $snapId . ':' . $emp;
            $baseVersion = max(0, $version - 1);
            $pdo->prepare('INSERT OR REPLACE INTO schedule_ops (op_id, team, day, emp, base_version, new_value, user_id, ts) VALUES (:op_id, :team, :day, :emp, :base_version, :new_value, :user_id, :ts)')->execute([
                ':op_id' => $opId,
                ':team' => $team,
                ':day' => $day,
                ':emp' => $emp,
                ':base_version' => $baseVersion,
                ':new_value' => $value,
                ':user_id' => get_current_user_id() ?? 'snapshot',
                ':ts' => $now,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond_json(['error' => 'internal_error', 'message' => $e->getMessage()], 500);
    }

    respond_json(['restored' => true, 'team' => $team, 'day' => $day]);
}

function list_snapshots(): void
{
    $team = input_string('team');
    $day = input_string('day');
    if (!$team || !$day || !validate_date($day)) {
        respond_json(['error' => 'invalid_parameter'], 422);
    }
    $pdo = acquire_db();
    $stmt = $pdo->prepare('SELECT snap_id, created_at, note FROM schedule_snapshots WHERE team = :team AND day = :day ORDER BY created_at DESC');
    $stmt->execute([':team' => $team, ':day' => $day]);
    $rows = $stmt->fetchAll();
    respond_json(['snapshots' => $rows]);
}
