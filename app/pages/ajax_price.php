<?php

declare(strict_types=1);

auth_require_login();

header('Content-Type: application/json; charset=utf-8');

if (!app_db_initialized()) {
    echo json_encode(['ok' => false, 'error' => 'Database not initialized']);
    exit;
}

$customerId = (int)($_GET['customer_id'] ?? 0);
$productId = (int)($_GET['product_id'] ?? 0);
$adminId = (int)($_SESSION['admin_id'] ?? 0);

if ($customerId <= 0 || $productId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing customer_id or product_id']);
    exit;
}

$stmt = db()->prepare('
    SELECT
        p.id,
        p.name,
        p.cost_price,
        p.sell_price AS default_sell_price,
        cpp.custom_sell_price
    FROM products p
    LEFT JOIN customer_product_prices cpp
        ON cpp.product_id = p.id AND cpp.customer_id = :customer_id AND cpp.admin_id = :aid
    WHERE p.id = :product_id AND p.admin_id = :aid
    LIMIT 1
');
$stmt->execute([
    ':customer_id' => $customerId,
    ':product_id' => $productId,
    ':aid' => $adminId,
]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'Product not found']);
    exit;
}

$sell = $row['custom_sell_price'] !== null ? to_float($row['custom_sell_price']) : to_float($row['default_sell_price']);

echo json_encode([
    'ok' => true,
    'product_id' => (int)$row['id'],
    'product_name' => (string)$row['name'],
    'cost_price' => to_float($row['cost_price']),
    'sell_price' => $sell,
    'is_custom' => $row['custom_sell_price'] !== null,
]);

