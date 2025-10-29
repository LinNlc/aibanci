<?php
require __DIR__ . '/common.php';

require_method('POST');
$data = require_post_json();

$newPassword = isset($data['new_password']) ? (string)$data['new_password'] : '';
$confirmPassword = isset($data['confirm_password']) ? (string)$data['confirm_password'] : '';

if ($newPassword === '') {
    respond_json(['error' => 'missing_new_password'], 422);
}
if ($confirmPassword !== '' && $confirmPassword !== $newPassword) {
    respond_json(['error' => 'password_mismatch'], 422);
}

$pdo = db();
start_session();
ensure_password_policy($newPassword);

$user = current_account($pdo);
$hash = password_hash($newPassword, PASSWORD_DEFAULT);

if (!$user) {
    $username = isset($data['username']) ? trim((string)$data['username']) : '';
    if ($username !== 'admin') {
        respond_json(['error' => 'setup_only_admin'], 403);
    }
    $stmt = $pdo->prepare('SELECT id, password_hash FROM accounts WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => 'admin']);
    $row = $stmt->fetch();
    if (!$row || ($row['password_hash'] !== null && $row['password_hash'] !== '')) {
        respond_json(['error' => 'setup_not_available'], 403);
    }
    begin_transaction($pdo);
    try {
        $pdo->prepare('UPDATE accounts SET password_hash = :hash, must_reset_password = 0, updated_at = strftime("%s","now") WHERE id = :id')->execute([
            ':hash' => $hash,
            ':id' => (int)$row['id'],
        ]);
        commit($pdo);
    } catch (Throwable $e) {
        rollback($pdo);
        throw $e;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$row['id'];
    respond_json(['success' => true]);
}

$currentPassword = isset($data['current_password']) ? (string)$data['current_password'] : '';
$forceReset = (bool)$user['must_reset_password'];

$stmt = $pdo->prepare('SELECT password_hash FROM accounts WHERE id = :id LIMIT 1');
$stmt->execute([':id' => (int)$user['id']]);
$row = $stmt->fetch();
if (!$row) {
    respond_json(['error' => 'account_not_found'], 404);
}

if (!$forceReset) {
    if ($row['password_hash'] === null || $row['password_hash'] === '' || !password_verify($currentPassword, (string)$row['password_hash'])) {
        respond_json(['error' => 'invalid_current_password'], 403);
    }
}

begin_transaction($pdo);
try {
    $pdo->prepare('UPDATE accounts SET password_hash = :hash, must_reset_password = 0, updated_at = strftime("%s","now") WHERE id = :id')->execute([
        ':hash' => $hash,
        ':id' => (int)$user['id'],
    ]);
    commit($pdo);
} catch (Throwable $e) {
    rollback($pdo);
    throw $e;
}

session_regenerate_id(true);
respond_json(['success' => true]);
