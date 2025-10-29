PRAGMA journal_mode=WAL;
PRAGMA synchronous=NORMAL;
PRAGMA foreign_keys=ON;
PRAGMA busy_timeout=5000;

CREATE TABLE IF NOT EXISTS schedule_cells (
    team TEXT NOT NULL,
    day TEXT NOT NULL,
    emp TEXT NOT NULL,
    value TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    updated_by TEXT,
    PRIMARY KEY (team, day, emp),
    CHECK (value IN ('白','中1','中2','夜','休'))
);

CREATE TABLE IF NOT EXISTS schedule_ops (
    op_id TEXT PRIMARY KEY,
    team TEXT NOT NULL,
    day TEXT NOT NULL,
    emp TEXT NOT NULL,
    base_version INTEGER NOT NULL,
    new_value TEXT NOT NULL,
    user_id TEXT NOT NULL,
    ts INTEGER NOT NULL,
    CHECK (new_value IN ('白','中1','中2','夜','休'))
);

CREATE INDEX IF NOT EXISTS idx_schedule_ops_team_day_ts ON schedule_ops(team, day, ts);

CREATE TABLE IF NOT EXISTS schedule_softlocks (
    team TEXT NOT NULL,
    day TEXT NOT NULL,
    emp TEXT NOT NULL,
    locked_by TEXT NOT NULL,
    lock_until INTEGER NOT NULL,
    PRIMARY KEY (team, day, emp)
);

CREATE TABLE IF NOT EXISTS schedule_snapshots (
    snap_id TEXT PRIMARY KEY,
    team TEXT NOT NULL,
    day TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    note TEXT,
    payload TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_snapshots_team_day ON schedule_snapshots(team, day, created_at);
