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

app_ext_api_ensure_customer_notes_table();

$body = file_get_contents('php://input');
$data = json_decode(is_string($body) ? $body : '', true) ?: [];
$customerId = (int)($data['customer_id'] ?? 0);
$note = trim((string)($data['note'] ?? ''));

if ($customerId <= 0 || $note === '') {
    app_ext_api_send_json(400, ['ok' => false, 'error' => 'customer_id and note required']);
}

try {
    $stmt = db()->prepare('INSERT INTO customer_notes (customer_id, note, admin_id) VALUES (:cid, :note, :aid)');
    $stmt->execute([':cid' => $customerId, ':note' => $note, ':aid' => $aid]);
    $id = (int)db()->lastInsertId();
    echo json_encode(['ok' => true, 'note_id' => $id]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Note failed']);
}

