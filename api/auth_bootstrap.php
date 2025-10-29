<?php
require __DIR__ . '/common.php';

require_method('GET');

$pdo = db();
start_session();

// Detect whether initial admin setup is required
$adminStmt = $pdo->prepare("SELECT id, username, password_hash, must_reset_password, display_name FROM accounts WHERE username = :username LIMIT 1");
$adminStmt->execute([':username' => 'admin']);
$adminRow = $adminStmt->fetch();
$needsInitialSetup = false;
if ($adminRow) {
    $passwordHash = $adminRow['password_hash'] ?? null;
    if ($passwordHash === null || $passwordHash === '') {
        $needsInitialSetup = true;
    }
}

$user = current_account($pdo);

if ($needsInitialSetup && !$user) {
    respond_json([
        'setup_required' => true,
        'username' => 'admin',
        'display_name' => $adminRow['display_name'] ?? '系统管理员',
    ]);
}

if (!$user) {
    respond_json([
        'authenticated' => false,
    ]);
}

$pages = get_user_page_permissions($pdo, (int)$user['id']);
$teamsMap = get_user_team_permissions($pdo, (int)$user['id']);
$teams = array_values($teamsMap);

$cfg = load_config();
$defaultTeamCode = $cfg['default_team_code'] ?? 'default';
$defaultTeamId = null;
foreach ($teams as $team) {
    if ($team['code'] === $defaultTeamCode) {
        $defaultTeamId = $team['id'];
        break;
    }
}
if ($defaultTeamId === null && $teams) {
    $defaultTeamId = $teams[0]['id'];
}

respond_json([
    'authenticated' => true,
    'user' => [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'must_reset_password' => (bool)$user['must_reset_password'],
    ],
    'pages' => $pages,
    'teams' => $teams,
    'default_team_id' => $defaultTeamId,
    'default_month' => $cfg['default_month'] ?? (new DateTime('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m'),
]);
