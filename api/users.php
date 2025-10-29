<?php
require __DIR__ . '/common.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_page_view('role_permissions');
    $stmt = $pdo->query('SELECT id, username, display_name, is_active, must_reset_password FROM accounts ORDER BY id ASC');
    $accounts = [];
    while ($row = $stmt->fetch()) {
        $id = (int)$row['id'];
        $accounts[$id] = [
            'id' => $id,
            'username' => (string)$row['username'],
            'display_name' => (string)$row['display_name'],
            'is_active' => (int)$row['is_active'] === 1,
            'must_reset_password' => (int)$row['must_reset_password'] === 1,
            'page_permissions' => [],
            'team_permissions' => [],
        ];
    }

    if ($accounts) {
        $ids = array_keys($accounts);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pageStmt = $pdo->prepare("SELECT account_id, page, can_view, can_edit FROM account_page_permissions WHERE account_id IN ($in)");
        $pageStmt->execute($ids);
        while ($row = $pageStmt->fetch()) {
            $accountId = (int)$row['account_id'];
            if (!isset($accounts[$accountId])) {
                continue;
            }
            $accounts[$accountId]['page_permissions'][$row['page']] = [
                'can_view' => (int)$row['can_view'] === 1,
                'can_edit' => (int)$row['can_edit'] === 1,
            ];
        }

        $teamStmt = $pdo->prepare("SELECT account_id, team_id, access, t.name, t.code FROM account_team_permissions JOIN teams t ON t.id = account_team_permissions.team_id WHERE account_id IN ($in)");
        $teamStmt->execute($ids);
        while ($row = $teamStmt->fetch()) {
            $accountId = (int)$row['account_id'];
            if (!isset($accounts[$accountId])) {
                continue;
            }
            $accounts[$accountId]['team_permissions'][] = [
                'team_id' => (int)$row['team_id'],
                'access' => (string)$row['access'],
                'name' => (string)$row['name'],
                'code' => (string)$row['code'],
            ];
        }

        foreach ($accounts as &$account) {
            $pages = [];
            $raw = $account['page_permissions'];
            foreach (PAGE_KEYS as $page) {
                $entry = $raw[$page] ?? ['can_view' => false, 'can_edit' => false];
                $pages[$page] = $entry;
            }
            $account['page_permissions'] = $pages;
        }
        unset($account);
    }

    respond_json(['accounts' => array_values($accounts)]);
}

require_method('POST');
require_page_edit('role_permissions');
$data = require_post_json();
$action = isset($data['action']) ? (string)$data['action'] : '';

function normalize_page_permissions(array $payload): array
{
    $result = [];
    foreach (PAGE_KEYS as $page) {
        $value = $payload[$page] ?? 'hidden';
        if (is_array($value) && isset($value['access'])) {
            $value = $value['access'];
        }
        $mode = (string)$value;
        $mode = $mode === 'write' ? 'write' : ($mode === 'read' ? 'read' : 'hidden');
        $result[$page] = [
            'can_view' => $mode !== 'hidden',
            'can_edit' => $mode === 'write',
        ];
    }
    return $result;
}

function normalize_team_permissions(PDO $pdo, $payload): array
{
    $result = [];
    if (!is_array($payload)) {
        return $result;
    }
    $teamStmt = $pdo->query('SELECT id FROM teams');
    $valid = [];
    while ($row = $teamStmt->fetch()) {
        $valid[(int)$row['id']] = true;
    }
    foreach ($payload as $teamId => $value) {
        $teamId = (int)$teamId;
        if (!isset($valid[$teamId])) {
            continue;
        }
        $mode = (string)$value;
        if ($mode !== 'read' && $mode !== 'write') {
            continue;
        }
        $result[$teamId] = $mode;
    }
    return $result;
}

