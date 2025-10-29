<?php
// 公共函数：加载配置、初始化数据库、统一 JSON 响应等

declare(strict_types=1);

const PAGE_KEYS = ['schedule', 'settings', 'role_permissions', 'people'];

/** @return array<string,mixed> */
function load_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $file = __DIR__ . '/../config/app.php';
    if (!file_exists($file)) {
        $file = __DIR__ . '/../config/app.example.php';
    }
    /** @var array<string,mixed> $cfg */
    $cfg = require $file;
    $config = $cfg;
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = load_config();
    $path = $cfg['db_path'] ?? (__DIR__ . '/../data/schedule.db');
    $dir = dirname((string)$path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA busy_timeout=5000');
    return $pdo;
}

function respond_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respond_no_content(): void
{
    http_response_code(204);
    exit;
}

function require_method(string $method): void
{
    if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? '', $method) !== 0) {
        respond_json(['error' => 'method_not_allowed'], 405);
    }
}

function input_string(string $key, bool $required = true): ?string
{
    $value = null;
    if (isset($_POST[$key])) {
        $value = trim((string)$_POST[$key]);
    } elseif (isset($_GET[$key])) {
        $value = trim((string)$_GET[$key]);
    }
    if ($required && ($value === null || $value === '')) {
        respond_json(['error' => 'missing_parameter', 'field' => $key], 422);
    }
    return $value === '' ? null : $value;
}

function input_int(string $key, bool $required = true): ?int
{
    $value = input_string($key, $required);
    if ($value === null) {
        return null;
    }
    if (!preg_match('/^-?\d+$/', $value)) {
        respond_json(['error' => 'invalid_parameter', 'field' => $key], 422);
    }
    return (int)$value;
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (is_array($data)) {
        return $data;
    }
    return [];
}

function require_post_json(): array
{
    if (!isset($_SERVER['CONTENT_TYPE']) || stripos($_SERVER['CONTENT_TYPE'], 'application/json') === false) {
        respond_json(['error' => 'invalid_content_type'], 415);
    }
    $data = json_body();
    if (!$data) {
        respond_json(['error' => 'invalid_json'], 400);
    }
    return $data;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $cfg = load_config();
    $sessionCfg = $cfg['session'] ?? [];
    $name = (string)($sessionCfg['name'] ?? 'AIBANCISESSID');
    session_name($name);
    session_set_cookie_params([
        'lifetime' => (int)($sessionCfg['cookie_lifetime'] ?? 0),
        'path' => '/',
        'secure' => (bool)($sessionCfg['cookie_secure'] ?? false),
        'httponly' => (bool)($sessionCfg['cookie_httponly'] ?? true),
        'samesite' => $sessionCfg['cookie_samesite'] ?? 'Lax',
    ]);
    session_start();
}

