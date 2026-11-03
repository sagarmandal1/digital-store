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

$body = file_get_contents('php://input');
$data = json_decode(is_string($body) ? $body : '', true) ?: [];

$customerId = (int)($data['customer_id'] ?? 0);
$productId = (int)($data['product_id'] ?? 0);
$customPrice = to_float($data['custom_price'] ?? $data['price'] ?? 0);

if ($customerId <= 0 || $productId <= 0) {
    app_ext_api_send_json(400, ['ok' => false, 'error' => 'customer_id and product_id required']);
}

// Verify customer
$stmt = db()->prepare('SELECT 1 FROM customers WHERE id = :id AND (admin_id = :aid OR :aid = 0) LIMIT 1');
$stmt->execute([':id' => $customerId, ':aid' => $ownerId]);
if (!$stmt->fetchColumn()) {
    app_ext_api_send_json(404, ['ok' => false, 'error' => 'Customer not found']);
}

// Verify product
$stmt = db()->prepare('SELECT 1 FROM products WHERE id = :id AND (admin_id = :aid OR :aid = 0) LIMIT 1');
$stmt->execute([':id' => $productId, ':aid' => $ownerId]);
if (!$stmt->fetchColumn()) {
    app_ext_api_send_json(404, ['ok' => false, 'error' => 'Product not found']);
}

try {
    $stmtUpsert = db()->prepare('
        INSERT INTO customer_product_prices (customer_id, product_id, custom_sell_price, admin_id)
        VALUES (:customer_id, :product_id, :custom_sell_price, :aid)
        ON CONFLICT(customer_id, product_id) DO UPDATE SET 
            custom_sell_price = excluded.custom_sell_price,
            admin_id = excluded.admin_id
    ');
    $stmtUpsert->execute([
        ':customer_id' => $customerId,
        ':product_id' => $productId,
        ':custom_sell_price' => $customPrice,
        ':aid' => $ownerId,
    ]);

    app_audit_log('update', 'custom_price', $customerId, [
        'product_id' => $productId,
        'custom_price' => $customPrice,
    ]);

    echo json_encode([
        'ok' => true,
        'customer_id' => $customerId,
        'product_id' => $productId,
        'custom_price' => $customPrice,
    ]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Failed to save custom price']);
}

