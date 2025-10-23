<?php
declare(strict_types=1);

/**
 * 排班助手 后端（无登录版 / PHP + SQLite）
 * 路径：/api/index.php
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');

if (!defined('ADMIN_LOGIN_USERNAME')) {
  define('ADMIN_LOGIN_USERNAME', 'admin');
}
if (!defined('ADMIN_LOGIN_PASSWORD')) {
  define('ADMIN_LOGIN_PASSWORD', '19971109');
}

// ===== SQLite 连接 & 初始化 =====
$legacyDbFile = '/opt/1panel/apps/openresty/openresty/www/sites/xn--wyuz77ayygl2b/index/api/data/data.sqlite';
$defaultDbDir = __DIR__ . '/data';
$defaultDbFile = $defaultDbDir . '/data.sqlite';

$dbFile = $defaultDbFile;
if (is_file($legacyDbFile)) {
  $dbFile = $legacyDbFile;
} elseif (is_dir(dirname($legacyDbFile)) && is_writable(dirname($legacyDbFile))) {
  $dbFile = $legacyDbFile;
} else {
  @mkdir($defaultDbDir, 0770, true);
}

if (!defined('DB_FILE')) {
  define('DB_FILE', $dbFile);
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec('PRAGMA journal_mode = WAL;');
  $pdo->exec('PRAGMA synchronous = NORMAL;');
  $pdo->exec('PRAGMA busy_timeout = 5000;');

  // 只有排班版本表（无 users）
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS schedule_versions (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      team TEXT NOT NULL,
      view_start TEXT NOT NULL, -- YYYY-MM-DD
      view_end TEXT NOT NULL,   -- YYYY-MM-DD
      employees TEXT NOT NULL,  -- JSON array
      data TEXT NOT NULL,       -- JSON object: { 'YYYY-MM-DD': { '张三':'夜', ... } }
      note TEXT,
      created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
      created_by_name TEXT,     -- 记录操作者名字（无登录时来自前端 operator）
      payload TEXT              -- 完整配置快照 JSON
    );
  ");
  try {
    $pdo->exec('ALTER TABLE schedule_versions ADD COLUMN payload TEXT');
  } catch (Throwable $e) {
    // 已有 payload 列时忽略
  }
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sv_team_range ON schedule_versions(team, view_start, view_end, id);");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS team_states (
      team TEXT PRIMARY KEY,
      payload TEXT NOT NULL,
      version INTEGER NOT NULL DEFAULT 1,
      updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
      updated_by TEXT,
      last_backup_at TEXT
    );
  ");

  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_team_states_updated_at ON team_states(updated_at);");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS team_activity_logs (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      team TEXT NOT NULL,
      operator TEXT,
      action TEXT NOT NULL,
      detail TEXT,
      created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
  ");

  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_team_logs_team_created_at ON team_activity_logs(team, created_at DESC, id DESC);");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS org_config (
      id INTEGER PRIMARY KEY CHECK (id = 1),
      payload TEXT NOT NULL,
      updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS schedule_cells (
      team TEXT NOT NULL,
      day  TEXT NOT NULL,
      emp  TEXT NOT NULL,
      value TEXT NOT NULL,
      version INTEGER NOT NULL,
      updated_at TEXT NOT NULL,
      updated_by TEXT,
      PRIMARY KEY(team, day, emp)
    );
  ");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_schedule_cells_team_day ON schedule_cells(team, day);");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS schedule_ops (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      team TEXT NOT NULL,
      day  TEXT NOT NULL,
      emp  TEXT NOT NULL,
      from_value TEXT,
      to_value   TEXT,
      client_id  TEXT,
      client_seq INTEGER,
      server_version INTEGER NOT NULL UNIQUE,
      base_cell_version INTEGER,
      cell_version INTEGER NOT NULL,
      conflict INTEGER NOT NULL DEFAULT 0,
      actor TEXT,
      created_at TEXT NOT NULL
    );
  ");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_schedule_ops_team_version ON schedule_ops(team, server_version);");
  $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_schedule_ops_client ON schedule_ops(team, client_id, client_seq);");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS live_versions (
      team TEXT PRIMARY KEY,
      version INTEGER NOT NULL
    );
  ");
  return $pdo;
}

// ===== 工具函数 =====
function json_input(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $arr = json_decode($raw, true);
  return is_array($arr) ? $arr : [];
}
function send_json($data, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}
function send_error(string $message, int $status = 400, array $extra = []): void {
  send_json(['message' => $message] + $extra, $status);
}
function ymd_range(string $start, string $end): array {
  $out = [];
  $s = strtotime($start);
  $e = strtotime($end);
  if ($s === false || $e === false || $s > $e) return $out;
  for ($t = $s; $t <= $e; $t += 86400) $out[] = date('Y-m-d', $t);
  return $out;
}

function is_valid_ymd(string $value): bool {
  return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}
function cn_week(string $ymd): string {
  $w = date('w', strtotime($ymd)); // 0..6
  return ['日','一','二','三','四','五','六'][$w] ?? '';
}

function now_local(): string {
  return date('Y-m-d H:i:s');
}

function fetch_org_config_payload(PDO $pdo, bool $forceReload = false): array {
  static $cached = null;
  if ($forceReload) {
    $cached = null;
  }
  if ($cached !== null) {
    return $cached;
  }
  $stmt = $pdo->query('SELECT payload FROM org_config WHERE id = 1 LIMIT 1');
  $row = $stmt->fetch();
  $payload = [];
  if ($row && isset($row['payload'])) {
    $decoded = decode_json_assoc($row['payload']);
    if ($decoded) {
      $payload = $decoded;
    }
  }
  $cached = $payload;
  return $payload;
}

function backup_settings(PDO $pdo): array {
  $config = fetch_org_config_payload($pdo);
  $backup = [];
  if (isset($config['backup']) && is_array($config['backup'])) {
    $backup = $config['backup'];
  }
  $interval = 10;
  if (isset($backup['intervalMinutes'])) {
    $interval = (int)$backup['intervalMinutes'];
  } elseif (isset($backup['interval_minutes'])) {
    $interval = (int)$backup['interval_minutes'];
  }
  if ($interval < 1) $interval = 10;

  $limit = 50;
  if (isset($backup['limit'])) {
    $limit = (int)$backup['limit'];
  } elseif (isset($backup['maxCount'])) {
    $limit = (int)$backup['maxCount'];
  }
  if ($limit < 1) $limit = 50;

  return [
    'interval' => $interval,
    'limit' => $limit,
  ];
}

function append_team_log(PDO $pdo, string $team, string $operator, string $action, string $detail = ''): void {
  $stmt = $pdo->prepare('INSERT INTO team_activity_logs(team, operator, action, detail) VALUES(?,?,?,?)');
  $stmt->execute([
    $team,
    $operator,
    $action,
    $detail,
  ]);
}

function send_schedule_export(array $header, array $rows, string $filenameBase): void {
  $hasSpreadsheet = is_file(__DIR__ . '/vendor/autoload.php');
  if ($hasSpreadsheet) {
    require_once __DIR__ . '/vendor/autoload.php';
    try {
      $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
      $sheet = $spreadsheet->getActiveSheet();
      $col = 1;
      foreach ($header as $value) {
        $sheet->setCellValueByColumnAndRow($col++, 1, $value);
      }
      $rowIndex = 2;
      foreach ($rows as $rowVals) {
        $col = 1;
        foreach ($rowVals as $cell) {
          $sheet->setCellValueByColumnAndRow($col++, $rowIndex, $cell);
        }
        $rowIndex++;
      }
      header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      header('Content-Disposition: attachment; filename="' . rawurlencode($filenameBase) . '.xlsx"');
      $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
      $writer->save('php://output');
      exit;
    } catch (\Throwable $e) {
      // 回退到 CSV
    }
  }

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
  fputcsv($out, $header);
  foreach ($rows as $rowVals) {
    fputcsv($out, $rowVals);
  }
  fclose($out);
  exit;
}

function decode_json_assoc(?string $json): array {
  if ($json === null || $json === '') return [];
  $decoded = json_decode($json, true);
  return is_array($decoded) ? $decoded : [];
}

function build_schedule_payload(array $row, string $teamFallback): array {
  $employees = array_values(decode_json_assoc($row['employees'] ?? ''));
  $dataRaw = decode_json_assoc($row['data'] ?? '');
  $payloadExtra = decode_json_assoc($row['payload'] ?? '');
  $base = [
    'team' => $row['team'] ?? $teamFallback,
    'viewStart' => $row['view_start'] ?? '',
    'viewEnd' => $row['view_end'] ?? '',
    'start' => $row['view_start'] ?? '',
    'end' => $row['view_end'] ?? '',
    'employees' => $employees,
    'data' => $dataRaw,
    'note' => $row['note'] ?? '',
    'version_id' => isset($row['id']) ? (int)$row['id'] : null,
    'versionId' => isset($row['id']) ? (int)$row['id'] : null,
    'created_at' => $row['created_at'] ?? null,
    'created_by_name' => $row['created_by_name'] ?? null,
  ];
  $merged = $payloadExtra ? array_replace($base, $payloadExtra) : $base;
  if (empty($merged['team'])) $merged['team'] = $teamFallback;
  if (empty($merged['viewStart'])) $merged['viewStart'] = $base['viewStart'];
  if (empty($merged['viewEnd'])) $merged['viewEnd'] = $base['viewEnd'];
  if (empty($merged['start'])) $merged['start'] = $merged['viewStart'];
  if (empty($merged['end'])) $merged['end'] = $merged['viewEnd'];
  if (!isset($merged['note'])) $merged['note'] = $base['note'];
  if (!isset($merged['version_id'])) $merged['version_id'] = $base['version_id'];
  if (!isset($merged['versionId'])) $merged['versionId'] = $base['versionId'];
  if (!isset($merged['employees']) || !is_array($merged['employees'])) {
    $merged['employees'] = $employees;
  } else {
    $merged['employees'] = array_values($merged['employees']);
  }
  if (!isset($merged['yearlyOptimize'])) {
    $merged['yearlyOptimize'] = false;
  }
  $dataMerged = $merged['data'] ?? [];
  if (!is_array($dataMerged)) {
    $merged['data'] = (object)[];
  } elseif (!count($dataMerged)) {
    $merged['data'] = (object)[];
  } else {
    $merged['data'] = $dataMerged;
  }
  return $merged;
}

function compute_history_profile(PDO $pdo, string $team, ?string $beforeStart = null, ?string $yearStart = null): array {
  $profile = [
    'shiftTotals' => [],
    'periodCount' => 0,
    'lastAssignments' => [],
    'ranges' => [],
  ];

  if ($beforeStart !== null && $beforeStart === '') {
    $beforeStart = null;
  }
  if ($yearStart !== null && $yearStart === '') {
    $yearStart = null;
  }

  $sql = "SELECT id, view_start, view_end, payload, employees, data FROM schedule_versions WHERE team=?";
  $params = [$team];
  if ($beforeStart) {
    $sql .= " AND view_end < ?";
    $params[] = $beforeStart;
  }
  $sql .= " ORDER BY view_end DESC, id DESC LIMIT 24";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();
  if (!$rows) {
    return $profile;
  }

  $lastAssignmentDay = null;

  foreach ($rows as $index => $row) {
    $payload = decode_json_assoc($row['payload'] ?? '');
    $data = $payload['data'] ?? decode_json_assoc($row['data'] ?? '');
    if (!is_array($data)) {
      $data = [];
    }
    $employees = $payload['employees'] ?? json_decode($row['employees'] ?? '[]', true);
    if (!is_array($employees)) {
      $employees = [];
    }
    foreach ($employees as $emp) {
      if (!isset($profile['shiftTotals'][$emp])) {
        $profile['shiftTotals'][$emp] = ['white' => 0, 'mid' => 0, 'mid2' => 0, 'night' => 0, 'total' => 0];
      }
    }
    $hasEligibleDay = false;
    foreach ($data as $day => $assignments) {
      if (!is_array($assignments)) continue;
      if ($beforeStart && strcmp((string)$day, (string)$beforeStart) >= 0) continue;
      if ($yearStart && strcmp((string)$day, (string)$yearStart) < 0) continue;
      $hasEligibleDay = true;
      foreach ($assignments as $emp => $val) {
        if (!isset($profile['shiftTotals'][$emp])) {
          $profile['shiftTotals'][$emp] = ['white' => 0, 'mid' => 0, 'mid2' => 0, 'night' => 0, 'total' => 0];
        }
        switch ($val) {
          case '白':
            $profile['shiftTotals'][$emp]['white']++;
            $profile['shiftTotals'][$emp]['total']++;
            break;
          case '中1':
            $profile['shiftTotals'][$emp]['mid']++;
            $profile['shiftTotals'][$emp]['total']++;
            break;
          case '中2':
            $profile['shiftTotals'][$emp]['mid2']++;
            $profile['shiftTotals'][$emp]['total']++;
            break;
          case '夜':
            $profile['shiftTotals'][$emp]['night']++;
            $profile['shiftTotals'][$emp]['total']++;
            break;
          default:
            break;
        }
      }
      if ($lastAssignmentDay === null || strcmp((string)$day, (string)$lastAssignmentDay) > 0) {
        $lastAssignmentDay = $day;
        $profile['lastAssignments'] = is_array($assignments) ? $assignments : [];
      }
    }
    if ($hasEligibleDay) {
      $profile['periodCount']++;
      $profile['ranges'][] = [
        'start' => $row['view_start'] ?? '',
        'end' => $row['view_end'] ?? '',
        'id' => isset($row['id']) ? (int)$row['id'] : null,
      ];
    }
  }

  return $profile;
}

function normalize_import_date($value): ?string {
  if ($value instanceof \DateTimeInterface) {
    return $value->format('Y-m-d');
  }
  if (is_numeric($value)) {
    if (class_exists('\\PhpOffice\\PhpSpreadsheet\\Shared\\Date')) {
      try {
        $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value);
        if ($dt instanceof \DateTimeInterface) {
          return $dt->format('Y-m-d');
        }
      } catch (Throwable $e) {
        // ignore and fall back
      }
    }
    $timestamp = (int)round(((float)$value - 25569) * 86400);
    if ($timestamp > 0) {
      return gmdate('Y-m-d', $timestamp);
    }
  }
  if (is_string($value)) {
    $str = trim($value);
  } else {
    $str = trim((string)$value);
  }
  if ($str === '') return null;
  $str = str_replace(['年', '月', '日', '.', '/'], ['-', '-', '', '-', '-'], $str);
  if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $str, $m)) {
    $y = (int)$m[1];
    $mo = (int)$m[2];
    $d = (int)$m[3];
    if (checkdate($mo, $d, $y)) {
      return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
  }
  if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $str, $m)) {
    $mo = (int)$m[1];
    $d = (int)$m[2];
    $y = (int)$m[3];
    if (checkdate($mo, $d, $y)) {
      return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
  }
  return null;
}

function realtime_enabled(): bool {
  static $cached = null;
  if ($cached !== null) {
    return $cached;
  }
  $env = getenv('REALTIME_ENABLED');
  if ($env === false || $env === '') {
    $cached = true;
    return $cached;
  }
  $env = strtolower(trim((string)$env));
  $cached = !in_array($env, ['0', 'false', 'no', 'off'], true);
  return $cached;
}

function current_server_time(): string {
  return gmdate('c');
}

function fetch_live_version(PDO $pdo, string $team): int {
  $stmt = $pdo->prepare('SELECT version FROM live_versions WHERE team=? LIMIT 1');
  $stmt->execute([$team]);
  $row = $stmt->fetch();
  return $row ? (int)$row['version'] : 0;
}

function persist_live_version(PDO $pdo, string $team, int $version): void {
  $stmt = $pdo->prepare('UPDATE live_versions SET version=? WHERE team=?');
  $stmt->execute([$version, $team]);
  if ($stmt->rowCount() === 0) {
    $insert = $pdo->prepare('INSERT INTO live_versions(team, version) VALUES(?, ?)');
    $insert->execute([$team, $version]);
  }
}

function begin_immediate_transaction(PDO $pdo): void {
  if ($pdo->inTransaction()) {
    return;
  }
  $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
}

function aggregate_schedule_cells(PDO $pdo, string $team, string $start, string $end, array $preferredEmployees = []): array {
  $dates = ymd_range($start, $end);
  $data = [];
  $versions = [];
  $meta = [];
  foreach ($dates as $day) {
    $data[$day] = [];
    $versions[$day] = [];
    $meta[$day] = [];
  }
  $stmt = $pdo->prepare('SELECT day, emp, value, version, updated_at, updated_by FROM schedule_cells WHERE team=? AND day BETWEEN ? AND ? ORDER BY day ASC, emp ASC');
  $stmt->execute([$team, $start, $end]);
  $employeesSet = [];
  $hasRows = false;
  while ($row = $stmt->fetch()) {
    $day = $row['day'];
    $emp = $row['emp'];
    if (!is_string($day) || !is_string($emp)) continue;
    if (!isset($data[$day])) {
      $data[$day] = [];
      $versions[$day] = [];
      $meta[$day] = [];
    }
    $value = (string)($row['value'] ?? '');
    $data[$day][$emp] = $value;
    $versions[$day][$emp] = isset($row['version']) ? (int)$row['version'] : 0;
    $meta[$day][$emp] = [
      'updated_at' => $row['updated_at'] ?? null,
      'updated_by' => $row['updated_by'] ?? null,
    ];
    $employeesSet[$emp] = true;
    $hasRows = true;
  }
  $orderedEmployees = [];
  foreach ($preferredEmployees as $emp) {
    if (!is_string($emp)) continue;
    if (isset($employeesSet[$emp])) {
      $orderedEmployees[] = $emp;
      unset($employeesSet[$emp]);
    } else {
      $orderedEmployees[] = $emp;
    }
  }
  foreach ($employeesSet as $emp => $_) {
    if (!in_array($emp, $orderedEmployees, true)) {
      $orderedEmployees[] = $emp;
    }
  }
  return [
    'data' => $data,
    'versions' => $versions,
    'meta' => $meta,
    'employees' => $orderedEmployees,
    'hasData' => $hasRows,
  ];
}

class SnapshotConflictException extends RuntimeException {
  private int $latestVersion;

  public function __construct(string $message, int $latestVersion) {
    parent::__construct($message, 409);
    $this->latestVersion = $latestVersion;
  }

  public function getLatestVersion(): int {
    return $this->latestVersion;
  }
}

function persist_snapshot(PDO $pdo, array $params): array {
  $team = (string)($params['team'] ?? '');
  $viewStart = (string)($params['viewStart'] ?? '');
  $viewEnd = (string)($params['viewEnd'] ?? '');
  if ($team === '' || !is_valid_ymd($viewStart) || !is_valid_ymd($viewEnd) || $viewStart > $viewEnd) {
    throw new InvalidArgumentException('无法保存：快照参数不合法');
  }

  $employees = $params['employees'] ?? [];
  if (!is_array($employees)) {
    $employees = [];
  }
  $employees = array_values(array_map(static function ($item) {
    return is_string($item) ? $item : (string)$item;
  }, $employees));

  $data = $params['data'] ?? [];
  if (!is_array($data)) {
    $data = [];
  }

  $note = (string)($params['note'] ?? '');
  $operator = trim((string)($params['operator'] ?? '管理员')) ?: '管理员';
  $baseVersionId = $params['baseVersionId'] ?? null;

  $snapshot = [
    'team' => $team,
    'viewStart' => $viewStart,
    'viewEnd' => $viewEnd,
    'start' => $params['start'] ?? $viewStart,
    'end' => $params['end'] ?? $viewEnd,
    'employees' => $employees,
    'data' => $data,
    'note' => $note,
  ];

  $extra = $params['extra'] ?? [];
  if (is_array($extra)) {
    foreach ($extra as $key => $value) {
      $snapshot[$key] = $value;
    }
  }

  if (array_key_exists('cellVersions', $params)) {
    $snapshot['cellVersions'] = $params['cellVersions'];
  }
  if (array_key_exists('cellMeta', $params)) {
    $snapshot['cellMeta'] = $params['cellMeta'];
  }

  $now = now_local();
  $manageTransaction = !$pdo->inTransaction();
  if ($manageTransaction) {
    $pdo->beginTransaction();
  }

  try {
    $stmt = $pdo->prepare('SELECT version, last_backup_at FROM team_states WHERE team=? LIMIT 1');
    $stmt->execute([$team]);
    $stateRow = $stmt->fetch();
    $currentVersion = $stateRow ? (int)$stateRow['version'] : null;
    $lastBackupAt = $stateRow['last_backup_at'] ?? null;

    if ($currentVersion !== null && $baseVersionId !== null) {
      $baseInt = (int)$baseVersionId;
      if ($baseInt < $currentVersion) {
        throw new SnapshotConflictException('保存冲突：已有新版本', $currentVersion);
      }
      if ($baseInt > $currentVersion) {
        throw new SnapshotConflictException('保存冲突：版本号无效', $currentVersion);
      }
    }

    $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
    if ($snapshotJson === false) {
      $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    $employeesJson = json_encode($employees, JSON_UNESCAPED_UNICODE);
    if ($employeesJson === false) {
      $employeesJson = json_encode($employees, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]';
    }

    $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($dataJson === false) {
      $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    $newVersion = ($currentVersion ?? 0) + 1;
    if ($currentVersion === null) {
      $insert = $pdo->prepare('INSERT INTO team_states(team, payload, version, updated_at, updated_by, last_backup_at) VALUES(?,?,?,?,?,NULL)');
      $insert->execute([$team, $snapshotJson, $newVersion, $now, $operator]);
    } else {
      $update = $pdo->prepare('UPDATE team_states SET payload=?, version=?, updated_at=?, updated_by=? WHERE team=?');
      $update->execute([$snapshotJson, $newVersion, $now, $operator, $team]);
    }

    $settings = backup_settings($pdo);
    $intervalMinutes = max(1, (int)($settings['interval'] ?? 10));
    $limitCount = max(1, (int)($settings['limit'] ?? 50));

    $shouldBackup = false;
    if (!$lastBackupAt) {
      $shouldBackup = true;
    } else {
      $lastTs = strtotime((string)$lastBackupAt);
      if ($lastTs === false) {
        $shouldBackup = true;
      } else {
        $shouldBackup = (strtotime($now) - $lastTs) >= ($intervalMinutes * 60);
      }
    }

    $backupCreatedAt = $lastBackupAt;
    if ($shouldBackup) {
      $stmt = $pdo->prepare(
        'INSERT INTO schedule_versions(team, view_start, view_end, employees, data, note, created_by_name, payload) VALUES(?,?,?,?,?,?,?,?)'
      );
      $stmt->execute([
        $team,
        $viewStart,
        $viewEnd,
        $employeesJson,
        $dataJson,
        $note !== '' ? $note : '自动备份',
        $operator,
        $snapshotJson,
      ]);
      $backupCreatedAt = $now;

      if ($limitCount > 0) {
        $listStmt = $pdo->prepare('SELECT id FROM schedule_versions WHERE team=? ORDER BY created_at DESC, id DESC LIMIT -1 OFFSET ?');
        $listStmt->execute([$team, $limitCount]);
        $toDelete = $listStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($toDelete) {
          $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
          $del = $pdo->prepare("DELETE FROM schedule_versions WHERE id IN ($placeholders)");
          $del->execute($toDelete);
        }
      }

      $markBackup = $pdo->prepare('UPDATE team_states SET last_backup_at=? WHERE team=?');
      $markBackup->execute([$backupCreatedAt, $team]);
    }

    $summary = sprintf('更新排班：%s ~ %s，人员 %d 名', $viewStart, $viewEnd, count($employees));
    if ($note !== '') {
      $summary .= '，备注：' . $note;
    }
    append_team_log($pdo, $team, $operator, 'update_schedule', $summary);

    if ($manageTransaction) {
      $pdo->commit();
    }

    return [
      'newVersion' => $newVersion,
      'updatedAt' => $now,
      'lastBackupAt' => $backupCreatedAt,
    ];
  } catch (Throwable $e) {
    if ($manageTransaction && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function read_schedule_rows(string $file): array {
  $rows = [];
  $autoload = __DIR__ . '/vendor/autoload.php';
  if (is_file($autoload)) {
    require_once $autoload;
    try {
      $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file);
      $reader->setReadDataOnly(true);
      $spreadsheet = $reader->load($file);
      $sheet = $spreadsheet->getActiveSheet();
      $highestRow = (int)$sheet->getHighestRow();
      $highestColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
      for ($r = 1; $r <= $highestRow; $r++) {
        $row = [];
        for ($c = 1; $c <= $highestColumn; $c++) {
          $cell = $sheet->getCellByColumnAndRow($c, $r);
          $value = $cell ? $cell->getValue() : null;
          if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $value = $value->getPlainText();
          }
          if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d');
          }
          if (is_string($value)) {
            $value = trim($value);
          }
          $row[] = $value;
        }
        $rows[] = $row;
      }
    } catch (Throwable $e) {
      $rows = [];
    }
  }
  if (!$rows) {
    $handle = fopen($file, 'r');
    if ($handle) {
      while (($cols = fgetcsv($handle)) !== false) {
        $rows[] = array_map(static function ($item) {
          return is_string($item) ? trim($item) : $item;
        }, $cols);
      }
      fclose($handle);
    }
  }
  return $rows;
}

function parse_schedule_from_rows(array $rows): array {
  $rows = array_values(array_filter($rows, static function ($row) {
    return is_array($row);
  }));
  if (!$rows) {
    throw new RuntimeException('文件为空或格式不正确');
  }
  $headerRaw = $rows[0];
  $header = [];
  foreach ($headerRaw as $col) {
    $header[] = is_string($col) ? trim($col) : (is_null($col) ? '' : (string)$col);
  }
  while (count($header) > 0 && $header[count($header) - 1] === '') {
    array_pop($header);
  }
  if (count($header) < 3) {
    throw new RuntimeException('请使用模板填写日期、星期和至少一名员工');
  }
  $employees = [];
  for ($i = 2; $i < count($header); $i++) {
    $name = trim((string)$header[$i]);
    if ($name === '') continue;
    $employees[] = $name;
  }
  $employees = array_values(array_unique($employees));
  if (!$employees) {
    throw new RuntimeException('请在表头（第 3 列开始）填写员工姓名');
  }
  $data = [];
  foreach ($rows as $index => $cols) {
    if ($index === 0) continue;
    if (!is_array($cols)) continue;
    $ymd = normalize_import_date($cols[0] ?? '');
    if (!$ymd) continue;
    if (!isset($data[$ymd])) {
      $data[$ymd] = [];
    }
    foreach ($employees as $empIndex => $emp) {
      $cell = $cols[$empIndex + 2] ?? '';
      if ($cell instanceof \DateTimeInterface) {
        $cell = $cell->format('H:i');
      }
      if (is_array($cell)) {
        $cell = '';
      }
      if (is_numeric($cell) && !is_string($cell)) {
        $cell = (string)$cell;
      }
      $value = is_string($cell) ? trim($cell) : (is_null($cell) ? '' : (string)$cell);
      if ($value === '') continue;
      $data[$ymd][$emp] = $value;
    }
  }
  if (!$data) {
    throw new RuntimeException('未检测到可导入的排班数据');
  }
  ksort($data);
  $start = array_key_first($data);
  $end = array_key_last($data);
  return [$employees, $data, $start, $end];
}

// ===== 路由 =====
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$path = preg_replace('#^/api#', '', $path) ?: '/';
$servingIndex = ($method === 'GET') && ($path === '/' || $path === '/index.html');
if ($servingIndex) {
  $indexFile = __DIR__ . '/index.html';
  if (is_file($indexFile)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($indexFile);
    exit;
  }
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'index.html 缺失';
  exit;
}
if ($method === 'OPTIONS') { http_response_code(204); exit; }

// ===== 接口实现 =====
switch (true) {

  // 无登录版：占位，保证前端兼容
  case $method === 'GET' && $path === '/me':
    send_json(['user' => ['username' => ADMIN_LOGIN_USERNAME, 'display_name' => '超级管理员']]);

  case $method === 'POST' && $path === '/login':
    $in = json_input();
    $username = trim((string)($in['username'] ?? ''));
    $password = (string)($in['password'] ?? '');
    if ($username !== ADMIN_LOGIN_USERNAME) {
      send_error('账号不存在', 404);
    }
    if ($password !== ADMIN_LOGIN_PASSWORD) {
      send_error('账号或密码错误', 401);
    }
    send_json(['ok' => true, 'user' => ['username' => ADMIN_LOGIN_USERNAME, 'display_name' => '超级管理员', 'role' => 'super']]);

  case $method === 'POST' && $path === '/logout':
    send_json(['ok' => true]);

  case $method === 'POST' && $path === '/ops':
    if (!realtime_enabled()) {
      send_error('实时协作未启用', 400);
    }
    $in = json_input();
    $team = (string)($in['team'] ?? '');
    $opsInput = $in['ops'] ?? [];
    $actor = trim((string)($in['actor'] ?? ''));
    if (!$team || !is_array($opsInput) || !$opsInput) {
      send_error('参数不合法', 400);
    }
    $pdo = db();
    $useRealtime = realtime_enabled();
    begin_immediate_transaction($pdo);
    try {
      $liveVersion = fetch_live_version($pdo, $team);
      $results = [];
      $selectCell = $pdo->prepare('SELECT value, version FROM schedule_cells WHERE team=? AND day=? AND emp=? LIMIT 1');
      $upsertCell = $pdo->prepare('INSERT INTO schedule_cells(team, day, emp, value, version, updated_at, updated_by) VALUES(?,?,?,?,?,?,?) ON CONFLICT(team, day, emp) DO UPDATE SET value=excluded.value, version=excluded.version, updated_at=excluded.updated_at, updated_by=excluded.updated_by');
      $insertOp = $pdo->prepare('INSERT INTO schedule_ops(team, day, emp, from_value, to_value, client_id, client_seq, server_version, base_cell_version, cell_version, conflict, actor, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $selectDuplicate = $pdo->prepare('SELECT day, emp, from_value, to_value, client_id, client_seq, server_version, base_cell_version, cell_version, conflict, actor, created_at FROM schedule_ops WHERE team=? AND client_id=? AND client_seq=? LIMIT 1');
      foreach ($opsInput as $item) {
        if (!is_array($item)) continue;
        $day = (string)($item['day'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
          continue;
        }
        $empRaw = (string)($item['emp'] ?? '');
        $emp = trim($empRaw);
        if ($emp === '') {
          continue;
        }
        $empLength = function_exists('mb_strlen') ? mb_strlen($emp) : strlen($emp);
        if ($empLength > 100) {
          if (function_exists('mb_substr')) {
            $emp = mb_substr($emp, 0, 100);
          } else {
            $emp = substr($emp, 0, 100);
          }
        }
        $clientId = trim((string)($item['clientId'] ?? ''));
        if ($clientId !== '') {
          $clientIdLength = function_exists('mb_strlen') ? mb_strlen($clientId) : strlen($clientId);
          if ($clientIdLength > 120) {
            if (function_exists('mb_substr')) {
              $clientId = mb_substr($clientId, 0, 120);
            } else {
              $clientId = substr($clientId, 0, 120);
            }
          }
        }
        $clientSeqRaw = $item['clientSeq'] ?? null;
        $clientSeq = is_numeric($clientSeqRaw) ? (int)$clientSeqRaw : null;
        if ($clientId === '' || $clientSeq === null) {
          continue;
        }
        $dupParams = [$team, $clientId, $clientSeq];
        $selectDuplicate->execute($dupParams);
        $dupRow = $selectDuplicate->fetch();
        if ($dupRow) {
          $dupVersion = isset($dupRow['server_version']) ? (int)$dupRow['server_version'] : 0;
          if ($dupVersion > $liveVersion) {
            $liveVersion = $dupVersion;
          }
          $results[] = [
            'day' => $dupRow['day'],
            'emp' => $dupRow['emp'],
            'value' => $dupRow['to_value'] ?? '',
            'serverVersion' => $dupVersion,
            'appliedCellVersion' => isset($dupRow['cell_version']) ? (int)$dupRow['cell_version'] : null,
            'conflict' => !empty($dupRow['conflict']),
            'clientId' => $clientId,
            'clientSeq' => $clientSeq,
            'updatedAt' => $dupRow['created_at'] ?? null,
            'updatedBy' => $dupRow['actor'] ?? null,
            'baseCellVersion' => isset($dupRow['base_cell_version']) ? (int)$dupRow['base_cell_version'] : null,
            'previousValue' => $dupRow['from_value'] ?? null,
          ];
          continue;
        }
        $valueRaw = (string)($item['to'] ?? $item['value'] ?? '');
        $value = trim($valueRaw);
        if ($value !== '') {
          if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, 20);
          } else {
            $value = substr($value, 0, 20);
          }
        }
        $baseCellVersion = isset($item['baseCellVersion']) ? (int)$item['baseCellVersion'] : null;
        $selectCell->execute([$team, $day, $emp]);
        $cellRow = $selectCell->fetch();
        $prevValue = $cellRow['value'] ?? '';
        $prevVersion = $cellRow ? (int)$cellRow['version'] : 0;
        $newVersion = $prevVersion + 1;
        $conflict = ($baseCellVersion !== null && $baseCellVersion !== $prevVersion);
        $nowIso = current_server_time();
        $upsertCell->execute([$team, $day, $emp, $value, $newVersion, $nowIso, $actor !== '' ? $actor : $clientId]);
        $liveVersion++;
        $insertOp->execute([
          $team,
          $day,
          $emp,
          $prevValue,
          $value,
          $clientId,
          $clientSeq,
          $liveVersion,
          $baseCellVersion,
          $newVersion,
          $conflict ? 1 : 0,
          $actor !== '' ? $actor : null,
          $nowIso,
        ]);
        $results[] = [
          'day' => $day,
          'emp' => $emp,
          'value' => $value,
          'serverVersion' => $liveVersion,
          'appliedCellVersion' => $newVersion,
          'conflict' => $conflict,
          'clientId' => $clientId,
          'clientSeq' => $clientSeq,
          'updatedAt' => $nowIso,
          'updatedBy' => $actor !== '' ? $actor : null,
          'baseCellVersion' => $baseCellVersion,
          'previousValue' => $prevValue,
        ];
      }
      persist_live_version($pdo, $team, $liveVersion);
      $pdo->commit();
      send_json([
        'ok' => true,
        'results' => $results,
        'liveVersion' => $liveVersion,
      ]);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }

  case $method === 'GET' && $path === '/events':
    if (!realtime_enabled()) {
      send_error('实时协作未启用', 404);
    }
    $team = (string)($_GET['team'] ?? '');
    $sinceRaw = $_GET['since'] ?? null;
    $since = is_numeric($sinceRaw) ? (int)$sinceRaw : 0;
    if ($team === '') {
      send_error('参数缺失', 400);
    }
    $pdo = db();
    ignore_user_abort(true);
    set_time_limit(0);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    echo ":connected\n\n";
    echo "event: presence\n";
    echo 'data: {"users":[]}' . "\n\n";
    @ob_flush();
    @flush();
    $current = $since;
    $startedAt = time();
    $lastBeat = time();
    while (!connection_aborted()) {
      $stmt = $pdo->prepare('SELECT server_version, day, emp, from_value, to_value, base_cell_version, cell_version, conflict, actor, created_at, client_id, client_seq FROM schedule_ops WHERE team=? AND server_version>? ORDER BY server_version ASC LIMIT 100');
      $stmt->execute([$team, $current]);
      $rows = $stmt->fetchAll();
      if ($rows) {
        foreach ($rows as $row) {
          $payload = [
            'serverVersion' => (int)$row['server_version'],
            'day' => $row['day'],
            'emp' => $row['emp'],
            'from' => $row['from_value'] ?? '',
            'to' => $row['to_value'] ?? '',
            'baseCellVersion' => isset($row['base_cell_version']) ? (int)$row['base_cell_version'] : null,
            'cellVersion' => isset($row['cell_version']) ? (int)$row['cell_version'] : null,
            'conflict' => !empty($row['conflict']),
            'updatedBy' => $row['actor'] ?? null,
            'at' => $row['created_at'] ?? null,
            'clientId' => $row['client_id'] ?? null,
            'clientSeq' => isset($row['client_seq']) ? (int)$row['client_seq'] : null,
          ];
          $dataLine = json_encode($payload, JSON_UNESCAPED_UNICODE);
          if ($dataLine === false) {
            $dataLine = '{}';
          }
          echo "event: op\n";
          echo 'data: ' . $dataLine . "\n\n";
          $current = (int)$row['server_version'];
        }
        @ob_flush();
        @flush();
      } else {
        if (time() - $lastBeat >= 15) {
          echo ":keepalive\n\n";
          @ob_flush();
          @flush();
          $lastBeat = time();
        }
        if ((time() - $startedAt) > 300) {
          break;
        }
        usleep(500000);
      }
    }
    exit;

  // 读取某团队 + 时间段的最新排班版本
  case $method === 'GET' && $path === '/schedule':
    $team  = (string)($_GET['team']  ?? 'default');
    $start = (string)($_GET['start'] ?? '');
    $end   = (string)($_GET['end']   ?? '');
    $historyYearStart = (string)($_GET['historyYearStart'] ?? ($_GET['history_year_start'] ?? ''));
    if ($historyYearStart === '') {
      $historyYearStart = null;
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyYearStart)) {
      $historyYearStart = null;
    }
    if (!$team) send_error('参数缺失', 400);

    $pdo = db();
    $stateStmt = $pdo->prepare('SELECT payload, version, updated_at, updated_by, last_backup_at FROM team_states WHERE team=? LIMIT 1');
    $stateStmt->execute([$team]);
    $stateRow = $stateStmt->fetch();

    $response = null;

    if ($stateRow) {
      $payloadArr = decode_json_assoc($stateRow['payload'] ?? '');
      if (!$payloadArr) {
        $payloadArr = [];
      }
      $employeesArr = $payloadArr['employees'] ?? [];
      if (!is_array($employeesArr)) {
        $employeesArr = [];
      }
      $dataArr = $payloadArr['data'] ?? [];
      if (!is_array($dataArr)) {
        $dataArr = [];
      }
      $employeesJson = json_encode(array_values($employeesArr), JSON_UNESCAPED_UNICODE);
      if ($employeesJson === false) {
        $employeesJson = json_encode(array_values($employeesArr), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]';
      }
      $dataJson = json_encode($dataArr, JSON_UNESCAPED_UNICODE);
      if ($dataJson === false) {
        $dataJson = json_encode($dataArr, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
      }
      $payloadJson = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);
      if ($payloadJson === false) {
        $payloadJson = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
      }
      $viewStartPayload = $payloadArr['viewStart'] ?? ($payloadArr['start'] ?? ($payloadArr['view_start'] ?? ''));
      $viewEndPayload = $payloadArr['viewEnd'] ?? ($payloadArr['end'] ?? ($payloadArr['view_end'] ?? ''));
      $rowForBuild = [
        'id' => $stateRow['version'] ?? null,
        'team' => $team,
        'view_start' => $viewStartPayload,
        'view_end' => $viewEndPayload,
        'employees' => $employeesJson,
        'data' => $dataJson,
        'note' => $payloadArr['note'] ?? '',
        'created_at' => $stateRow['updated_at'] ?? null,
        'created_by_name' => $stateRow['updated_by'] ?? null,
        'payload' => $payloadJson,
      ];
      $response = build_schedule_payload($rowForBuild, $team);
      $version = isset($stateRow['version']) ? (int)$stateRow['version'] : null;
      $response['versionId'] = $version;
      $response['version_id'] = $version;
      $response['updated_at'] = $stateRow['updated_at'] ?? null;
      $response['updated_by'] = $stateRow['updated_by'] ?? null;
      $response['lastBackupAt'] = $stateRow['last_backup_at'] ?? null;
    }

    if (!$response && !$useRealtime) {
      $row = null;
      if ($start && $end) {
        $stmt = $pdo->prepare("
          SELECT id, team, employees, data, view_start, view_end, note, created_at, created_by_name, payload
          FROM schedule_versions
          WHERE team=? AND view_start=? AND view_end=?
          ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$team, $start, $end]);
        $row = $stmt->fetch();
      }
      if (!$row) {
        $stmt = $pdo->prepare("
          SELECT id, team, employees, data, view_start, view_end, note, created_at, created_by_name, payload
          FROM schedule_versions
          WHERE team=?
          ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$team]);
        $row = $stmt->fetch();
      }
      if ($row) {
        $response = build_schedule_payload($row, $team);
      }
    }

    if (!$response) {
      $viewStart = $start ?: date('Y-m-01');
      $viewEnd = $end ?: date('Y-m-t');
      $response = [
        'team'      => $team,
        'viewStart' => $viewStart,
        'viewEnd'   => $viewEnd,
        'start'     => $viewStart,
        'end'       => $viewEnd,
        'employees' => [],
        'data'      => [],
        'note'      => '',
        'created_at'=> null,
        'created_by_name' => null,
        'version_id'=> null,
        'versionId' => null,
        'yearlyOptimize' => false,
      ];
    }

    $rangeStart = is_valid_ymd((string)$start) ? (string)$start : '';
    $rangeEnd = is_valid_ymd((string)$end) ? (string)$end : '';
    $fallbackStart = $response['viewStart'] ?? ($response['start'] ?? null);
    $fallbackEnd = $response['viewEnd'] ?? ($response['end'] ?? null);
    if ($rangeStart === '' && is_valid_ymd((string)$fallbackStart)) {
      $rangeStart = (string)$fallbackStart;
    }
    if ($rangeEnd === '' && is_valid_ymd((string)$fallbackEnd)) {
      $rangeEnd = (string)$fallbackEnd;
    }

    if ($useRealtime) {
      if ($rangeStart === '' || $rangeEnd === '') {
        $rangeStart = date('Y-m-01');
        $rangeEnd = date('Y-m-t');
      }
      if ($rangeStart > $rangeEnd) {
        $tmp = $rangeStart;
        $rangeStart = $rangeEnd;
        $rangeEnd = $tmp;
      }
      $preferredEmployees = is_array($response['employees'] ?? null) ? $response['employees'] : [];
      $aggregation = aggregate_schedule_cells($pdo, $team, $rangeStart, $rangeEnd, $preferredEmployees);
      $response['data'] = $aggregation['data'];
      $response['employees'] = $aggregation['employees'] ?: $preferredEmployees;
      $response['cellVersions'] = $aggregation['versions'];
      $response['cellMeta'] = $aggregation['meta'];
      $response['liveVersion'] = fetch_live_version($pdo, $team);
      $response['serverTime'] = current_server_time();
      $response['realtime'] = true;
      $response['viewStart'] = $rangeStart;
      $response['viewEnd'] = $rangeEnd;
      if (empty($response['start'])) {
        $response['start'] = $rangeStart;
      }
      if (empty($response['end'])) {
        $response['end'] = $rangeEnd;
      }
    } else {
      if ($rangeStart === '' && is_valid_ymd((string)$fallbackStart)) {
        $rangeStart = (string)$fallbackStart;
      }
      if ($rangeEnd === '' && is_valid_ymd((string)$fallbackEnd)) {
        $rangeEnd = (string)$fallbackEnd;
      }
      if (!isset($response['data']) || !is_array($response['data'])) {
        $response['data'] = [];
      }
      $response['realtime'] = false;
    }

    $historyRangeStart = $rangeStart !== '' ? $rangeStart : null;
    $response['historyProfile'] = compute_history_profile($pdo, $team, $historyRangeStart, $historyYearStart);
    send_json($response);

  // 保存（实时版本）——基于团队状态的乐观锁
  case $method === 'POST' && $path === '/schedule/save':
    $in   = json_input();
    $team = (string)($in['team'] ?? 'default');
    $vs   = (string)($in['viewStart'] ?? '');
    $ve   = (string)($in['viewEnd'] ?? '');
    $emps = $in['employees'] ?? [];
    $data = $in['data'] ?? new stdClass();
    $base = $in['baseVersionId'] ?? null; // 可能为 null
    $note = (string)($in['note'] ?? '');
    $operator = trim((string)($in['operator'] ?? '管理员'));

    $pdo = db();
    $useRealtime = realtime_enabled();
    if (!is_valid_ymd($vs) || !is_valid_ymd($ve) || $vs > $ve) {
      send_error('排班范围不合法', 400);
    }
    if ($useRealtime) {
      $aggregation = aggregate_schedule_cells($pdo, $team, $vs, $ve, is_array($emps) ? $emps : []);
      $emps = $aggregation['employees'];
      $data = $aggregation['data'];
    }

    if (!$team || !$vs || !$ve || !is_array($emps) || !is_array($data)) {
      send_error('参数不合法', 400);
    }

    $extra = [
      'adminDays' => $in['adminDays'] ?? null,
      'restPrefs' => $in['restPrefs'] ?? null,
      'nightRules' => $in['nightRules'] ?? null,
      'nightWindows' => $in['nightWindows'] ?? null,
      'nightOverride' => $in['nightOverride'] ?? null,
      'rMin' => $in['rMin'] ?? null,
      'rMax' => $in['rMax'] ?? null,
      'pMin' => $in['pMin'] ?? null,
      'pMax' => $in['pMax'] ?? null,
      'mixMax' => $in['mixMax'] ?? null,
      'shiftColors' => $in['shiftColors'] ?? null,
      'staffingAlerts' => $in['staffingAlerts'] ?? null,
      'batchChecked' => $in['batchChecked'] ?? null,
      'albumSelected' => $in['albumSelected'] ?? null,
      'albumWhiteHour' => $in['albumWhiteHour'] ?? null,
      'albumMidHour' => $in['albumMidHour'] ?? null,
      'albumRangeStartMonth' => $in['albumRangeStartMonth'] ?? null,
      'albumRangeEndMonth' => $in['albumRangeEndMonth'] ?? null,
      'albumMaxDiff' => $in['albumMaxDiff'] ?? null,
      'albumAssignments' => $in['albumAssignments'] ?? null,
      'albumAutoNote' => $in['albumAutoNote'] ?? null,
      'albumHistory' => $in['albumHistory'] ?? null,
      'historyProfile' => $in['historyProfile'] ?? null,
      'yearlyOptimize' => $in['yearlyOptimize'] ?? null,
    ];

    $cellVersionsPayload = $useRealtime ? ($aggregation['versions'] ?? []) : ((isset($in['cellVersions']) && is_array($in['cellVersions'])) ? $in['cellVersions'] : []);
    $cellMetaPayload = $useRealtime ? ($aggregation['meta'] ?? []) : ((isset($in['cellMeta']) && is_array($in['cellMeta'])) ? $in['cellMeta'] : []);

    try {
      $result = persist_snapshot($pdo, [
        'team' => $team,
        'viewStart' => $vs,
        'viewEnd' => $ve,
        'start' => $vs,
        'end' => $ve,
        'employees' => array_values($emps),
        'data' => $data,
        'note' => $note,
        'operator' => $operator ?: '管理员',
        'baseVersionId' => $base,
        'extra' => $extra,
        'cellVersions' => $cellVersionsPayload,
        'cellMeta' => $cellMetaPayload,
      ]);
    } catch (SnapshotConflictException $conflict) {
      $latest = $conflict->getLatestVersion();
      send_error($conflict->getMessage(), 409, [
        'code' => 409,
        'latest_version_id' => $latest,
        'latestVersionId' => $latest,
      ]);
    } catch (InvalidArgumentException $invalid) {
      send_error($invalid->getMessage(), 400);
    }

    send_json([
      'ok' => true,
      'version_id' => $result['newVersion'],
      'versionId' => $result['newVersion'],
      'updated_at' => $result['updatedAt'],
      'updatedAt' => $result['updatedAt'],
      'last_backup_at' => $result['lastBackupAt'],
      'lastBackupAt' => $result['lastBackupAt'],
    ]);

  case $method === 'POST' && $path === '/schedule/version/restore':
    if (!realtime_enabled()) {
      send_error('实时协作未启用', 400);
    }
    $in = json_input();
    $team = trim((string)($in['team'] ?? ''));
    $versionId = (int)($in['versionId'] ?? 0);
    if ($team === '' || $versionId <= 0) {
      send_error('参数不合法', 400);
    }
    $operator = trim((string)($in['operator'] ?? '管理员'));
    $noteOverride = array_key_exists('note', $in) ? (string)$in['note'] : '';
    $baseVersionId = $in['baseVersionId'] ?? null;

    $pdo = db();
    begin_immediate_transaction($pdo);
    try {
      $stmt = $pdo->prepare('SELECT id, team, employees, data, view_start, view_end, note, payload FROM schedule_versions WHERE id=? LIMIT 1');
      $stmt->execute([$versionId]);
      $row = $stmt->fetch();
      if (!$row) {
        $pdo->rollBack();
        send_error('历史版本不存在', 404);
      }
      $versionTeam = $row['team'] ?? '';
      if ($versionTeam !== '' && $versionTeam !== $team) {
        $pdo->rollBack();
        send_error('该备份不属于指定团队', 400);
      }

      $payload = build_schedule_payload($row, $team);
      $targetStart = (string)($payload['viewStart'] ?? ($payload['start'] ?? ''));
      $targetEnd = (string)($payload['viewEnd'] ?? ($payload['end'] ?? ''));
      if (!is_valid_ymd($targetStart) || !is_valid_ymd($targetEnd) || $targetStart > $targetEnd) {
        $pdo->rollBack();
        send_error('历史版本时间范围不合法', 400);
      }

      $targetEmployees = [];
      if (isset($payload['employees']) && is_array($payload['employees'])) {
        foreach ($payload['employees'] as $emp) {
          if (!is_string($emp)) continue;
          $targetEmployees[] = $emp;
        }
      }
      $targetEmployees = array_values(array_unique($targetEmployees));

      $targetDataRaw = $payload['data'] ?? [];
      if ($targetDataRaw instanceof stdClass) {
        $targetDataRaw = (array)$targetDataRaw;
      }
      if (!is_array($targetDataRaw)) {
        $targetDataRaw = [];
      }
      $targetData = [];
      foreach ($targetDataRaw as $day => $rowData) {
        if ($rowData instanceof stdClass) {
          $rowData = (array)$rowData;
        }
        if (!is_array($rowData)) {
          $rowData = [];
        }
        $normalized = [];
        foreach ($rowData as $emp => $val) {
          $empStr = trim((string)$emp);
          if ($empStr === '') continue;
          if ($val instanceof stdClass) {
            $val = '';
          } elseif (is_array($val)) {
            $val = '';
          }
          $normalized[$empStr] = trim($val === null ? '' : (string)$val);
        }
        $targetData[$day] = $normalized;
      }

      $dates = ymd_range($targetStart, $targetEnd);
      if (!$dates) {
        $dates = [$targetStart];
      }

      $currentAggregation = aggregate_schedule_cells($pdo, $team, $targetStart, $targetEnd, $targetEmployees);
      $currentData = $currentAggregation['data'];

      $ops = [];
      foreach ($dates as $day) {
        $targetRow = isset($targetData[$day]) && is_array($targetData[$day]) ? $targetData[$day] : [];
        $currentRow = isset($currentData[$day]) && is_array($currentData[$day]) ? $currentData[$day] : [];
        $empSet = array_unique(array_merge(array_keys($targetRow), array_keys($currentRow)));
        foreach ($empSet as $empName) {
          $emp = trim((string)$empName);
          if ($emp === '') continue;
          $targetVal = $targetRow[$emp] ?? '';
          if (!is_string($targetVal)) {
            $targetVal = $targetVal === null ? '' : (string)$targetVal;
          }
          $targetVal = trim($targetVal);
          $currentVal = $currentRow[$emp] ?? '';
          if (!is_string($currentVal)) {
            $currentVal = $currentVal === null ? '' : (string)$currentVal;
          }
          $currentVal = trim($currentVal);
          if ($targetVal === $currentVal) {
            continue;
          }
          $ops[] = ['day' => $day, 'emp' => $emp, 'value' => $targetVal];
        }
      }

      $liveVersion = fetch_live_version($pdo, $team);
      $selectCell = $pdo->prepare('SELECT value, version FROM schedule_cells WHERE team=? AND day=? AND emp=? LIMIT 1');
      $upsertCell = $pdo->prepare('INSERT INTO schedule_cells(team, day, emp, value, version, updated_at, updated_by) VALUES(?,?,?,?,?,?,?) ON CONFLICT(team, day, emp) DO UPDATE SET value=excluded.value, version=excluded.version, updated_at=excluded.updated_at, updated_by=excluded.updated_by');
      $insertOp = $pdo->prepare('INSERT INTO schedule_ops(team, day, emp, from_value, to_value, client_id, client_seq, server_version, base_cell_version, cell_version, conflict, actor, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $actorLabel = $operator !== '' ? $operator : '管理员';
      try {
        $randomSuffix = bin2hex(random_bytes(6));
      } catch (Throwable $e) {
        $randomSuffix = substr(hash('sha256', microtime(true) . mt_rand()), 0, 12);
      }
      $clientId = substr(sprintf('restore#%s#%s', $versionId, $randomSuffix), 0, 120);

      foreach ($ops as $index => $opItem) {
        $day = $opItem['day'];
        $emp = $opItem['emp'];
        $value = $opItem['value'];

        $selectCell->execute([$team, $day, $emp]);
        $cellRow = $selectCell->fetch();
        $prevValue = is_string($cellRow['value'] ?? null) ? (string)$cellRow['value'] : '';
        $prevVersion = $cellRow ? (int)$cellRow['version'] : 0;

        $valueTrim = $value;
        if ($valueTrim !== '') {
          if (function_exists('mb_substr')) {
            $valueTrim = mb_substr($valueTrim, 0, 20);
          } else {
            $valueTrim = substr($valueTrim, 0, 20);
          }
        }

        if (function_exists('mb_strlen')) {
          if (mb_strlen($emp) > 100) {
            $emp = mb_substr($emp, 0, 100);
          }
        } elseif (strlen($emp) > 100) {
          $emp = substr($emp, 0, 100);
        }

        $newVersion = $prevVersion + 1;
        $nowIso = current_server_time();
        $upsertCell->execute([$team, $day, $emp, $valueTrim, $newVersion, $nowIso, $actorLabel]);
        $liveVersion++;
        $insertOp->execute([
          $team,
          $day,
          $emp,
          $prevValue,
          $valueTrim,
          $clientId,
          $index + 1,
          $liveVersion,
          $prevVersion,
          $newVersion,
          0,
          $actorLabel,
          $nowIso,
        ]);
      }

      persist_live_version($pdo, $team, $liveVersion);

      $extra = [];
      foreach (['adminDays','restPrefs','nightRules','nightWindows','nightOverride','rMin','rMax','pMin','pMax','mixMax','shiftColors','staffingAlerts','batchChecked','albumSelected','albumWhiteHour','albumMidHour','albumRangeStartMonth','albumRangeEndMonth','albumMaxDiff','albumAssignments','albumAutoNote','albumHistory','historyProfile','yearlyOptimize'] as $key) {
        if (array_key_exists($key, $payload)) {
          $extra[$key] = $payload[$key];
        }
      }

      $postAggregation = aggregate_schedule_cells($pdo, $team, $targetStart, $targetEnd, $targetEmployees);
      $noteForSave = $noteOverride !== '' ? $noteOverride : (string)($payload['note'] ?? '');
      if ($noteForSave === '') {
        $noteForSave = '从历史版本 #' . $versionId . ' 恢复';
      }

      $snapshotResult = persist_snapshot($pdo, [
        'team' => $team,
        'viewStart' => $targetStart,
        'viewEnd' => $targetEnd,
        'start' => $targetStart,
        'end' => $targetEnd,
        'employees' => $postAggregation['employees'] ?: $targetEmployees,
        'data' => $postAggregation['data'],
        'note' => $noteForSave,
        'operator' => $actorLabel,
        'baseVersionId' => $baseVersionId,
        'extra' => $extra,
        'cellVersions' => $postAggregation['versions'],
        'cellMeta' => $postAggregation['meta'],
      ]);

      $pdo->commit();

      send_json([
        'ok' => true,
        'appliedOps' => count($ops),
        'version_id' => $snapshotResult['newVersion'],
        'versionId' => $snapshotResult['newVersion'],
        'updated_at' => $snapshotResult['updatedAt'],
        'updatedAt' => $snapshotResult['updatedAt'],
        'last_backup_at' => $snapshotResult['lastBackupAt'],
        'lastBackupAt' => $snapshotResult['lastBackupAt'],
        'liveVersion' => $liveVersion,
      ]);
    } catch (SnapshotConflictException $conflict) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $latest = $conflict->getLatestVersion();
      send_error($conflict->getMessage(), 409, [
        'code' => 409,
        'latest_version_id' => $latest,
        'latestVersionId' => $latest,
      ]);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }

  // 历史版本列表
  case $method === 'GET' && $path === '/schedule/versions':
    $team  = (string)($_GET['team']  ?? 'default');
    $start = (string)($_GET['start'] ?? '');
    $end   = (string)($_GET['end']   ?? '');
    if (!$team) send_error('参数缺失', 400);

    $pdo = db();
    $start = $start ?: '';
    $end = $end ?: '';
    $hasRange = $start !== '' && $end !== '' && strtotime($start) !== false && strtotime($end) !== false;
    if ($hasRange && $start > $end) {
      $tmp = $start;
      $start = $end;
      $end = $tmp;
    }

    if ($hasRange) {
      $stmt = $pdo->prepare("
        SELECT id, view_start, view_end, created_at, note, created_by_name
        FROM schedule_versions
        WHERE team=? AND view_start >= ? AND view_end <= ?
        ORDER BY created_at DESC, id DESC
        LIMIT 200
      ");
      $stmt->execute([$team, $start, $end]);
    } else {
      $stmt = $pdo->prepare("
        SELECT id, view_start, view_end, created_at, note, created_by_name
        FROM schedule_versions
        WHERE team=?
        ORDER BY created_at DESC, id DESC
        LIMIT 200
      ");
      $stmt->execute([$team]);
    }
    $rows = $stmt->fetchAll() ?: [];
    send_json(['versions' => array_map(function($r){
      return [
        'id' => (int)$r['id'],
        'view_start' => $r['view_start'] ?? null,
        'view_end' => $r['view_end'] ?? null,
        'created_at' => $r['created_at'],
        'note' => $r['note'] ?? '',
        'created_by_name' => $r['created_by_name'] ?? '管理员',
      ];
    }, $rows)]);

  // 按版本 ID 读取
  case $method === 'GET' && $path === '/version':
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) send_error('参数缺失', 400);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, team, employees, data, view_start, view_end, note, created_at, created_by_name, payload FROM schedule_versions WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) send_error('未找到', 404);
    $team = $row['team'] ?? 'default';
    $result = build_schedule_payload($row, $team);
    $rangeStart = $result['viewStart'] ?? ($row['view_start'] ?? null);
    $result['historyProfile'] = compute_history_profile($pdo, $team, $rangeStart, null);
    send_json($result);

  // 删除历史版本
  case $method === 'POST' && $path === '/schedule/version/delete':
    $in = json_input();
    $id = (int)($in['id'] ?? 0);
    $team = trim((string)($in['team'] ?? ''));
    if ($id <= 0 || $team === '') send_error('参数缺失', 400);
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM schedule_versions WHERE id = ? AND team = ?');
    $stmt->execute([$id, $team]);
    if ($stmt->rowCount() === 0) send_error('记录不存在或已删除', 404);
    send_json(['ok' => true, 'deleted' => true]);

  case $method === 'GET' && $path === '/team/logs':
    $team = trim((string)($_GET['team'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 50);
    if ($team === '') send_error('参数缺失', 400);
    if ($limit <= 0) $limit = 50;
    if ($limit > 200) $limit = 200;
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, operator, action, detail, created_at FROM team_activity_logs WHERE team=? ORDER BY created_at DESC, id DESC LIMIT ?');
    $stmt->execute([$team, $limit]);
    $rows = $stmt->fetchAll() ?: [];
    $logs = array_map(function($row) {
      return [
        'id' => isset($row['id']) ? (int)$row['id'] : null,
        'operator' => $row['operator'] ?? '管理员',
        'action' => $row['action'] ?? '',
        'detail' => $row['detail'] ?? '',
        'created_at' => $row['created_at'] ?? null,
      ];
    }, $rows);
    send_json(['logs' => $logs]);

  case $method === 'GET' && $path === '/org-config':
    $pdo = db();
    $stmt = $pdo->query('SELECT payload, updated_at FROM org_config WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch();
    $payload = [];
    if ($row && isset($row['payload'])) {
      $decoded = decode_json_assoc($row['payload']);
      if ($decoded) {
        $payload = $decoded;
      }
    }
    send_json([
      'config' => $payload,
      'updated_at' => $row['updated_at'] ?? null,
    ]);

  case $method === 'POST' && $path === '/org-config':
    $in = json_input();
    $config = $in['config'] ?? [];
    if (is_object($config)) {
      $config = json_decode(json_encode($config, JSON_UNESCAPED_UNICODE), true);
    }
    if (!is_array($config)) {
      send_error('配置格式错误', 400);
    }
    $json = json_encode($config, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    if ($json === false) {
      send_error('配置保存失败', 500);
    }
    $pdo = db();
    $stmt = $pdo->prepare("
      INSERT INTO org_config(id, payload, updated_at)
      VALUES(1, ?, datetime('now','localtime'))
      ON CONFLICT(id) DO UPDATE SET payload=excluded.payload, updated_at=excluded.updated_at
    ");
    $stmt->execute([$json]);
    fetch_org_config_payload($pdo, true);
    send_json(['ok' => true]);

  case $method === 'GET' && $path === '/template/xlsx':
    $filename = '排班导入模板';
    $employees = ['员工A', '员工B'];
    $dates = [];
    $today = new DateTime('now');
    for ($i = 0; $i < 7; $i++) {
      $d = clone $today;
      $d->modify('+' . $i . ' day');
      $ymd = $d->format('Y-m-d');
      $dates[] = $ymd;
    }
    $header = array_merge(['日期', '星期'], $employees);
    $rows = [];
    foreach ($dates as $idx => $ymd) {
      $row = [$ymd, '周' . cn_week($ymd)];
      foreach ($employees as $empIndex => $empName) {
        $row[] = ($idx + $empIndex) % 2 === 0 ? '白' : '休';
      }
      $rows[] = $row;
    }
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (is_file($autoload)) {
      require_once $autoload;
      try {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $col = 1;
        foreach ($header as $value) {
          $sheet->setCellValueByColumnAndRow($col++, 1, $value);
        }
        $r = 2;
        foreach ($rows as $rowVals) {
          $col = 1;
          foreach ($rowVals as $cell) {
            $sheet->setCellValueByColumnAndRow($col++, $r, $cell);
          }
          $r++;
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '.xlsx"');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
      } catch (Throwable $e) {
        // fall through to CSV
      }
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, $header);
    foreach ($rows as $rowVals) {
      fputcsv($out, $rowVals);
    }
    fclose($out);
    exit;

  // 导出：优先 XLSX，失败回退 CSV（读取已保存版本）
  case $method === 'GET' && $path === '/export/xlsx':
    $team  = (string)($_GET['team']  ?? 'default');
    $start = (string)($_GET['start'] ?? '');
    $end   = (string)($_GET['end']   ?? '');
    if (!$team || !$start || !$end) send_error('参数缺失', 400);

    $pdo = db();
    $stmt = $pdo->prepare("SELECT employees, data FROM schedule_versions
      WHERE team=? AND view_start=? AND view_end=?
      ORDER BY id DESC LIMIT 1");
    $stmt->execute([$team, $start, $end]);
    $row = $stmt->fetch();
    $employees = $row ? (json_decode($row['employees'], true) ?: []) : [];
    $data = $row ? (json_decode($row['data'], true) ?: []) : [];

    $dates = ymd_range($start, $end);
    $header = array_merge(['日期','星期'], $employees);
    $rows = [];
    foreach ($dates as $d) {
      $rowVals = [$d, '周' . cn_week($d)];
      foreach ($employees as $e) {
        $rowVals[] = $data[$d][$e] ?? '';
      }
      $rows[] = $rowVals;
    }
    send_schedule_export($header, $rows, '排班_' . $start . '_' . $end);

  case $method === 'POST' && $path === '/export/xlsx':
    $in = json_input();
    $team  = (string)($in['team'] ?? 'default');
    $start = (string)($in['start'] ?? '');
    $end   = (string)($in['end'] ?? '');
    if (!$team || !$start || !$end) send_error('参数缺失', 400);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
      send_error('时间格式错误', 400);
    }
    if ($start > $end) {
      $tmp = $start;
      $start = $end;
      $end = $tmp;
    }

    $employeesInput = $in['employees'] ?? [];
    $employees = [];
    if (is_array($employeesInput)) {
      foreach ($employeesInput as $name) {
        $trimmed = trim((string)$name);
        if ($trimmed === '') continue;
        if (!in_array($trimmed, $employees, true)) {
          $employees[] = $trimmed;
        }
      }
    }

    $dataInput = $in['data'] ?? [];
    $data = [];
    if (is_array($dataInput)) {
      foreach ($dataInput as $day => $row) {
        if (!is_string($day) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) continue;
        if (!is_array($row)) $row = [];
        $data[$day] = $row;
      }
    }

    $dates = ymd_range($start, $end);
    $header = array_merge(['日期','星期'], $employees);
    $rows = [];
    foreach ($dates as $d) {
      $rowVals = [$d, '周' . cn_week($d)];
      foreach ($employees as $e) {
        $rowVals[] = $data[$d][$e] ?? '';
      }
      $rows[] = $rowVals;
    }
    send_schedule_export($header, $rows, '排班_' . $start . '_' . $end);

  case $method === 'POST' && $path === '/import/xlsx':
    if (empty($_FILES['file'])) {
      send_error('请上传 Excel/CSV 文件', 400);
    }
    $file = $_FILES['file'];
    $error = $file['error'] ?? UPLOAD_ERR_OK;
    if ($error !== UPLOAD_ERR_OK) {
      send_error('文件上传失败', 400, ['code' => $error]);
    }
    $tmpName = $file['tmp_name'] ?? '';
    if (!$tmpName || !is_file($tmpName)) {
      send_error('文件上传失败', 400);
    }
    try {
      $rows = read_schedule_rows($tmpName);
      [$employees, $data, $start, $end] = parse_schedule_from_rows($rows);
    } catch (Throwable $e) {
      send_error($e->getMessage() ?: '导入失败', 400);
    }
    send_json([
      'ok' => true,
      'employees' => $employees,
      'data' => $data,
      'start' => $start,
      'end' => $end,
      'message' => '导入成功',
    ]);

  default:
    send_error('Not Found', 404);
}
