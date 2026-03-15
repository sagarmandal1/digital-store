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
$notes = trim((string)($data['notes'] ?? ''));
$saleDate = trim((string)($data['sale_date'] ?? today_iso()));
$paymentAmount = to_float($data['payment_amount'] ?? 0);
$paymentMethod = trim((string)($data['payment_method'] ?? 'Cash'));
$paymentDate = trim((string)($data['payment_date'] ?? $saleDate));
$productId = isset($data['product_id']) ? (int)$data['product_id'] : null;
$productName = trim((string)($data['product_name'] ?? 'WhatsApp Quick Sale'));
$qty = (int)($data['qty'] ?? 1);
$qty = max(1, min(999, $qty));

if ($customerId <= 0 || $amount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'customer_id and amount required']);
    exit;
}

function generate_invoice_no(PDO $pdo, int $aid): string
{
    $prefix = 'INV-' . date('Ymd') . '-';
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE admin_id = :aid AND sale_date = :d');
    $stmt->execute([':aid' => $aid, ':d' => today_iso()]);
    $count = (int)$stmt->fetchColumn();
    return $prefix . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
}

try {
    $unitSell = $amount;
    $unitCost = 0.0;
    $isCustom = true;
    $sku = 'WA';
    $itemName = $productName === '' ? 'WhatsApp Quick Sale' : $productName;
    $itemProductId = null;

    if (is_int($productId) && $productId > 0) {
        $stmt = $pdo->prepare("
            SELECT 
                p.id, p.name, p.sku, p.cost_price, p.sell_price,
                cpp.custom_sell_price
            FROM products p
            LEFT JOIN customer_product_prices cpp
                ON cpp.product_id = p.id AND cpp.customer_id = :cid AND cpp.admin_id = :aid
            WHERE p.id = :pid AND (p.admin_id = :aid OR p.admin_id = 0) AND p.active = 1
            LIMIT 1
        ");
        $stmt->execute([':pid' => $productId, ':cid' => $customerId, ':aid' => $ownerId]);
        $p = $stmt->fetch();
        if (!$p) {
            echo json_encode(['ok' => false, 'error' => 'Product not found']);
            exit;
        }

        $unitCost = to_float($p['cost_price'] ?? 0);
        $isCustom = $p['custom_sell_price'] !== null;
        $unitSell = $isCustom ? to_float($p['custom_sell_price']) : to_float($p['sell_price']);
        $sku = (string)($p['sku'] ?? '');
        $itemName = (string)($p['name'] ?? $itemName);
        $itemProductId = (int)$p['id'];
    } else {
        $unitSell = $qty > 0 ? ($amount / $qty) : $amount;
    }

    $lineSell = $unitSell * $qty;
    $lineCost = $unitCost * $qty;
    $lineProfit = $lineSell - $lineCost;

    $items = [[
        'product_id' => $itemProductId,
        'name' => $itemName,
        'sku' => $sku === '' ? ($itemProductId ? 'PRD' : 'WA') : $sku,
        'qty' => $qty,
        'cost_price' => $unitCost,
        'sell_price' => $unitSell,
        'line_cost' => $lineCost,
        'line_sell' => $lineSell,
        'line_profit' => $lineProfit,
        'is_custom' => $isCustom,
    ]];

    $invoiceNo = generate_invoice_no($pdo, $ownerId);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('
        INSERT INTO sales (invoice_no, customer_id, sale_date, items_json, total_cost, total_sell, total_profit, paid_amount, notes, admin_id)
        VALUES (:invoice_no, :customer_id, :sale_date, :items_json, 0, :total_sell, :total_profit, :paid_amount, :notes, :aid)
    ');
    $stmt->execute([
        ':invoice_no' => $invoiceNo,
        ':customer_id' => $customerId,
        ':sale_date' => $saleDate === '' ? today_iso() : $saleDate,
        ':items_json' => json_encode($items, JSON_UNESCAPED_UNICODE),
        ':total_sell' => $lineSell,
        ':total_profit' => $lineProfit,
        ':paid_amount' => max(0.0, min($paymentAmount, $lineSell)),
        ':notes' => $notes === '' ? null : $notes,
        ':aid' => $ownerId,
    ]);
    $saleId = (int)$pdo->lastInsertId();

    if ($paymentAmount > 0) {
        $stmt = $pdo->prepare('
            INSERT INTO payments (sale_id, payment_date, amount, method, note, admin_id)
            VALUES (:sale_id, :payment_date, :amount, :method, :note, :aid)
        ');
        $stmt->execute([
            ':sale_id' => $saleId,
            ':payment_date' => $paymentDate === '' ? today_iso() : $paymentDate,
            ':amount' => max(0.0, min($paymentAmount, $lineSell)),
            ':method' => $paymentMethod === '' ? null : $paymentMethod,
            ':note' => 'Initial payment',
            ':aid' => $ownerId,
        ]);
    }

    $pdo->commit();
    app_audit_log('create', 'sale', $saleId, ['invoice_no' => $invoiceNo, 'customer_id' => $customerId, 'total_sell' => $lineSell, 'paid_amount' => $paymentAmount]);
    echo json_encode(['ok' => true, 'sale_id' => $saleId, 'invoice_no' => $invoiceNo]);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Sale failed']);
}
