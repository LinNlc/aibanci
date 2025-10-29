<?php
require __DIR__ . '/common.php';

require_method('POST');
require_auth();

$payload = require_post_json();
$action = isset($payload['action']) ? trim((string)$payload['action']) : '';
$team = isset($payload['team']) ? trim((string)$payload['team']) : '';
$day = isset($payload['day']) ? trim((string)$payload['day']) : '';
$emp = isset($payload['emp']) ? trim((string)$payload['emp']) : '';

if (!in_array($action, ['acquire', 'renew', 'release'], true) || $team === '' || $day === '' || $emp === '' || !validate_date($day)) {
    respond_json(['error' => 'invalid_parameter'], 422);
}

$pdo = acquire_db();
$userId = get_current_user_id() ?? 'guest';
$cfg = load_config();
$ttl = (int)($cfg['lock_ttl'] ?? 30);
$now = time();
$expires = $now + $ttl;

try {
    begin_transaction($pdo);
    $stmt = $pdo->prepare('SELECT locked_by, lock_until FROM schedule_softlocks WHERE team = :team AND day = :day AND emp = :emp');
    $stmt->execute([':team' => $team, ':day' => $day, ':emp' => $emp]);
    $row = $stmt->fetch();
    $risk = false;

    if ($action === 'acquire') {
        if ($row) {
            $lockedBy = $row['locked_by'];
            $lockUntil = (int)$row['lock_until'];
            if ($lockUntil <= $now || $lockedBy === $userId) {
                $pdo->prepare('UPDATE schedule_softlocks SET locked_by = :by, lock_until = :until WHERE team = :team AND day = :day AND emp = :emp')
                    ->execute([':by' => $userId, ':until' => $expires, ':team' => $team, ':day' => $day, ':emp' => $emp]);
            } else {
                $risk = true;
                $pdo->prepare('UPDATE schedule_softlocks SET locked_by = :by, lock_until = :until WHERE team = :team AND day = :day AND emp = :emp')
                    ->execute([':by' => $userId, ':until' => $expires, ':team' => $team, ':day' => $day, ':emp' => $emp]);
            }
        } else {
            $pdo->prepare('INSERT INTO schedule_softlocks (team, day, emp, locked_by, lock_until) VALUES (:team, :day, :emp, :by, :until)')
                ->execute([':team' => $team, ':day' => $day, ':emp' => $emp, ':by' => $userId, ':until' => $expires]);
        }
        commit($pdo);
        respond_json([
            'locked' => true,
            'lock_until' => $expires,
            'locked_by' => $userId,
            'risk' => $risk,
        ]);
    }

    if ($action === 'renew') {
        if ($row && $row['locked_by'] === $userId) {
            $pdo->prepare('UPDATE schedule_softlocks SET lock_until = :until WHERE team = :team AND day = :day AND emp = :emp')
                ->execute([':until' => $expires, ':team' => $team, ':day' => $day, ':emp' => $emp]);
            commit($pdo);
            respond_json([
                'locked' => true,
                'lock_until' => $expires,
                'locked_by' => $userId,
                'risk' => false,
            ]);
        }
        if ($row && $row['locked_by'] !== $userId && (int)$row['lock_until'] > $now) {
            rollback($pdo);
            respond_json([
                'locked' => false,
                'lock_until' => (int)$row['lock_until'],
                'locked_by' => $row['locked_by'],
                'risk' => true,
            ], 409);
        }
        // 锁已过期或不存在，重新获取
        rollback($pdo);
        respond_json([
            'locked' => false,
            'lock_until' => 0,
            'locked_by' => null,
            'risk' => false,
        ], 409);
    }

    if ($action === 'release') {
        if ($row && ($row['locked_by'] === $userId || (int)$row['lock_until'] <= $now)) {
            $pdo->prepare('DELETE FROM schedule_softlocks WHERE team = :team AND day = :day AND emp = :emp')
                ->execute([':team' => $team, ':day' => $day, ':emp' => $emp]);
        }
        commit($pdo);
        respond_json([
            'locked' => false,
            'lock_until' => 0,
            'locked_by' => null,
            'risk' => false,
        ]);
    }

    rollback($pdo);
    respond_json(['error' => 'unsupported_action'], 400);
} catch (Throwable $e) {
    rollback($pdo);
    respond_json(['error' => 'internal_error', 'message' => $e->getMessage()], 500);
}