function get_current_user_id(): ?int
{
    start_session();
    if (!empty($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }
    return null;
}

/**
 * @return array<string,mixed>|null
 */
function current_account(?PDO $pdo = null): ?array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $userId = get_current_user_id();
    if ($userId === null) {
        return null;
    }
    $pdo = $pdo ?? db();
    $stmt = $pdo->prepare('SELECT id, username, display_name, is_active, must_reset_password FROM accounts WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['is_active'] !== 1) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    $row['must_reset_password'] = (int)$row['must_reset_password'] === 1;
    $cached = $row;
    return $cached;
}

function is_authenticated(): bool
{
    return current_account() !== null;
}

function require_auth(): array
{
    $user = current_account();
    if (!$user) {
        respond_json(['error' => 'unauthenticated'], 401);
    }
    return $user;
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

function validate_date(string $ymd): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    return $dt !== false && $dt->format('Y-m-d') === $ymd;
}

function ensure_password_policy(string $password): void
{
    if (strlen($password) < 8) {
        respond_json(['error' => 'password_too_short', 'min_length' => 8], 422);
    }
}

/**
 * @return array<string,array{can_view:bool,can_edit:bool}>
 */
function get_user_page_permissions(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT page, can_view, can_edit FROM account_page_permissions WHERE account_id = :id');
    $stmt->execute([':id' => $userId]);
    $perms = [];
    while ($row = $stmt->fetch()) {
        $perms[$row['page']] = [
            'can_view' => (int)$row['can_view'] === 1,
            'can_edit' => (int)$row['can_edit'] === 1,
        ];
    }
    foreach (PAGE_KEYS as $page) {
        if (!isset($perms[$page])) {
            $perms[$page] = ['can_view' => false, 'can_edit' => false];
        }
    }
    return $perms;
}

/**
 * @return array<int,array{id:int,code:string,name:string,access:string}>
 */
function get_user_team_permissions(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT t.id AS team_id, t.code, t.name, atp.access FROM account_team_permissions atp JOIN teams t ON t.id = atp.team_id WHERE atp.account_id = :id AND t.is_active = 1 ORDER BY t.sort_order ASC, t.id ASC');
    $stmt->execute([':id' => $userId]);
    $teams = [];
    while ($row = $stmt->fetch()) {
        $teams[(int)$row['team_id']] = [
            'id' => (int)$row['team_id'],
            'code' => (string)$row['code'],
            'name' => (string)$row['name'],
            'access' => (string)$row['access'],
        ];
    }
    return $teams;
}

function require_page_view(string $page): void
{
    $user = require_auth();
    $pdo = db();
    $perms = get_user_page_permissions($pdo, (int)$user['id']);
    if (!$perms[$page]['can_view']) {
        respond_json(['error' => 'forbidden', 'reason' => 'page_not_allowed'], 403);
    }
}

function require_page_edit(string $page): void
{
    $user = require_auth();
    $pdo = db();
    $perms = get_user_page_permissions($pdo, (int)$user['id']);
    if (!$perms[$page]['can_edit']) {
        respond_json(['error' => 'forbidden', 'reason' => 'page_readonly'], 403);
    }
}

function ensure_team_access(int $teamId, bool $requireWrite = false): array
{
    $user = require_auth();
    $pdo = db();
    $teams = get_user_team_permissions($pdo, (int)$user['id']);
    if (!isset($teams[$teamId])) {
        respond_json(['error' => 'forbidden', 'reason' => 'team_not_allowed'], 403);
    }
    if ($requireWrite && $teams[$teamId]['access'] !== 'write') {
        respond_json(['error' => 'forbidden', 'reason' => 'team_readonly'], 403);
    }
    return $teams[$teamId];
}

/**
 * @return array<int,string>
 */
function allowed_shift_codes(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $stmt = $pdo->query("SELECT shift_code FROM shift_styles WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    $codes = [];
    while ($row = $stmt->fetch()) {
        $codes[] = (string)$row['shift_code'];
    }
    $cache = $codes;
    return $codes;
}

function ensure_value_allowed(?string $value, PDO $pdo): void
{
    if ($value === null || $value === '') {
        return;
    }
    $allowed = allowed_shift_codes($pdo);
    if (!in_array($value, $allowed, true)) {
        respond_json(['error' => 'invalid_value', 'allowed' => $allowed], 422);
    }
}

function ensure_rate_limit(PDO $pdo, int $userId): void
{
    $cfg = load_config();
    $window = (int)($cfg['rate_limit']['window'] ?? 1);
    $maxOps = (int)($cfg['rate_limit']['max_ops'] ?? 10);
    if ($window <= 0 || $maxOps <= 0) {
        return;
    }
    $since = time() - $window;
    $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM schedule_ops WHERE user_id = :uid AND ts >= :since');
    $stmt->execute([':uid' => $userId, ':since' => $since]);
    $row = $stmt->fetch();
    $cnt = (int)($row['cnt'] ?? 0);
    if ($cnt >= $maxOps) {
        respond_json(['error' => 'rate_limited', 'window' => $window, 'max_ops' => $maxOps], 429);
    }
}

function log_op(string $opId, array $context = []): void
{
    $cfg = load_config();
    $dir = $cfg['log_dir'] ?? (__DIR__ . '/../log');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $line = sprintf("%s\top_id=%s\t%s\n",
        (new DateTime('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'),
        $opId,
        json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    file_put_contents($dir . '/ops.log', $line, FILE_APPEND);
}

function begin_transaction(PDO $pdo): void
{
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }
}

function commit(PDO $pdo): void
{
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
}

function rollback(PDO $pdo): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
