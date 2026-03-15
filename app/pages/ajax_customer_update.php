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

$body = file_get_contents('php://input');
$data = json_decode(is_string($body) ? $body : '', true) ?: [];

$cid = (int)($data['id'] ?? 0);
$name = trim((string)($data['name'] ?? ''));
$phone = trim((string)($data['phone'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$address = trim((string)($data['address'] ?? ''));
$category = trim((string)($data['category'] ?? ''));

if ($cid <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid id']);
    exit;
}

try {
    $stmt = db()->prepare('UPDATE customers SET name=:name, email=:email, phone=:phone, address=:address, category=:cat WHERE id=:id AND admin_id=:aid');
    $stmt->execute([
        ':id' => $cid,
        ':name' => $name === '' ? null : $name,
        ':email' => $email === '' ? null : $email,
        ':phone' => $phone === '' ? null : $phone,
        ':address' => $address === '' ? null : $address,
        ':cat' => in_array($category, ['Regular', 'VIP'], true) ? $category : ($category === '' ? null : 'Regular'),
        ':aid' => $ownerId,
    ]);
    app_audit_log('update', 'customer', $cid, ['name' => $name]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Update failed']);
}
