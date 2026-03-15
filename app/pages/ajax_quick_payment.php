<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
app_ext_api_allow_cors();

if (!app_db_initialized()) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Database not initialized']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$tokenAdminId = app_ext_api_require_token_admin_id();
$ownerId = app_ext_api_scope_admin_id($tokenAdminId);
if ($ownerId <= 0) {
    app_ext_api_send_json(403, ['ok' => false, 'error' => 'Select a user scope']);
}
$pdo = db();

$body = file_get_contents('php://input');
$data = json_decode(is_string($body) ? $body : '', true) ?: [];

$customerId = (int)($data['customer_id'] ?? 0);
$amount = to_float($data['amount'] ?? 0);
$method = trim((string)($data['method'] ?? 'Cash'));
$date = trim((string)($data['payment_date'] ?? today_iso()));
$note = trim((string)($data['note'] ?? ''));

if ($customerId <= 0 || $amount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'customer_id and amount required']);
    exit;
}

try {
    $stmt = $pdo->prepare('
        SELECT id, total_sell, paid_amount 
        FROM sales 
        WHERE customer_id = :cid AND admin_id = :aid AND (total_sell - paid_amount) > 0.01 
        ORDER BY sale_date ASC, id ASC
    ');
    $stmt->execute([':cid' => $customerId, ':aid' => $ownerId]);
    $unpaid = $stmt->fetchAll();

    $remaining = $amount;
    $payments = [];

    $pdo->beginTransaction();
    foreach ($unpaid as $sale) {
        if ($remaining <= 0) break;
        $saleId = (int)$sale['id'];
        $due = max(0.0, to_float($sale['total_sell']) - to_float($sale['paid_amount']));
        $payAmt = min($remaining, $due);
        if ($payAmt > 0.0001) {
            $ins = $pdo->prepare('INSERT INTO payments (sale_id, payment_date, amount, method, note, admin_id) VALUES (:sid, :date, :amt, :method, :note, :aid)');
            $ins->execute([
                ':sid' => $saleId,
                ':date' => $date === '' ? today_iso() : $date,
                ':amt' => $payAmt,
                ':method' => $method === '' ? null : $method,
                ':note' => $note === '' ? null : $note,
                ':aid' => $ownerId,
            ]);
            $upd = $pdo->prepare('UPDATE sales SET paid_amount = paid_amount + :amt WHERE id = :sid AND admin_id = :aid');
            $upd->execute([':amt' => $payAmt, ':sid' => $saleId, ':aid' => $ownerId]);
            $payments[] = ['sale_id' => $saleId, 'amount' => $payAmt];
            $remaining -= $payAmt;
        }
    }
    $pdo->commit();

    app_audit_log('create', 'payment_batch', $customerId, ['amount' => $amount, 'remaining' => $remaining]);
    echo json_encode(['ok' => true, 'distributed' => $payments, 'remaining' => $remaining]);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Payment failed']);
}
