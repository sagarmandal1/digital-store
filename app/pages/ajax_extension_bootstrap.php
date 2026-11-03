<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
app_ext_api_allow_cors();

if (!app_db_initialized()) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Database not initialized']);
}

$tokenAdminId = app_ext_api_require_token_admin_id();
$scopeAdminId = app_ext_api_scope_admin_id($tokenAdminId);
$scopeAdminId = max(0, $scopeAdminId);
$canWrite = $tokenAdminId !== 1 || $scopeAdminId > 0;
$today = today_iso();

$profile = [
    'user' => ['id' => 0, 'name' => '', 'email' => ''],
    'scope' => ['id' => $scopeAdminId, 'name' => 'All Users'],
];
$stats = [
    'sales' => 0.0,
    'payments' => 0.0,
    'expenses' => 0.0,
    'net' => 0.0,
];

try {
    $stmt = db()->prepare('SELECT id, name, email FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $tokenAdminId]);
    $admin = $stmt->fetch() ?: [];
    $profile['user'] = [
        'id' => (int)($admin['id'] ?? 0),
        'name' => (string)($admin['name'] ?? ''),
        'email' => (string)($admin['email'] ?? ''),
    ];

    if ($scopeAdminId > 0 && $scopeAdminId !== $tokenAdminId) {
        $stmt->execute([':id' => $scopeAdminId]);
        $scope = $stmt->fetch() ?: [];
        $profile['scope'] = [
            'id' => $scopeAdminId,
            'name' => (string)($scope['name'] ?? ('User #' . $scopeAdminId)),
        ];
    } elseif ($scopeAdminId > 0) {
        $profile['scope'] = [
            'id' => $scopeAdminId,
            'name' => (string)($admin['name'] ?? ('User #' . $scopeAdminId)),
        ];
    }

    $stmt = db()->prepare("SELECT COALESCE(SUM(total_sell), 0) FROM sales WHERE sale_date = :d AND (admin_id = :aid OR :aid = 0)");
    $stmt->execute([':d' => $today, ':aid' => $scopeAdminId]);
    $stats['sales'] = to_float($stmt->fetchColumn());

    $stmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_date = :d AND (admin_id = :aid OR :aid = 0)");
    $stmt->execute([':d' => $today, ':aid' => $scopeAdminId]);
    $stats['payments'] = to_float($stmt->fetchColumn());

    $stmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date = :d AND (admin_id = :aid OR :aid = 0)");
    $stmt->execute([':d' => $today, ':aid' => $scopeAdminId]);
    $stats['expenses'] = to_float($stmt->fetchColumn());
    $stats['net'] = $stats['payments'] - $stats['expenses'];
} catch (Throwable $e) {
}

app_ext_api_send_json(200, [
    'ok' => true,
    'can_write' => $canWrite,
    'is_super_admin' => $tokenAdminId === 1,
    'today' => $today,
    'profile' => $profile,
    'global_notice' => app_setting_for_admin_id(0, 'global_notice', ''),
    'company_name' => app_setting_for_admin_id($scopeAdminId > 0 ? $scopeAdminId : $tokenAdminId, 'company_name', (string)app_config('app_name', 'Digital Store')),
    'payment_info' => app_setting_for_admin_id($scopeAdminId > 0 ? $scopeAdminId : $tokenAdminId, 'payment_info', ''),
    'stats' => $stats,
]);
