<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
app_ext_api_allow_cors();

if (!app_db_initialized()) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Database not initialized']);
}

$tokenAdminId = app_ext_api_require_token_admin_id();
$aid = app_ext_api_scope_admin_id($tokenAdminId);
$aid = max(0, $aid);

try {
    $stmt = db()->prepare('SELECT id, name, email FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $tokenAdminId]);
    $admin = $stmt->fetch();

    if (!$admin) {
        app_ext_api_send_json(404, ['ok' => false, 'error' => 'Admin not found']);
    }

    $scopeAdminName = '';
    if ($aid > 0 && $aid !== $tokenAdminId) {
        $stmt = db()->prepare('SELECT name FROM admins WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $aid]);
        $scopeAdminName = (string)($stmt->fetchColumn() ?: '');
    }

    echo json_encode([
        'ok' => true,
        'user' => [
            'id' => (int)$admin['id'],
            'name' => (string)$admin['name'],
            'email' => (string)$admin['email'],
        ],
        'scope' => [
            'id' => $aid,
            'name' => $scopeAdminName ?: (string)$admin['name'],
        ],
    ]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Profile fetch failed']);
}
