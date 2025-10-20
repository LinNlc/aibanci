<?php
declare(strict_types=1);

/**
 * 排班助手 后端（无登录版 / PHP + SQLite）
 * 路径：/api/index.php
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');

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
  CREATE TABLE IF NOT EXISTS org_config (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    payload TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
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
function cn_week(string $ymd): string {
  $w = date('w', strtotime($ymd)); // 0..6
  return ['日','一','二','三','四','五','六'][$w] ?? '';
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
    send_json(['user' => ['username' => 'admin', 'display_name' => '管理员']]);

  case $method === 'POST' && $path === '/login':
    send_json(['ok' => true, 'user' => ['username' => 'admin', 'display_name' => '管理员']]);

  case $method === 'POST' && $path === '/logout':
    send_json(['ok' => true]);

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

    if (!$row) {
      $viewStart = $start ?: date('Y-m-01');
      $viewEnd = $end ?: date('Y-m-t');
      $historyProfile = compute_history_profile($pdo, $team, $start ?: null, $historyYearStart);
      send_json([
        'team'      => $team,
        'viewStart' => $viewStart,
        'viewEnd'   => $viewEnd,
        'start'     => $viewStart,
        'end'       => $viewEnd,
        'employees' => [],
        'data'      => (object)[],
        'note'      => '',
        'created_at'=> null,
        'created_by_name' => null,
        'version_id'=> null,
        'versionId' => null,
        'historyProfile' => $historyProfile,
        'yearlyOptimize' => false,
      ]);
    } else {
      $result = build_schedule_payload($row, $team);
      $rangeStart = $start ?: ($row['view_start'] ?? null);
      $result['historyProfile'] = compute_history_profile($pdo, $team, $rangeStart, $historyYearStart);
      send_json($result);
    }

  // 保存（新版本）——乐观锁：baseVersionId 不等于最新时返回 409
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

    if (!$team || !$vs || !$ve || !is_array($emps) || !is_array($data)) {
      send_error('参数不合法', 400);
    }

    $pdo = db();
    $stmt = $pdo->prepare("
      SELECT id FROM schedule_versions
      WHERE team=? AND view_start=? AND view_end=?
      ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$team, $vs, $ve]);
    $cur = $stmt->fetch();
    $latestId = $cur ? (int)$cur['id'] : null;

    if ($latestId !== null && $base !== null && (int)$base !== $latestId) {
      send_error('保存冲突：已有新版本', 409, ['code'=>409,'latest_version_id'=>$latestId]);
    }

    $snapshot = [
      'team' => $team,
      'viewStart' => $vs,
      'viewEnd' => $ve,
      'start' => $vs,
      'end' => $ve,
      'employees' => array_values($emps),
      'data' => $data,
      'note' => $note,
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
      'operator' => $operator ?: '管理员',
    ];
    $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
    if ($snapshotJson === false) {
      $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    $employeesJson = json_encode(array_values($emps), JSON_UNESCAPED_UNICODE);
    if ($employeesJson === false) {
      $employeesJson = json_encode(array_values($emps), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]';
    }
    $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($dataJson === false) {
      $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    $stmt = $pdo->prepare("
      INSERT INTO schedule_versions(team, view_start, view_end, employees, data, note, created_by_name, payload)
      VALUES(?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
      $team, $vs, $ve,
      $employeesJson,
      $dataJson,
      $note,
      $operator ?: '管理员',
      $snapshotJson
    ]);
    $newId = (int)$pdo->lastInsertId();
    send_json(['ok'=>true, 'version_id'=>$newId]);

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
    send_json(['ok' => true]);

  // 导出：优先 XLSX，失败回退 CSV
  case $method === 'GET' && $path === '/export/xlsx':
    $team  = (string)($_GET['team']  ?? 'default');
    $start = (string)($_GET['start'] ?? '');
    $end   = (string)($_GET['end']   ?? '');
    if (!$team || !$start || !$end) send_error('参数缺失', 400);

    $pdo = db();
    $stmt = $pdo->prepare("
      SELECT employees, data FROM schedule_versions
      WHERE team=? AND view_start=? AND view_end=?
      ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$team, $start, $end]);
    $row = $stmt->fetch();
    $employees = $row ? (json_decode($row['employees'], true) ?: []) : [];
    $data = $row ? (json_decode($row['data'], true) ?: []) : [];

    $dates = ymd_range($start, $end);
    $header = array_merge(['日期','星期'], $employees);

    $hasSpreadsheet = is_file(__DIR__ . '/vendor/autoload.php');
    if ($hasSpreadsheet) {
      require_once __DIR__ . '/vendor/autoload.php';
      try {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $col = 1;
        foreach ($header as $h) $sheet->setCellValueByColumnAndRow($col++, 1, $h);
        $r = 2;
        foreach ($dates as $d) {
          $rowVals = [$d, '周'.cn_week($d)];
          foreach ($employees as $e) $rowVals[] = $data[$d][$e] ?? '';
          $col = 1;
          foreach ($rowVals as $v) $sheet->setCellValueByColumnAndRow($col++, $r, $v);
          $r++;
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="排班_'.$start.'_'.$end.'.xlsx"');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
      } catch (\Throwable $e) { /* 回退 CSV */ }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="排班_'.$start.'_'.$end.'.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($out, $header);
    foreach ($dates as $d) {
      $rowVals = [$d, '周'.cn_week($d)];
      foreach ($employees as $e) $rowVals[] = $data[$d][$e] ?? '';
      fputcsv($out, $rowVals);
    }
    fclose($out);
    exit;

  default:
    send_error('Not Found', 404);
}
