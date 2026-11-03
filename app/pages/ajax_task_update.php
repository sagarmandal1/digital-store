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

$taskId = (int)($data['task_id'] ?? 0);
if ($taskId <= 0) {
    app_ext_api_send_json(400, ['ok' => false, 'error' => 'task_id required']);
}

// Fetch the task
$stmt = db()->prepare('SELECT * FROM customer_tasks WHERE id = :id AND admin_id = :aid LIMIT 1');
$stmt->execute([':id' => $taskId, ':aid' => $ownerId]);
$task = $stmt->fetch();

if (!$task) {
    app_ext_api_send_json(404, ['ok' => false, 'error' => 'Task not found']);
}
if ($task['status'] === 'delivered') {
    app_ext_api_send_json(400, ['ok' => false, 'error' => 'Cannot edit delivered task']);
}

// Only update provided fields
$taskName = isset($data['task_name']) ? trim((string)$data['task_name']) : null;
$notes = isset($data['notes']) ? trim((string)$data['notes']) : null;
$amount = isset($data['amount']) ? to_float($data['amount']) : null;

$sets = [];
$params = [':id' => $taskId];

if ($taskName !== null && $taskName !== '') {
    $sets[] = 'task_name = :name';
    $params[':name'] = $taskName;
}
if ($notes !== null) {
    $sets[] = 'notes = :notes';
    $params[':notes'] = $notes;
}
if ($amount !== null) {
    $sets[] = 'amount = :amount';
    $params[':amount'] = $amount;
}

if (empty($sets)) {
    app_ext_api_send_json(400, ['ok' => false, 'error' => 'Nothing to update']);
}

try {
    $sql = 'UPDATE customer_tasks SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    app_audit_log('update', 'task', $taskId, array_filter([
        'task_name' => $taskName,
        'notes' => $notes !== null ? '(updated)' : null,
        'amount' => $amount,
    ], fn($v) => $v !== null));
    echo json_encode(['ok' => true, 'task_id' => $taskId]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Update failed']);
}
