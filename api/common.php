<?php
/**
 * 公共初始化：加载配置、提供数据库连接、通用响应方法。
 * 所有 API 均需引用本文件。
 */

declare(strict_types=1);

mb_internal_encoding('UTF-8');

define('APP_ROOT', dirname(__DIR__));

$configFile = APP_ROOT . '/config/app.php';
if (!file_exists($configFile)) {
    $configFile = APP_ROOT . '/config/app.example.php';
}
$config = require $configFile;

/**
 * 获取 PDO 数据库连接，开启必要 PRAGMA。
 */
function get_db(): PDO {
    global $config;
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'sqlite:' . $config['db_path'];
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA busy_timeout=5000');
    return $pdo;
}

/**
 * 简易鉴权桩：真实环境可对接 SSO / Token。
 */
function is_authenticated(): ?array {
    // TODO: 替换为真实鉴权逻辑（Cookie/Token）。此处演示读取伪造头部。
    $userId = $_SERVER['HTTP_X_USER_ID'] ?? 'demo_user';
    if (!$userId) {
        return null;
    }
    return [
        'user_id' => $userId,
        'display_name' => $_SERVER['HTTP_X_USER_NAME'] ?? $userId,
    ];
}

/**
 * 输出 JSON 并结束。
 */
function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 校验日期格式（YYYY-MM-DD）。
 */
function is_valid_date(string $date): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
}

/**
 * 校验班次值是否合法。
 */
function assert_value_allowed(string $value): void {
    global $config;
    if (!in_array($value, $config['allowed_values'], true)) {
        json_response([
            'error' => 'invalid_value',
            'message' => '班次值不在允许列表中',
        ], 400);
    }
}

/**
 * 记录审计日志：便于排查问题。
 */
function audit_log(string $event, array $data): void {
    $line = sprintf('[%s] %s %s', date('c'), $event, json_encode($data, JSON_UNESCAPED_UNICODE));
    error_log($line, 3, APP_ROOT . '/log/audit.log');
}

/**
 * 统一频率限制：每用户每秒限制请求次数。
 */
function enforce_rate_limit(string $userId, string $key, int $maxPerSecond): void {
    $dir = APP_ROOT . '/runtime';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $now = time();
    $bucket = $now;
    $file = sprintf('%s/rl_%s_%s.cache', $dir, preg_replace('/[^a-z0-9_]/i', '_', $key), md5($userId));
    $data = ['bucket' => $bucket, 'count' => 0];
    if (file_exists($file)) {
        $raw = file_get_contents($file);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['bucket'], $decoded['count'])) {
                $data = $decoded;
            }
        }
    }
    if ($data['bucket'] === $bucket) {
        $data['count']++;
    } else {
        $data = ['bucket' => $bucket, 'count' => 1];
    }
    if ($data['count'] > $maxPerSecond) {
        json_response([
            'error' => 'rate_limited',
            'message' => '请求过于频繁，请稍后再试',
        ], 429);
    }
    file_put_contents($file, json_encode($data));
}

/**
 * 将整表导出为 JSON，供快照使用。
 */
function export_day_payload(PDO $db, string $team, string $day): string {
    $stmt = $db->prepare('SELECT emp, value, version, updated_at, updated_by FROM schedule_cells WHERE team = :team AND day = :day ORDER BY emp');
    $stmt->execute([':team' => $team, ':day' => $day]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return json_encode($rows, JSON_UNESCAPED_UNICODE);
}

/**
 * 从快照载入数据并回滚。
 */
function restore_day_payload(PDO $db, string $team, string $day, string $payload, string $userId): void {
    $db->exec('DELETE FROM schedule_cells WHERE team = ' . $db->quote($team) . ' AND day = ' . $db->quote($day));
    $data = json_decode($payload, true);
    if (!is_array($data)) {
        throw new RuntimeException('快照内容损坏');
    }
    $now = time();
    $stmt = $db->prepare('INSERT INTO schedule_cells (team, day, emp, value, version, updated_at, updated_by) VALUES (:team, :day, :emp, :value, :version, :updated_at, :updated_by)');
    foreach ($data as $row) {
        $stmt->execute([
            ':team' => $team,
            ':day' => $day,
            ':emp' => $row['emp'],
            ':value' => $row['value'],
            ':version' => (int)$row['version'],
            ':updated_at' => $now,
            ':updated_by' => $userId,
        ]);
    }
}
