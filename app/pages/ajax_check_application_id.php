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

$appId = trim((string)($_GET['app_id'] ?? ''));
if ($appId === '') {
    echo json_encode(['ok' => true, 'exists' => false]);
    exit;
}

// Clean appId to alphanumeric + dashes
$cleanId = preg_replace('/[^A-Za-z0-9\-]/', '', $appId);
if (strlen($cleanId) < 3) {
    echo json_encode(['ok' => true, 'exists' => false]);
    exit;
}

try {
    app_ensure_customer_tasks_table();

    // 1. Search in customer_tasks
    $stmt = db()->prepare("
        SELECT t.id, t.customer_id, t.task_name, t.amount, t.status, t.created_at,
               c.name AS customer_name, c.phone AS customer_phone
        FROM customer_tasks t
        LEFT JOIN customers c ON c.id = t.customer_id
        WHERE (t.notes LIKE :q OR t.task_name LIKE :q)
          AND (t.admin_id = :aid OR :aid = 0)
        ORDER BY t.id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':q' => '%' . $cleanId . '%',
        ':aid' => $aid,
    ]);
    $task = $stmt->fetch();

    if ($task) {
        echo json_encode([
            'ok' => true,
            'exists' => true,
            'type' => 'task',
            'app_id' => $cleanId,
            'data' => [
                'id' => (int)$task['id'],
                'customer_id' => (int)$task['customer_id'],
                'customer_name' => (string)($task['customer_name'] ?? ''),
                'customer_phone' => (string)($task['customer_phone'] ?? ''),
                'task_name' => (string)$task['task_name'],
                'amount' => to_float($task['amount']),
                'status' => (string)$task['status'],
                'created_at' => (string)$task['created_at'],
            ],
        ]);
        exit;
    }

    // 2. Search in sales
    $stmtSale = db()->prepare("
        SELECT s.id, s.invoice_no, s.customer_id, s.total_sell, s.created_at,
               c.name AS customer_name, c.phone AS customer_phone
        FROM sales s
        LEFT JOIN customers c ON c.id = s.customer_id
        WHERE (s.notes LIKE :q OR s.items_json LIKE :q)
          AND (s.admin_id = :aid OR :aid = 0)
        ORDER BY s.id DESC
        LIMIT 1
    ");
    $stmtSale->execute([
        ':q' => '%' . $cleanId . '%',
        ':aid' => $aid,
    ]);
    $sale = $stmtSale->fetch();

    if ($sale) {
        echo json_encode([
            'ok' => true,
            'exists' => true,
            'type' => 'sale',
            'app_id' => $cleanId,
            'data' => [
                'id' => (int)$sale['id'],
                'invoice_no' => (string)$sale['invoice_no'],
                'customer_id' => (int)$sale['customer_id'],
                'customer_name' => (string)($sale['customer_name'] ?? ''),
                'customer_phone' => (string)($sale['customer_phone'] ?? ''),
                'total_sell' => to_float($sale['total_sell']),
                'created_at' => (string)$sale['created_at'],
            ],
        ]);
        exit;
    }

    // Not found
    echo json_encode([
        'ok' => true,
        'exists' => false,
        'app_id' => $cleanId,
    ]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Check failed: ' . $e->getMessage()]);
}
