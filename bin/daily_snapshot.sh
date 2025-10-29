#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DAY="${1:-$(date +%F)}"
NOTE="每日自动快照"

SNAP_DAY="$DAY" SNAP_NOTE="$NOTE" php <<'PHP'
<?php
require __DIR__ . '/../api/common.php';

$day = getenv('SNAP_DAY');
$note = getenv('SNAP_NOTE');
$pdo = acquire_db();
$stmt = $pdo->prepare('SELECT DISTINCT team FROM schedule_cells WHERE day = :day');
$stmt->execute([':day' => $day]);
$teams = $stmt->fetchAll(PDO::FETCH_COLUMN);
foreach ($teams as $team) {
    $cells = $pdo->prepare('SELECT emp, value, version, updated_at, updated_by FROM schedule_cells WHERE team = :team AND day = :day ORDER BY emp ASC');
    $cells->execute([':team' => $team, ':day' => $day]);
    $rows = $cells->fetchAll();
    $snapId = 'cron_' . bin2hex(random_bytes(8));
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
}

echo "完成 {$day} 快照，共处理团队：" . count($teams) . "\n";
PHP
