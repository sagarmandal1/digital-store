<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
app_ext_api_allow_cors();

if (!app_db_initialized()) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Database not initialized']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_ext_api_send_json(405, ['ok' => false, 'error' => 'POST required']);
}

$tokenAdminId = app_ext_api_require_token_admin_id();
$ownerId = app_ext_api_scope_admin_id($tokenAdminId);
if ($ownerId <= 0) {
    app_ext_api_send_json(403, ['ok' => false, 'error' => 'Select a user scope']);
}

app_ensure_customer_tasks_table();

$body = file_get_contents('php://input');
$data = json_decode(is_string($body) ? $body : '', true) ?: [];

$customerId = (int)($data['customer_id'] ?? 0);
$taskName = trim((string)($data['task_name'] ?? ''));
$notes = trim((string)($data['notes'] ?? ''));
$amount = to_float($data['amount'] ?? 0);

if ($customerId <= 0 || $taskName === '') {
    app_ext_api_send_json(400, ['ok' => false, 'error' => 'customer_id and task_name required']);
}

// Verify customer exists and belongs to this admin
$stmt = db()->prepare('SELECT 1 FROM customers WHERE id = :id AND admin_id = :aid LIMIT 1');
$stmt->execute([':id' => $customerId, ':aid' => $ownerId]);
if (!$stmt->fetchColumn()) {
    app_ext_api_send_json(404, ['ok' => false, 'error' => 'Customer not found']);
}

try {
    $stmt = db()->prepare('
        INSERT INTO customer_tasks (customer_id, admin_id, task_name, notes, amount, status)
        VALUES (:cid, :aid, :name, :notes, :amount, \'pending\')
    ');
    $stmt->execute([
        ':cid' => $customerId,
        ':aid' => $ownerId,
        ':name' => $taskName,
        ':notes' => $notes,
        ':amount' => $amount,
    ]);
    $taskId = (int)db()->lastInsertId();
    app_audit_log('create', 'task', $taskId, [
        'customer_id' => $customerId,
        'task_name' => $taskName,
        'amount' => $amount,
    ]);
    echo json_encode(['ok' => true, 'task_id' => $taskId]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Task creation failed']);
}
