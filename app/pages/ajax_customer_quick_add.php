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

$name = trim((string)($data['name'] ?? ''));
$phone = trim((string)($data['phone'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$address = trim((string)($data['address'] ?? ''));
$category = trim((string)($data['category'] ?? 'Regular'));

if ($name === '' && $phone === '') {
    echo json_encode(['ok' => false, 'error' => 'Name or phone required']);
    exit;
}

try {
    $stmt = db()->prepare('INSERT INTO customers (name, email, phone, address, category, admin_id) VALUES (:name, :email, :phone, :address, :cat, :aid)');
    $stmt->execute([
        ':name' => $name === '' ? ($phone !== '' ? $phone : 'Customer') : $name,
        ':email' => $email === '' ? null : $email,
        ':phone' => $phone === '' ? null : $phone,
        ':address' => $address === '' ? null : $address,
        ':cat' => in_array($category, ['Regular', 'VIP'], true) ? $category : 'Regular',
        ':aid' => $ownerId,
    ]);
    $cid = (int)db()->lastInsertId();
    app_audit_log('create', 'customer', $cid, ['name' => $name]);
    echo json_encode(['ok' => true, 'customer_id' => $cid]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Create failed']);
}
