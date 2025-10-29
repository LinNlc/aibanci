<?php
require __DIR__ . '/common.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_auth();
    $stmt = $pdo->query('SELECT id, shift_code, display_name, bg_color, text_color, sort_order, is_active FROM shift_styles ORDER BY sort_order ASC, id ASC');
    $shifts = [];
    while ($row = $stmt->fetch()) {
        $shifts[] = [
            'id' => (int)$row['id'],
            'shift_code' => (string)$row['shift_code'],
            'display_name' => (string)$row['display_name'],
            'bg_color' => (string)$row['bg_color'],
            'text_color' => (string)$row['text_color'],
            'sort_order' => (int)$row['sort_order'],
            'is_active' => (int)$row['is_active'] === 1,
        ];
    }
    respond_json(['shifts' => $shifts]);
}

require_method('POST');
require_page_edit('settings');
$data = require_post_json();
$action = isset($data['action']) ? (string)$data['action'] : '';

if ($action === 'create') {
    $code = isset($data['shift_code']) ? trim((string)$data['shift_code']) : '';
    $display = isset($data['display_name']) ? trim((string)$data['display_name']) : '';
    $bg = isset($data['bg_color']) ? (string)$data['bg_color'] : '#ffffff';
    $text = isset($data['text_color']) ? (string)$data['text_color'] : '#111827';
    if ($code === '' || $display === '') {
        respond_json(['error' => 'missing_fields'], 422);
    }
    $stmt = $pdo->prepare('INSERT INTO shift_styles(shift_code, display_name, bg_color, text_color, sort_order, is_active) VALUES (:code, :display, :bg, :text, (SELECT COALESCE(MAX(sort_order),0) + 1 FROM shift_styles), 1)');
    try {
        $stmt->execute([
            ':code' => $code,
            ':display' => $display,
            ':bg' => $bg,
            ':text' => $text,
        ]);
    } catch (PDOException $e) {
        respond_json(['error' => 'shift_exists'], 409);
    }
    respond_json(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'update') {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $fields = [];
    $params = [':id' => $id];
    if ($id <= 0) {
        respond_json(['error' => 'invalid_id'], 422);
    }
    if (isset($data['shift_code'])) {
        $fields[] = 'shift_code = :code';
        $params[':code'] = trim((string)$data['shift_code']);
    }
    if (isset($data['display_name'])) {
        $fields[] = 'display_name = :display';
        $params[':display'] = trim((string)$data['display_name']);
    }
    if (isset($data['bg_color'])) {
        $fields[] = 'bg_color = :bg';
        $params[':bg'] = (string)$data['bg_color'];
    }
    if (isset($data['text_color'])) {
        $fields[] = 'text_color = :text';
        $params[':text'] = (string)$data['text_color'];
    }
    if (isset($data['is_active'])) {
        $fields[] = 'is_active = :active';
        $params[':active'] = (bool)$data['is_active'] ? 1 : 0;
    }
    if (!$fields) {
        respond_json(['error' => 'no_changes'], 400);
    }
    $sql = 'UPDATE shift_styles SET ' . implode(', ', $fields) . ' WHERE id = :id';
    try {
        $pdo->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        respond_json(['error' => 'shift_exists'], 409);
    }
    respond_json(['success' => true]);
}

if ($action === 'sort') {
    $order = isset($data['order']) && is_array($data['order']) ? $data['order'] : [];
    begin_transaction($pdo);
    try {
        $pos = 1;
        $stmt = $pdo->prepare('UPDATE shift_styles SET sort_order = :sort WHERE id = :id');
        foreach ($order as $shiftId) {
            $stmt->execute([
                ':sort' => $pos++,
                ':id' => (int)$shiftId,
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
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) {
        respond_json(['error' => 'invalid_id'], 422);
    }
    $pdo->prepare('DELETE FROM shift_styles WHERE id = :id')->execute([':id' => $id]);
    respond_json(['success' => true]);
}

respond_json(['error' => 'unknown_action'], 400);
