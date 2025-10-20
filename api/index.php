<?php
declare(strict_types=1);

session_start();

if (!headers_sent()) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  header('Pragma: no-cache');
  header('Expires: 0');
}

const STORAGE_FILE = __DIR__ . '/../storage/state.json';

if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool
  {
    if ($needle === '') {
      return true;
    }
    return substr($haystack, 0, strlen($needle)) === $needle;
  }
}

function respond(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function readJsonBody(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false) {
    respond(['message' => '无法读取请求体'], 400);
  }
  if ($raw === '') {
    return [];
  }
  $json = json_decode($raw, true);
  if (!is_array($json)) {
    respond(['message' => 'JSON 格式无效'], 400);
  }
  return $json;
}

function storageDefault(): array {
  return [
    'orgConfig' => [
      'teams' => [
        [
          'id' => 'default',
          'name' => '默认团队',
          'remark' => '',
          'features' => ['albumScheduler' => true],
        ],
      ],
      'accounts' => [
        [
          'username' => 'admin',
          'displayName' => '超级管理员',
          'role' => 'super',
          'passwordHash' => '',
          'menus' => [
            'grid' => ['visible' => true, 'write' => true],
            'batch' => ['visible' => true, 'write' => true],
            'album' => ['visible' => true, 'write' => true],
            'users' => ['visible' => true, 'write' => true],
            'stats' => ['visible' => true, 'write' => true],
            'history' => ['visible' => true, 'write' => true],
            'settings' => ['visible' => true, 'write' => true],
            'roles' => ['visible' => true, 'write' => true],
          ],
          'teamAccess' => ['*'],
        ],
      ],
    ],
    'schedules' => [],
  ];
}

