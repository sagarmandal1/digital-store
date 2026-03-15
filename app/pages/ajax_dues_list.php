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
    $stmt = db()->prepare("
        SELECT 
            c.id, c.name, c.phone,
            COALESCE(SUM(s.total_sell), 0) - COALESCE(SUM(s.paid_amount), 0) as due
        FROM customers c
        LEFT JOIN sales s ON s.customer_id = c.id
        WHERE (c.admin_id = :aid OR :aid = 0)
        GROUP BY c.id
        HAVING due > 0.01
        ORDER BY due DESC
        LIMIT 20
    ");
    $stmt->execute([':aid' => $aid]);
    $rows = $stmt->fetchAll() ?: [];

    echo json_encode([
        'ok' => true,
        'dues' => array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'name' => (string)$r['name'],
                'phone' => (string)($r['phone'] ?? ''),
                'due' => to_float($r['due']),
            ];
        }, $rows),
    ]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Dues failed']);
}
