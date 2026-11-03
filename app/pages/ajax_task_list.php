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

app_ensure_customer_tasks_table();

$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$status = trim((string)($_GET['status'] ?? 'pending'));
if ($status !== 'pending' && $status !== 'delivered' && $status !== 'all') {
    $status = 'pending';
}

try {
    $sql = "
        SELECT t.id, t.customer_id, t.task_name, t.notes, t.amount, t.status,
               t.sale_id, t.created_at, t.delivered_at,
               c.name AS customer_name, c.phone AS customer_phone
        FROM customer_tasks t
        JOIN customers c ON c.id = t.customer_id
        WHERE (t.admin_id = :aid OR :aid = 0)
    ";
    $params = [':aid' => $aid];

    if ($customerId > 0) {
        $sql .= " AND t.customer_id = :cid";
        $params[':cid'] = $customerId;
    }
    if ($status !== 'all') {
        $sql .= " AND t.status = :status";
        $params[':status'] = $status;
    }

    $sql .= " ORDER BY t.status ASC, t.created_at DESC LIMIT 50";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll() ?: [];

    $result = array_map(function ($t) {
        return [
            'id' => (int)$t['id'],
            'customer_id' => (int)$t['customer_id'],
            'customer_name' => (string)($t['customer_name'] ?? ''),
            'customer_phone' => (string)($t['customer_phone'] ?? ''),
            'task_name' => (string)$t['task_name'],
            'notes' => (string)($t['notes'] ?? ''),
            'amount' => to_float($t['amount']),
            'status' => (string)$t['status'],
            'sale_id' => $t['sale_id'] !== null ? (int)$t['sale_id'] : null,
            'created_at' => (string)$t['created_at'],
            'delivered_at' => (string)($t['delivered_at'] ?? ''),
        ];
    }, $tasks);

    echo json_encode(['ok' => true, 'tasks' => $result, 'count' => count($result)]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Task list failed']);
}