if ($action === 'create') {
    $username = isset($data['username']) ? trim((string)$data['username']) : '';
    $displayName = isset($data['display_name']) ? trim((string)$data['display_name']) : '';
    $initialPassword = isset($data['initial_password']) ? (string)$data['initial_password'] : '';
    if ($username === '' || !preg_match('/^[a-zA-Z0-9_@.-]{3,}$/', $username)) {
        respond_json(['error' => 'invalid_username'], 422);
    }
    if ($displayName === '') {
        $displayName = $username;
    }
    $passwordHash = null;
    $mustReset = 1;
    if ($initialPassword !== '') {
        ensure_password_policy($initialPassword);
        $passwordHash = password_hash($initialPassword, PASSWORD_DEFAULT);
        $mustReset = 0;
    }
    begin_transaction($pdo);
    try {
        $stmt = $pdo->prepare('INSERT INTO accounts(username, display_name, password_hash, must_reset_password, is_active) VALUES (:username, :display, :hash, :must_reset, 1)');
        $stmt->execute([
            ':username' => $username,
            ':display' => $displayName,
            ':hash' => $passwordHash,
            ':must_reset' => $mustReset,
        ]);
        $accountId = (int)$pdo->lastInsertId();
        $pagePerms = normalize_page_permissions(is_array($data['page_permissions'] ?? null) ? $data['page_permissions'] : []);
        $teamPerms = normalize_team_permissions($pdo, $data['team_permissions'] ?? []);
        $pageStmt = $pdo->prepare('INSERT INTO account_page_permissions(account_id, page, can_view, can_edit) VALUES (:account, :page, :view, :edit)');
        foreach ($pagePerms as $page => $perm) {
            $pageStmt->execute([
                ':account' => $accountId,
                ':page' => $page,
                ':view' => $perm['can_view'] ? 1 : 0,
                ':edit' => $perm['can_edit'] ? 1 : 0,
            ]);
        }
        $teamStmt = $pdo->prepare('INSERT INTO account_team_permissions(account_id, team_id, access) VALUES (:account, :team, :access)');
        foreach ($teamPerms as $teamId => $access) {
            $teamStmt->execute([
                ':account' => $accountId,
                ':team' => $teamId,
                ':access' => $access,
            ]);
        }
        commit($pdo);
    } catch (Throwable $e) {
        rollback($pdo);
        if ($e instanceof PDOException) {
            respond_json(['error' => 'username_exists'], 409);
        }
        throw $e;
    }
    respond_json(['success' => true]);
}

if ($action === 'update') {
    $accountId = isset($data['id']) ? (int)$data['id'] : 0;
    $displayName = isset($data['display_name']) ? trim((string)$data['display_name']) : null;
    $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : null;
    if ($accountId <= 0) {
        respond_json(['error' => 'invalid_id'], 422);
    }
    $fields = [];
    $params = [':id' => $accountId];
    if ($displayName !== null) {
        $fields[] = 'display_name = :display';
        $params[':display'] = $displayName;
    }
    if ($isActive !== null) {
        $fields[] = 'is_active = :active';
        $params[':active'] = $isActive ? 1 : 0;
    }
    if (!$fields) {
        respond_json(['error' => 'no_changes'], 400);
    }
    $sql = 'UPDATE accounts SET ' . implode(', ', $fields) . ', updated_at = strftime("%s","now") WHERE id = :id';
    $pdo->prepare($sql)->execute($params);
    respond_json(['success' => true]);
}

if ($action === 'set_permissions') {
    $accountId = isset($data['id']) ? (int)$data['id'] : 0;
    if ($accountId <= 0) {
        respond_json(['error' => 'invalid_id'], 422);
    }
    $pagePerms = normalize_page_permissions(is_array($data['page_permissions'] ?? null) ? $data['page_permissions'] : []);
    $teamPerms = normalize_team_permissions($pdo, $data['team_permissions'] ?? []);
    begin_transaction($pdo);
    try {
        $pdo->prepare('DELETE FROM account_page_permissions WHERE account_id = :id')->execute([':id' => $accountId]);
        $pdo->prepare('DELETE FROM account_team_permissions WHERE account_id = :id')->execute([':id' => $accountId]);
        $pageStmt = $pdo->prepare('INSERT INTO account_page_permissions(account_id, page, can_view, can_edit) VALUES (:account, :page, :view, :edit)');
        foreach ($pagePerms as $page => $perm) {
            $pageStmt->execute([
                ':account' => $accountId,
                ':page' => $page,
                ':view' => $perm['can_view'] ? 1 : 0,
                ':edit' => $perm['can_edit'] ? 1 : 0,
            ]);
        }
        $teamStmt = $pdo->prepare('INSERT INTO account_team_permissions(account_id, team_id, access) VALUES (:account, :team, :access)');
        foreach ($teamPerms as $teamId => $access) {
            $teamStmt->execute([
                ':account' => $accountId,
                ':team' => $teamId,
                ':access' => $access,
            ]);
        }
        commit($pdo);
    } catch (Throwable $e) {
        rollback($pdo);
        throw $e;
    }
    respond_json(['success' => true]);
}

if ($action === 'reset_password') {
    $accountId = isset($data['id']) ? (int)$data['id'] : 0;
    if ($accountId <= 0) {
        respond_json(['error' => 'invalid_id'], 422);
    }
    $pdo->prepare('UPDATE accounts SET password_hash = NULL, must_reset_password = 1, updated_at = strftime("%s","now") WHERE id = :id')->execute([':id' => $accountId]);
    respond_json(['success' => true]);
}

respond_json(['error' => 'unknown_action'], 400);
