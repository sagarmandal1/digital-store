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

$customerId = (int)($_GET['customer_id'] ?? 0);
if ($customerId <= 0) {
    app_ext_api_send_json(400, ['ok' => false, 'error' => 'customer_id required']);
}

try {
    $stmt = db()->prepare("
        SELECT 
            s.id AS sale_id,
            s.invoice_no,
            s.sale_date AS date,
            s.total_sell AS amount,
            'sale' AS type
        FROM sales s
        WHERE s.customer_id = :cid AND (s.admin_id = :aid OR :aid = 0)
        UNION ALL
        SELECT
            p.sale_id AS sale_id,
            s.invoice_no,
            p.payment_date AS date,
            p.amount AS amount,
            'payment' AS type
        FROM payments p
        JOIN sales s ON s.id = p.sale_id
        WHERE s.customer_id = :cid AND (p.admin_id = :aid OR :aid = 0)
        ORDER BY date DESC
        LIMIT 20
    ");
    $stmt->execute([':cid' => $customerId, ':aid' => $aid]);
    $rows = $stmt->fetchAll() ?: [];
    echo json_encode([
        'ok' => true,
        'events' => array_map(function ($r) {
            return [
                'type' => (string)$r['type'],
                'invoice_no' => (string)$r['invoice_no'],
                'sale_id' => (int)$r['sale_id'],
                'date' => (string)$r['date'],
                'amount' => to_float($r['amount']),
            ];
        }, $rows),
    ]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Ledger failed']);
}

