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

$limit = (int)($_GET['limit'] ?? 20);
$limit = max(1, min(100, $limit));
$offset = (int)($_GET['offset'] ?? 0);

try {
    $stmt = db()->prepare("
        SELECT s.*, c.name AS customer_name
        FROM sales s
        LEFT JOIN customers c ON c.id = s.customer_id
        WHERE (s.admin_id = :aid OR :aid = 0)
        ORDER BY s.sale_date DESC, s.id DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindParam(':aid', $aid, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];

    echo json_encode([
        'ok' => true,
        'sales' => array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'invoice_no' => (string)$r['invoice_no'],
                'customer' => (string)$r['customer_name'],
                'date' => (string)$r['sale_date'],
                'total' => to_float($r['total_sell']),
                'paid' => to_float($r['paid_amount']),
                'due' => max(0.0, to_float($r['total_sell']) - to_float($r['paid_amount'])),
            ];
        }, $rows),
    ]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Sales list failed']);
}
