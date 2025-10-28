<?php
return [
    // SQLite 数据库文件路径
    'db_path' => __DIR__ . '/../data/schedule.db',
    // 允许的班次取值，用于写入时校验
    'allowed_values' => ['白', '中1', '中2', '夜', '休'],
    // SSE 频道心跳间隔（秒）
    'sse_ping_interval' => 15,
    // 写操作节流：每个用户每秒最多多少次 update_cell
    'write_rate_limit_per_second' => 5,
    // 软锁默认持续秒数
    'lock_duration' => 30,
    // 快照存储目录
    'snapshot_dir' => __DIR__ . '/../snapshots',
    // 站点显示名称
    'site_name' => '协同排班系统',
    // 调试开关
    'debug' => false,
];
