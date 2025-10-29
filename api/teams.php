<?php
require __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = db();

if ($method === 'GET') {
    $scope = $_GET['scope'] ?? 'mine';
    if ($scope === 'all') {
        require_page_view('role_permissions');
        $stmt = $pdo->query('SELECT id, code, name, is_active, sort_order FROM teams ORDER BY sort_order ASC, id ASC');
        $teams = [];
        while ($row = $stmt->fetch()) {
            $teams[] = [
                'id' => (int)$row['id'],
                'code' => (string)$row['code'],
                'name' => (string)$row['name'],
                'is_active' => (int)$row['is_active'] === 1,
                'sort_order' => (int)$row['sort_order'],
            ];
        }
        respond_json(['teams' => $teams]);
    }

    $user = require_auth();
    $teams = array_values(get_user_team_permissions($pdo, (int)$user['id']));
    respond_json(['teams' => $teams]);
}

require_method('POST');
$data = require_post_json();
$action = isset($data['action']) ? (string)$data['action'] : '';

require_page_edit('role_permissions');

if ($action === 'create') {
    $name = isset($data['name']) ? trim((string)$data['name']) : '';
    $code = isset($data['code']) ? trim((string)$data['code']) : '';
    if ($name === '') {
        respond_json(['error' => 'missing_name'], 422);
    }
    if ($code === '') {
        $code = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $code = trim($code, '-');
        if ($code === '') {
            $code = 'team-' . substr(sha1((string)microtime(true)), 0, 6);
        }
    }
    $stmt = $pdo->prepare('INSERT INTO teams(code, name, is_active, sort_order) VALUES (:code, :name, 1, (SELECT COALESCE(MAX(sort_order),0) + 1 FROM teams))');
    try {
        $stmt->execute([
            ':code' => $code,
            ':name' => $name,
        ]);
    } catch (PDOException $e) {
        respond_json(['error' => 'team_exists'], 409);
    }
    $id = (int)$pdo->lastInsertId();
    respond_json(['team' => ['id' => $id, 'code' => $code, 'name' => $name, 'is_active' => true]]);
}

if ($action === 'update') {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $name = isset($data['name']) ? trim((string)$data['name']) : '';
    $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;
    if ($id <= 0 || $name === '') {
        respond_json(['error' => 'invalid_parameters'], 422);
    }
    $pdo->prepare('UPDATE teams SET name = :name, is_active = :active WHERE id = :id')->execute([
        ':name' => $name,
        ':active' => $isActive ? 1 : 0,
        ':id' => $id,
    ]);
    respond_json(['success' => true]);
}

if ($action === 'sort') {
    $order = isset($data['order']) && is_array($data['order']) ? $data['order'] : [];
    begin_transaction($pdo);
    try {
        $position = 1;
        $stmt = $pdo->prepare('UPDATE teams SET sort_order = :sort WHERE id = :id');
        foreach ($order as $teamId) {
            $stmt->execute([
                ':sort' => $position++,
                ':id' => (int)$teamId,
            ]);
        }
        commit($pdo);
    } catch (Throwable $e) {
        rollback($pdo);
        throw $e;
    }
    respond_json(['success' => true]);
}

respond_json(['error' => 'unknown_action'], 400);
