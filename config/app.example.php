<?php
return [
    // SQLite 数据库文件路径，默认放置在项目 data 目录下
    'db_path' => __DIR__ . '/../data/schedule.db',
    // 允许的班次列表，可按需扩展
    'allowed_values' => ['白', '中1', '中2', '夜', '休'],
    // 默认团队与页面展示范围
    'default_team' => '默认团队',
    'default_day' => (new DateTime('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d'),
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
];
