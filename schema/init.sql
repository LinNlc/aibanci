PRAGMA journal_mode=WAL;
PRAGMA synchronous=NORMAL;
PRAGMA foreign_keys=ON;
PRAGMA busy_timeout=5000;

CREATE TABLE IF NOT EXISTS teams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL DEFAULT '',
    password_hash TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    must_reset_password INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    updated_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
);

CREATE TABLE IF NOT EXISTS account_page_permissions (
    account_id INTEGER NOT NULL,
    page TEXT NOT NULL,
    can_view INTEGER NOT NULL DEFAULT 0,
    can_edit INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (account_id, page),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS account_team_permissions (
    account_id INTEGER NOT NULL,
    team_id INTEGER NOT NULL,
    access TEXT NOT NULL CHECK(access IN ('read','write')),
    PRIMARY KEY (account_id, team_id),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS shift_styles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    shift_code TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    bg_color TEXT NOT NULL DEFAULT '#ffffff',
    text_color TEXT NOT NULL DEFAULT '#111827',
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS people (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    show_in_schedule INTEGER NOT NULL DEFAULT 1,
    sort_index INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS schedule_cells (
    team_id INTEGER NOT NULL,
    day TEXT NOT NULL,
    person_id INTEGER NOT NULL,
    value TEXT NOT NULL DEFAULT '',
    version INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    updated_by INTEGER,
    PRIMARY KEY (team_id, day, person_id),
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES accounts(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS schedule_ops (
    op_id TEXT PRIMARY KEY,
    team_id INTEGER NOT NULL,
    day TEXT NOT NULL,
    person_id INTEGER NOT NULL,
    base_version INTEGER NOT NULL,
    new_value TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    ts INTEGER NOT NULL,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_schedule_ops_team_day_ts ON schedule_ops(team_id, day, ts);

CREATE TABLE IF NOT EXISTS schedule_softlocks (
    team_id INTEGER NOT NULL,
    day TEXT NOT NULL,
    person_id INTEGER NOT NULL,
    locked_by INTEGER NOT NULL,
    lock_until INTEGER NOT NULL,
    PRIMARY KEY (team_id, day, person_id),
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
    FOREIGN KEY (locked_by) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS schedule_snapshots (
    snap_id TEXT PRIMARY KEY,
    team_id INTEGER NOT NULL,
    day TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    note TEXT,
    payload TEXT NOT NULL,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_snapshots_team_day ON schedule_snapshots(team_id, day, created_at);

-- Seed default team
INSERT OR IGNORE INTO teams(id, code, name, sort_order) VALUES (1, 'default', '默认团队', 0);

-- Seed default admin account (pending password setup)
INSERT OR IGNORE INTO accounts(id, username, display_name, password_hash, is_active, must_reset_password)
VALUES (1, 'admin', '系统管理员', NULL, 1, 1);

-- Ensure admin has full page permissions
INSERT OR IGNORE INTO account_page_permissions(account_id, page, can_view, can_edit) VALUES
    (1, 'schedule', 1, 1),
    (1, 'settings', 1, 1),
    (1, 'role_permissions', 1, 1),
    (1, 'people', 1, 1);

-- Ensure admin has full team access
INSERT OR IGNORE INTO account_team_permissions(account_id, team_id, access) VALUES
    (1, 1, 'write');

-- Seed default shift styles
INSERT OR IGNORE INTO shift_styles(id, shift_code, display_name, bg_color, text_color, sort_order, is_active) VALUES
    (1, '白', '白班', '#fff7ed', '#7c2d12', 1, 1),
    (2, '中1', '中班1', '#e0f2fe', '#0c4a6e', 2, 1),
    (3, '中2', '中班2', '#ede9fe', '#5b21b6', 3, 1),
    (4, '夜', '夜班', '#0f172a', '#f8fafc', 4, 1),
    (5, '休', '休息', '#f1f5f9', '#0f172a', 5, 1);
