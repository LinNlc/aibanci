<?php
return [
    // SQLite 数据库文件路径，默认放置在项目 data 目录下
    'db_path' => __DIR__ . '/../data/schedule.db',
    // 默认团队编码（用户首次登录时的默认视图）
    'default_team_code' => 'default',
    // 默认日期（按月视图）
    'default_month' => (new DateTime('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m'),
    // SSE 心跳间隔（秒）
    'sse_heartbeat' => 15,
    // 软锁持续时间（秒）
    'lock_ttl' => 30,
    // 操作频率限制：每个用户在该时间窗口内最多允许的写操作次数
    'rate_limit' => [
        'window' => 1,
        'max_ops' => 10,
    ],
    // 快照存储目录
    'snapshot_dir' => __DIR__ . '/../snapshots',
    // 日志目录
    'log_dir' => __DIR__ . '/../log',
    // 会话配置
    'session' => [
        'name' => 'AIBANCISESSID',
        'cookie_lifetime' => 604800,
        'cookie_secure' => false,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ],
];