function loadState(): array {
  if (!file_exists(STORAGE_FILE)) {
    $dir = dirname(STORAGE_FILE);
    if (!is_dir($dir)) {
      mkdir($dir, 0775, true);
    }
    $state = storageDefault();
    file_put_contents(STORAGE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $state;
  }
  $raw = file_get_contents(STORAGE_FILE);
  if ($raw === false || $raw === '') {
    return storageDefault();
  }
  $json = json_decode($raw, true);
  if (!is_array($json)) {
    return storageDefault();
  }
  if (!isset($json['orgConfig']) || !is_array($json['orgConfig'])) {
    $json['orgConfig'] = storageDefault()['orgConfig'];
  }
  if (!isset($json['schedules']) || !is_array($json['schedules'])) {
    $json['schedules'] = [];
  }
  return $json;
}

function saveState(array $state): void {
  $dir = dirname(STORAGE_FILE);
  if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
  }
  $tmpFile = tempnam($dir, 'state_');
  if ($tmpFile === false) {
    respond(['message' => '无法创建临时文件'], 500);
  }
  file_put_contents($tmpFile, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  rename($tmpFile, STORAGE_FILE);
}

function currentUser(): ?array {
  return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function requireAuth(?string $role = null): array {
  $user = currentUser();
  if (!$user) {
    respond(['message' => '未登录'], 401);
  }
  if ($role && ($user['role'] ?? '') !== $role) {
    respond(['message' => '权限不足'], 403);
  }
  return $user;
}

function ensureTeamId(string $team): string {
  $team = trim($team);
  if ($team === '') {
    respond(['message' => 'team 参数必填'], 400);
  }
  return $team;
}

function normalizeSchedulePayload(array $payload): array {
  $start = isset($payload['start']) ? (string)$payload['start'] : '';
  $end = isset($payload['end']) ? (string)$payload['end'] : '';
  $team = ensureTeamId((string)($payload['team'] ?? ''));
  if ($start === '' || $end === '') {
    respond(['message' => 'start / end 参数必填'], 400);
  }
  $employees = [];
  if (isset($payload['employees']) && is_array($payload['employees'])) {
    foreach ($payload['employees'] as $emp) {
      if (!is_string($emp)) {
        continue;
      }
      $name = trim($emp);
      if ($name !== '') {
        $employees[] = $name;
      }
    }
  }
  $data = [];
  if (isset($payload['data']) && is_array($payload['data'])) {
    foreach ($payload['data'] as $day => $row) {
      if (!is_string($day)) {
        continue;
      }
      if (!is_array($row)) {
        continue;
      }
      $cleanRow = [];
      foreach ($row as $emp => $val) {
        if (!is_string($emp)) {
          continue;
        }
        $cleanRow[$emp] = is_string($val) ? $val : '';
      }
      $data[$day] = $cleanRow;
    }
  }
  $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
  return [
    'team' => $team,
    'start' => $start,
    'end' => $end,
    'viewStart' => (string)($payload['viewStart'] ?? $start),
    'viewEnd' => (string)($payload['viewEnd'] ?? $end),
    'employees' => $employees,
    'data' => $data,
    'note' => isset($payload['note']) ? (string)$payload['note'] : '',
    'operator' => isset($payload['operator']) ? (string)$payload['operator'] : '管理员',
    'adminDays' => (int)($payload['adminDays'] ?? 0),
    'restPrefs' => isset($payload['restPrefs']) && is_array($payload['restPrefs']) ? $payload['restPrefs'] : [],
    'nightRules' => isset($payload['nightRules']) && is_array($payload['nightRules']) ? $payload['nightRules'] : [],
    'nightWindows' => isset($payload['nightWindows']) && is_array($payload['nightWindows']) ? $payload['nightWindows'] : [],
    'nightOverride' => !empty($payload['nightOverride']),
    'rMin' => isset($payload['rMin']) ? (float)$payload['rMin'] : 0.3,
    'rMax' => isset($payload['rMax']) ? (float)$payload['rMax'] : 0.7,
    'pMin' => isset($payload['pMin']) ? (float)$payload['pMin'] : 0.3,
    'pMax' => isset($payload['pMax']) ? (float)$payload['pMax'] : 0.7,
    'mixMax' => isset($payload['mixMax']) ? (float)$payload['mixMax'] : 1.0,
    'shiftColors' => isset($payload['shiftColors']) && is_array($payload['shiftColors']) ? $payload['shiftColors'] : [],
    'staffingAlerts' => isset($payload['staffingAlerts']) && is_array($payload['staffingAlerts']) ? $payload['staffingAlerts'] : [],
    'batchChecked' => isset($payload['batchChecked']) && is_array($payload['batchChecked']) ? $payload['batchChecked'] : [],
    'albumSelected' => isset($payload['albumSelected']) && is_array($payload['albumSelected']) ? $payload['albumSelected'] : [],
    'albumWhiteHour' => isset($payload['albumWhiteHour']) ? (float)$payload['albumWhiteHour'] : 0.22,
    'albumMidHour' => isset($payload['albumMidHour']) ? (float)$payload['albumMidHour'] : 0.06,
    'albumRangeStartMonth' => isset($payload['albumRangeStartMonth']) ? (string)$payload['albumRangeStartMonth'] : '',
    'albumRangeEndMonth' => isset($payload['albumRangeEndMonth']) ? (string)$payload['albumRangeEndMonth'] : '',
    'albumMaxDiff' => isset($payload['albumMaxDiff']) ? (float)$payload['albumMaxDiff'] : 0.5,
    'albumAssignments' => isset($payload['albumAssignments']) && is_array($payload['albumAssignments']) ? $payload['albumAssignments'] : [],
    'albumAutoNote' => isset($payload['albumAutoNote']) ? (string)$payload['albumAutoNote'] : '',
    'albumHistory' => isset($payload['albumHistory']) && is_array($payload['albumHistory']) ? $payload['albumHistory'] : [],
    'historyProfile' => isset($payload['historyProfile']) ? $payload['historyProfile'] : null,
    'yearlyOptimize' => !empty($payload['yearlyOptimize']),
    'created_at' => $now,
    'updated_at' => $now,
    'baseVersionId' => isset($payload['baseVersionId']) ? (string)$payload['baseVersionId'] : null,
  ];
}

function defaultTeamState(string $team, string $start, string $end): array {
  return [
    'team' => $team,
    'start' => $start,
    'end' => $end,
    'viewStart' => $start,
    'viewEnd' => $end,
    'employees' => [],
    'data' => [],
    'note' => '',
    'operator' => '管理员',
    'adminDays' => 0,
    'restPrefs' => [],
    'nightRules' => [
      'prioritizeInterval' => false,
      'restAfterNight' => true,
      'enforceRestCap' => true,
      'restAfterMid2' => true,
      'allowDoubleMid2' => false,
      'allowNightDay4' => false,
    ],
    'nightWindows' => [['s' => $start, 'e' => $end]],
    'nightOverride' => true,
    'rMin' => 0.3,
    'rMax' => 0.7,
    'pMin' => 0.3,
    'pMax' => 0.7,
    'mixMax' => 1.0,
    'shiftColors' => [
      '白' => '#eef2ff',
      '中1' => '#e0f2fe',
      '中2' => '#cffafe',
      '夜' => '#fee2e2',
      '休' => '#f3f4f6',
    ],
    'staffingAlerts' => [
      'total' => ['threshold' => 0, 'lowColor' => '#fee2e2', 'highColor' => '#dcfce7'],
      'white' => ['threshold' => 0, 'lowColor' => '#fef3c7', 'highColor' => '#bfdbfe'],
      'mid1' => ['threshold' => 0, 'lowColor' => '#fef3c7', 'highColor' => '#bae6fd'],
    ],
    'batchChecked' => [],
    'albumSelected' => [],
    'albumWhiteHour' => 0.22,
    'albumMidHour' => 0.06,
    'albumRangeStartMonth' => substr($start, 0, 7),
    'albumRangeEndMonth' => substr($end, 0, 7),
    'albumMaxDiff' => 0.5,
    'albumAssignments' => [],
    'albumAutoNote' => '',
    'albumHistory' => [],
    'historyProfile' => null,
    'yearlyOptimize' => false,
    'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
    'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
    'baseVersionId' => null,
  ];
}

function &scheduleCollection(array &$state, string $team)
{
  if (!isset($state['schedules'][$team]) || !is_array($state['schedules'][$team])) {
    $state['schedules'][$team] = [
      'versions' => [],
      'latest' => null,
    ];
  }
  return $state['schedules'][$team];
}

function generateVersionId(): string {
  try {
    return gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
  } catch (Throwable $e) {
    return uniqid(gmdate('YmdHis') . '-', true);
  }
}

function hashPassword(string $raw): string {
  return base64_encode($raw);
}

function verifyPassword(?string $hash, string $raw): bool {
  if ($hash === null || $hash === '') {
    return $raw === '';
  }
  return hashPassword($raw) === $hash;
}

function visibleAccount(array $account): array {
  return [
    'username' => $account['username'] ?? '',
    'display_name' => $account['displayName'] ?? ($account['display_name'] ?? ''),
    'role' => $account['role'] ?? 'guest',
    'menus' => $account['menus'] ?? [],
    'teamAccess' => $account['teamAccess'] ?? [],
  ];
}

function streamCsv(array $schedule): void {
  $filename = 'schedule-' . ($schedule['team'] ?? 'default') . '-' . ($schedule['start'] ?? 'start') . '.csv';
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  $out = fopen('php://output', 'w');
  if ($out === false) {
    respond(['message' => '无法创建导出流'], 500);
  }
  $employees = $schedule['employees'] ?? [];
  $days = array_keys($schedule['data'] ?? []);
  sort($days);
  $header = array_merge(['日期'], $employees);
  fputcsv($out, $header);
  foreach ($days as $day) {
    $row = [$day];
    foreach ($employees as $emp) {
      $row[] = $schedule['data'][$day][$emp] ?? '';
    }
    fputcsv($out, $row);
  }
  fclose($out);
  exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
if ($scriptDir && str_starts_with($path, $scriptDir)) {
  $path = substr($path, strlen($scriptDir));
}
$path = '/' . ltrim($path, '/');

try {
  $state = loadState();
  switch ($path) {
    case '/health':
      respond(['status' => 'ok']);

    case '/org-config':
      if ($method === 'GET') {
        respond(['config' => $state['orgConfig'], 'user' => currentUser()]);
      }
      if ($method === 'POST') {
        requireAuth('super');
        $body = readJsonBody();
        $config = $body['config'] ?? null;
        if (!is_array($config)) {
          respond(['message' => 'config 字段缺失'], 400);
        }
        $state['orgConfig'] = $config;
        saveState($state);
        respond(['config' => $config]);
      }
      respond(['message' => '方法不被允许'], 405);

    case '/login':
      if ($method !== 'POST') {
        respond(['message' => '仅支持 POST'], 405);
      }
      $body = readJsonBody();
      $username = trim((string)($body['username'] ?? ''));
      $password = (string)($body['password'] ?? '');
      $account = null;
      foreach ($state['orgConfig']['accounts'] as $acc) {
        if (($acc['username'] ?? '') === $username) {
          $account = $acc;
          break;
        }
      }
      if (!$account || !verifyPassword($account['passwordHash'] ?? '', $password)) {
        respond(['message' => '用户名或密码错误'], 401);
      }
      $user = [
        'username' => $account['username'],
        'display_name' => $account['displayName'] ?? ($account['display_name'] ?? ''),
        'role' => $account['role'] ?? 'guest',
        'menus' => $account['menus'] ?? [],
        'teamAccess' => $account['teamAccess'] ?? ['*'],
      ];
      $_SESSION['user'] = $user;
      respond(['user' => $user]);

    case '/logout':
      if ($method !== 'POST') {
        respond(['message' => '仅支持 POST'], 405);
      }
      $_SESSION = [];
      if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
      }
      session_destroy();
      respond(['ok' => true]);

    case '/schedule':
      if ($method !== 'GET') {
        respond(['message' => '仅支持 GET'], 405);
      }
      $team = ensureTeamId((string)($_GET['team'] ?? 'default'));
      $start = (string)($_GET['start'] ?? date('Y-m-01'));
      $end = (string)($_GET['end'] ?? date('Y-m-t'));
      $collection =& scheduleCollection($state, $team);
      $latestId = $collection['latest'] ?? null;
      if ($latestId) {
        foreach ($collection['versions'] as $version) {
          if (($version['id'] ?? null) === $latestId) {
        respond(array_merge($version, ['version_id' => $latestId]));
          }
        }
      }
      $default = defaultTeamState($team, $start, $end);
      respond($default);

    case '/schedule/save':
      if ($method !== 'POST') {
        respond(['message' => '仅支持 POST'], 405);
      }
      requireAuth();
      $payload = normalizeSchedulePayload(readJsonBody());
      $team = $payload['team'];
      $collection =& scheduleCollection($state, $team);
      $base = $payload['baseVersionId'];
      $latestId = $collection['latest'] ?? null;
      if ($base && $latestId && $base !== $latestId) {
        respond(['message' => '存在新版本，请先加载最新版本', 'code' => 409], 409);
      }
      $versionId = generateVersionId();
      $payload['id'] = $versionId;
      $payload['version_id'] = $versionId;
      $payload['updated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
      $collection['versions'][] = $payload;
      $collection['latest'] = $versionId;
      $state['schedules'][$team] = $collection;
      saveState($state);
      respond(['version_id' => $versionId]);

    case '/schedule/versions':
      if ($method !== 'GET') {
        respond(['message' => '仅支持 GET'], 405);
      }
      $team = ensureTeamId((string)($_GET['team'] ?? 'default'));
      $collection =& scheduleCollection($state, $team);
      $versions = array_map(fn($v) => [
        'id' => $v['id'] ?? '',
        'team' => $team,
        'start' => $v['start'] ?? '',
        'end' => $v['end'] ?? '',
        'created_at' => $v['created_at'] ?? '',
        'operator' => $v['operator'] ?? '',
        'note' => $v['note'] ?? '',
      ], $collection['versions']);
      respond(['versions' => $versions]);

    case '/schedule/version/delete':
      if ($method !== 'POST') {
        respond(['message' => '仅支持 POST'], 405);
      }
      requireAuth('super');
      $body = readJsonBody();
      $team = ensureTeamId((string)($body['team'] ?? ''));
      $id = (string)($body['id'] ?? '');
      if ($id === '') {
        respond(['message' => '缺少版本 ID'], 400);
      }
      $collection =& scheduleCollection($state, $team);
      $collection['versions'] = array_values(array_filter(
        $collection['versions'],
        fn($v) => ($v['id'] ?? null) !== $id
      ));
      if (($collection['latest'] ?? null) === $id) {
        $collection['latest'] = null;
      }
      $state['schedules'][$team] = $collection;
      saveState($state);
      respond(['ok' => true]);

    case '/version':
      if ($method !== 'GET') {
        respond(['message' => '仅支持 GET'], 405);
      }
      $id = (string)($_GET['id'] ?? '');
      if ($id === '') {
        respond(['message' => '缺少版本 ID'], 400);
      }
      foreach ($state['schedules'] as $collection) {
        if (!is_array($collection['versions'] ?? null)) {
          continue;
        }
        foreach ($collection['versions'] as $version) {
          if (($version['id'] ?? null) === $id) {
            respond(array_merge($version, ['version_id' => $id]));
          }
        }
      }
      respond(['message' => '未找到指定版本'], 404);

    case '/export/xlsx':
      if ($method !== 'GET') {
        respond(['message' => '仅支持 GET'], 405);
      }
      $team = ensureTeamId((string)($_GET['team'] ?? 'default'));
      $collection =& scheduleCollection($state, $team);
      $latestId = $collection['latest'] ?? null;
      $schedule = null;
      if ($latestId) {
        foreach ($collection['versions'] as $version) {
          if (($version['id'] ?? null) === $latestId) {
            $schedule = $version;
            break;
          }
        }
      }
      if ($schedule === null) {
        $schedule = defaultTeamState($team, (string)($_GET['start'] ?? date('Y-m-01')), (string)($_GET['end'] ?? date('Y-m-t')));
      }
      streamCsv($schedule);
      break;

    default:
      respond(['message' => '未找到接口'], 404);
  }
} catch (Throwable $e) {
  respond([
    'message' => '服务器异常：' . $e->getMessage(),
  ], 500);
}
