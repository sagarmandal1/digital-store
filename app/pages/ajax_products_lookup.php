<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
app_ext_api_allow_cors();

if (!app_db_initialized()) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Database not initialized']);
}

$tokenAdminId = app_ext_api_require_token_admin_id();
$aid = app_ext_api_scope_admin_id($tokenAdminId);
$aid = max(0, $aid);

$q = trim((string)($_GET['q'] ?? ''));
$customerId = (int)($_GET['customer_id'] ?? 0);

try {
    $sql = "
        SELECT 
            p.id, p.name, p.sku, p.sell_price,
            cpp.custom_sell_price
        FROM products p
        LEFT JOIN customer_product_prices cpp
            ON cpp.product_id = p.id 
            AND cpp.customer_id = :cid
            AND cpp.admin_id = :aid
        WHERE (p.admin_id = :aid OR :aid = 0) AND p.active = 1
    ";
    $params = [':aid' => $aid, ':cid' => $customerId];

    if ($q !== '') {
        $sql .= " AND (p.name LIKE :q OR p.sku LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $sql .= " ORDER BY p.name ASC LIMIT 20";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    echo json_encode([
        'ok' => true,
        'products' => array_map(function ($r) {
            $custom = $r['custom_sell_price'] !== null;
            $price = $custom ? to_float($r['custom_sell_price']) : to_float($r['sell_price']);
            return [
                'id' => (int)$r['id'],
                'name' => (string)$r['name'],
                'sku' => (string)($r['sku'] ?? ''),
                'price' => $price,
                'is_custom' => $custom,
            ];
        }, $rows),
    ]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Products lookup failed']);
}
