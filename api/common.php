<?php
// 公共函数：加载配置、初始化数据库、统一 JSON 响应等

declare(strict_types=1);

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
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // 初始化 PRAGMA
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

function is_authenticated(): bool
{
    return get_current_user_id() !== null;
}

function require_auth(): void
{
    if (!is_authenticated()) {
        respond_json(['error' => 'unauthenticated'], 401);
    }
}

function get_current_user_id(): ?string
{
    // 简易鉴权桩：真实环境应读取安全 Cookie 或 Token
    if (!empty($_COOKIE['uid'])) {
        return preg_replace('/[^a-zA-Z0-9_\-@.]/', '', (string)$_COOKIE['uid']);
    }
    if (!empty($_SERVER['HTTP_X_USER_ID'])) {
        return preg_replace('/[^a-zA-Z0-9_\-@.]/', '', (string)$_SERVER['HTTP_X_USER_ID']);
    }
    return 'guest';
}

function validate_date(string $ymd): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    return $dt !== false && $dt->format('Y-m-d') === $ymd;
}

function validate_value(string $value): bool
{
    $cfg = load_config();
    $allowed = $cfg['allowed_values'] ?? [];
    return in_array($value, $allowed, true);
}

function ensure_value_allowed(string $value): void
{
    if (!validate_value($value)) {
        respond_json(['error' => 'invalid_value', 'allowed' => load_config()['allowed_values']], 422);
    }
}

function ensure_rate_limit(PDO $pdo, string $userId): void
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
        (new DateTime('now', new DateTimeZone('Asia/Singapore')))->format('Y-m-d H:i:s'),
        $opId,
        json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    file_put_contents($dir . '/ops.log', $line, FILE_APPEND);
}

function acquire_db(): PDO
{
    return db();
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
