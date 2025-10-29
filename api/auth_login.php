<?php
require __DIR__ . '/common.php';

require_method('POST');
$data = require_post_json();

$username = isset($data['username']) ? trim((string)$data['username']) : '';
$password = isset($data['password']) ? (string)$data['password'] : '';

if ($username === '' || $password === '') {
    respond_json(['error' => 'missing_credentials'], 422);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, username, display_name, password_hash, is_active, must_reset_password FROM accounts WHERE username = :username LIMIT 1');
$stmt->execute([':username' => $username]);
$row = $stmt->fetch();
if (!$row || (int)$row['is_active'] !== 1) {
    respond_json(['error' => 'invalid_credentials'], 401);
}

if ($row['password_hash'] === null || $row['password_hash'] === '') {
    respond_json(['error' => 'password_not_set'], 403);
}

if (!password_verify($password, (string)$row['password_hash'])) {
    respond_json(['error' => 'invalid_credentials'], 401);
}

start_session();
session_regenerate_id(true);
$_SESSION['user_id'] = (int)$row['id'];

respond_json([
    'authenticated' => true,
    'user' => [
        'id' => (int)$row['id'],
        'username' => $row['username'],
        'display_name' => $row['display_name'],
        'must_reset_password' => (int)$row['must_reset_password'] === 1,
    ],
]);
