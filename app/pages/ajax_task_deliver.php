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
$paymentAmount = to_float($data['payment_amount'] ?? 0);
$paymentMethod = trim((string)($data['payment_method'] ?? 'Cash'));
$saleDate = trim((string)($data['sale_date'] ?? today_iso()));
$paymentDate = trim((string)($data['payment_date'] ?? $saleDate));
$saleNotes = trim((string)($data['notes'] ?? ''));

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
    app_ext_api_send_json(400, ['ok' => false, 'error' => 'Task already delivered']);
}

$customerId = (int)$task['customer_id'];
$amount = to_float($task['amount']);
$taskName = (string)$task['task_name'];

if ($amount <= 0) {
    app_ext_api_send_json(400, ['ok' => false, 'error' => 'Task amount must be > 0']);
}

// Generate invoice number
$prefix = 'INV-' . date('Ymd') . '-';
$countStmt = db()->prepare('SELECT COUNT(*) FROM sales WHERE admin_id = :aid AND sale_date = :d');
$countStmt->execute([':aid' => $ownerId, ':d' => today_iso()]);
$count = (int)$countStmt->fetchColumn();
$invoiceNo = $prefix . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);

$items = [[
    'product_id' => null,
    'name' => $taskName,
    'sku' => 'TASK',
    'qty' => 1,
    'cost_price' => 0,
    'sell_price' => $amount,
    'line_cost' => 0,
    'line_sell' => $amount,
    'line_profit' => $amount,
    'is_custom' => true,
]];

$pdo = db();
try {
    $pdo->beginTransaction();

    // 1. Create Sale
    $stmt = $pdo->prepare('
        INSERT INTO sales (invoice_no, customer_id, sale_date, items_json, total_cost, total_sell, total_profit, paid_amount, notes, admin_id)
        VALUES (:invoice_no, :customer_id, :sale_date, :items_json, 0, :total_sell, :total_profit, :paid_amount, :notes, :aid)
    ');
    $combinedNotes = $taskName;
    if ($saleNotes !== '') {
        $combinedNotes .= ' — ' . $saleNotes;
    }
    $stmt->execute([
        ':invoice_no' => $invoiceNo,
        ':customer_id' => $customerId,
        ':sale_date' => $saleDate === '' ? today_iso() : $saleDate,
        ':items_json' => json_encode($items, JSON_UNESCAPED_UNICODE),
        ':total_sell' => $amount,
        ':total_profit' => $amount,
        ':paid_amount' => max(0.0, min($paymentAmount, $amount)),
        ':notes' => $combinedNotes,
        ':aid' => $ownerId,
    ]);
    $saleId = (int)$pdo->lastInsertId();

    // 2. Create Payment (if any)
    if ($paymentAmount > 0) {
        $stmt = $pdo->prepare('
            INSERT INTO payments (sale_id, payment_date, amount, method, note, admin_id)
            VALUES (:sale_id, :payment_date, :amount, :method, :note, :aid)
        ');
        $stmt->execute([
            ':sale_id' => $saleId,
            ':payment_date' => $paymentDate === '' ? today_iso() : $paymentDate,
            ':amount' => max(0.0, min($paymentAmount, $amount)),
            ':method' => $paymentMethod === '' ? 'Cash' : $paymentMethod,
            ':note' => 'Task delivery payment',
            ':aid' => $ownerId,
        ]);
    }

    // 3. Update task → delivered
    $stmt = $pdo->prepare('
        UPDATE customer_tasks 
        SET status = \'delivered\', sale_id = :sid, delivered_at = datetime(\'now\')
        WHERE id = :id
    ');
    $stmt->execute([':sid' => $saleId, ':id' => $taskId]);

    $pdo->commit();

    app_audit_log('deliver', 'task', $taskId, [
        'sale_id' => $saleId,
        'invoice_no' => $invoiceNo,
        'customer_id' => $customerId,
        'amount' => $amount,
        'paid' => $paymentAmount,
    ]);

    echo json_encode([
        'ok' => true,
        'task_id' => $taskId,
        'sale_id' => $saleId,
        'invoice_no' => $invoiceNo,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Delivery failed']);
}
