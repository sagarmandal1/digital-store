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
$aid = app_ext_api_scope_admin_id($tokenAdminId);
if ($aid <= 0) {
    app_ext_api_send_json(403, ['ok' => false, 'error' => 'Select a user scope']);
}

$body = file_get_contents('php://input');
$data = json_decode(is_string($body) ? $body : '', true) ?: [];

$amount = to_float($data['amount'] ?? 0);
$category = trim((string)($data['category'] ?? 'General'));
$note = trim((string)($data['note'] ?? ''));
$date = trim((string)($data['date'] ?? today_iso()));

if ($amount <= 0) {
    app_ext_api_send_json(400, ['ok' => false, 'error' => 'Amount required']);
}

try {
    $stmt = db()->prepare('INSERT INTO expenses (category, amount, expense_date, notes, admin_id) VALUES (:cat, :amt, :d, :note, :aid)');
    $stmt->execute([
        ':cat' => $category,
        ':amt' => $amount,
        ':d' => $date,
        ':note' => $note === '' ? null : $note,
        ':aid' => $aid,
    ]);
    $id = (int)db()->lastInsertId();
    app_audit_log('create', 'expense', $id, ['amount' => $amount, 'category' => $category]);
    echo json_encode(['ok' => true, 'expense_id' => $id]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Expense failed']);
}
