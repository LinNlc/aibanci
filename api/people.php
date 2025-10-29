<?php
require __DIR__ . '/common.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_page_view('people');
    $teamId = input_int('team_id');
    if (!$teamId) {
        respond_json(['error' => 'missing_team'], 422);
    }
    ensure_team_access($teamId, false);
    $stmt = $pdo->prepare('SELECT id, name, active, show_in_schedule, sort_index FROM people WHERE team_id = :team ORDER BY sort_index ASC, id ASC');
    $stmt->execute([':team' => $teamId]);
    $items = [];
    while ($row = $stmt->fetch()) {
        $items[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'active' => (int)$row['active'] === 1,
            'show_in_schedule' => (int)$row['show_in_schedule'] === 1,
            'sort_index' => (int)$row['sort_index'],
        ];
    }
    respond_json(['people' => $items]);
}

require_method('POST');
require_page_edit('people');
$data = require_post_json();
$action = isset($data['action']) ? (string)$data['action'] : '';
$teamId = isset($data['team_id']) ? (int)$data['team_id'] : 0;
if ($teamId <= 0) {
    respond_json(['error' => 'missing_team'], 422);
}
ensure_team_access($teamId, true);

if ($action === 'create') {
    $name = isset($data['name']) ? trim((string)$data['name']) : '';
    $show = isset($data['show_in_schedule']) ? (bool)$data['show_in_schedule'] : true;
    $active = isset($data['active']) ? (bool)$data['active'] : true;
    if ($name === '') {
        respond_json(['error' => 'missing_name'], 422);
    }
    $stmt = $pdo->prepare('INSERT INTO people(team_id, name, active, show_in_schedule, sort_index) VALUES (:team, :name, :active, :show, (SELECT COALESCE(MAX(sort_index),0) + 1 FROM people WHERE team_id = :team))');
    $stmt->execute([
        ':team' => $teamId,
        ':name' => $name,
        ':active' => $active ? 1 : 0,
        ':show' => $show ? 1 : 0,
    ]);
    respond_json(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'update') {
    $personId = isset($data['id']) ? (int)$data['id'] : 0;
    if ($personId <= 0) {
        respond_json(['error' => 'invalid_id'], 422);
    }
    $fields = [];
    $params = [':id' => $personId, ':team' => $teamId];
    if (isset($data['name'])) {
        $fields[] = 'name = :name';
        $params[':name'] = trim((string)$data['name']);
    }
    if (isset($data['active'])) {
        $fields[] = 'active = :active';
        $params[':active'] = (bool)$data['active'] ? 1 : 0;
    }
    if (isset($data['show_in_schedule'])) {
        $fields[] = 'show_in_schedule = :show';
        $params[':show'] = (bool)$data['show_in_schedule'] ? 1 : 0;
    }
    if (!$fields) {
        respond_json(['error' => 'no_changes'], 400);
    }
    $sql = 'UPDATE people SET ' . implode(', ', $fields) . ' WHERE id = :id AND team_id = :team';
    $pdo->prepare($sql)->execute($params);
    respond_json(['success' => true]);
}

if ($action === 'move') {
    $personId = isset($data['id']) ? (int)$data['id'] : 0;
    $direction = isset($data['direction']) ? (string)$data['direction'] : '';
    if ($personId <= 0 || ($direction !== 'up' && $direction !== 'down')) {
        respond_json(['error' => 'invalid_parameters'], 422);
    }
    begin_transaction($pdo);
    try {
        $current = $pdo->prepare('SELECT sort_index FROM people WHERE id = :id AND team_id = :team');
        $current->execute([':id' => $personId, ':team' => $teamId]);
        $row = $current->fetch();
        if (!$row) {
            rollback($pdo);
            respond_json(['error' => 'not_found'], 404);
        }
        $currentIndex = (int)$row['sort_index'];
        $order = $direction === 'up' ? 'DESC' : 'ASC';
        $cmp = $direction === 'up' ? '<' : '>';
        $neighbor = $pdo->prepare("SELECT id, sort_index FROM people WHERE team_id = :team AND sort_index $cmp :index ORDER BY sort_index $order LIMIT 1");
        $neighbor->execute([':team' => $teamId, ':index' => $currentIndex]);
        $target = $neighbor->fetch();
        if ($target) {
            $pdo->prepare('UPDATE people SET sort_index = :sort WHERE id = :id')->execute([
                ':sort' => (int)$target['sort_index'],
                ':id' => $personId,
            ]);
            $pdo->prepare('UPDATE people SET sort_index = :sort WHERE id = :id')->execute([
                ':sort' => $currentIndex,
                ':id' => (int)$target['id'],
            ]);
        }
        commit($pdo);
    } catch (Throwable $e) {
        rollback($pdo);
        throw $e;
    }
    respond_json(['success' => true]);
}

if ($action === 'delete') {
    $personId = isset($data['id']) ? (int)$data['id'] : 0;
    if ($personId <= 0) {
        respond_json(['error' => 'invalid_id'], 422);
    }
    $pdo->prepare('DELETE FROM people WHERE id = :id AND team_id = :team')->execute([
        ':id' => $personId,
        ':team' => $teamId,
    ]);
    respond_json(['success' => true]);
}

respond_json(['error' => 'unknown_action'], 400);
