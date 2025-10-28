#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

${PHP_BIN} <<'PHP'
<?php
require __DIR__ . '/../api/common.php';

date_default_timezone_set('Asia/Shanghai');

$db = get_db();
$now = time();

// 查出全部已有排班的团队+日期组合
$stmt = $db->query('SELECT DISTINCT team, day FROM schedule_cells');
$targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($targets as $target) {
    $team = $target['team'];
    $day = $target['day'];
    if (!$team || !$day) {
        continue;
    }
    $payload = export_day_payload($db, $team, $day);
    $snapId = sprintf('%s_%s_%s_auto', md5($team), $day, $now);
    $insert = $db->prepare('INSERT INTO schedule_snapshots (snap_id, team, day, created_at, note, payload) VALUES (:snap_id, :team, :day, :created_at, :note, :payload)');
    $insert->execute([
        ':snap_id' => $snapId,
        ':team' => $team,
        ':day' => $day,
        ':created_at' => $now,
        ':note' => '自动快照',
        ':payload' => $payload,
    ]);
    echo "已生成快照: {$snapId}\n";
}
PHP
