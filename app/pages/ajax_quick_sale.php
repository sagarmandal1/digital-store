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

$rawItems = $data['items'] ?? null;
$isMulti = is_array($rawItems) && !empty($rawItems);

if ($customerId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'customer_id required']);
    exit;
}

if (!$isMulti && $amount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'amount required']);
    exit;
}

$stmt = $pdo->prepare('SELECT 1 FROM customers WHERE id = :id AND admin_id = :aid LIMIT 1');
$stmt->execute([':id' => $customerId, ':aid' => $ownerId]);
if (!$stmt->fetchColumn()) {
    app_ext_api_send_json(404, ['ok' => false, 'error' => 'Customer not found']);
}

function generate_invoice_no(PDO $pdo, int $aid): string
{
    $prefix = 'INV-' . date('Ymd') . '-';
    // Count ALL sales for today globally (not per admin) since invoice_no has a UNIQUE constraint
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE sale_date = :d');
    $stmt->execute([':d' => today_iso()]);
    $count = (int)$stmt->fetchColumn();
    $candidate = $prefix . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
    // Check if this invoice_no already exists (race condition guard)
    $chk = $pdo->prepare('SELECT 1 FROM sales WHERE invoice_no = :inv LIMIT 1');
    $chk->execute([':inv' => $candidate]);
    if ($chk->fetchColumn()) {
        // Fallback: append random suffix
        $candidate = $prefix . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT) . '-' . random_int(100, 9999);
    }
    return $candidate;
}

try {
    $items = [];
    $totalSell = 0.0;
    $totalCost = 0.0;

    if ($isMulti) {
        foreach ($rawItems as $ri) {
            $pId = isset($ri['product_id']) && (int)$ri['product_id'] > 0 ? (int)$ri['product_id'] : null;
            $iQty = max(1, min(999, (int)($ri['qty'] ?? 1)));
            $iSell = to_float($ri['sell_price'] ?? 0);
            $iName = trim((string)($ri['name'] ?? ''));
            $iCost = 0.0;
            $iSku = 'WA';

            if ($pId) {
                $stmt = $pdo->prepare("
                    SELECT p.id, p.name, p.sku, p.cost_price, p.sell_price, cpp.custom_sell_price
                    FROM products p
                    LEFT JOIN customer_product_prices cpp
                        ON cpp.product_id = p.id AND cpp.customer_id = :cid AND cpp.admin_id = :aid
                    WHERE p.id = :pid AND (p.admin_id = :aid OR p.admin_id = 0) AND p.active = 1
                    LIMIT 1
                ");
                $stmt->execute([':pid' => $pId, ':cid' => $customerId, ':aid' => $ownerId]);
                $p = $stmt->fetch();
                if ($p) {
                    $iCost = to_float($p['cost_price'] ?? 0);
                    $iSku = (string)($p['sku'] ?? 'PRD');
                    if ($iName === '') {
                        $iName = (string)($p['name'] ?? 'Product');
                    }
                    if ($iSell <= 0) {
                        $iSell = to_float($p['custom_sell_price'] ?? $p['sell_price'] ?? 0);
                    }
                }
            }
            if ($iName === '') {
                $iName = 'WhatsApp Quick Sale Item';
            }

            $lineSell = $iSell * $iQty;
            $lineCost = $iCost * $iQty;
            $lineProfit = $lineSell - $lineCost;

            $totalSell += $lineSell;
            $totalCost += $lineCost;

            $items[] = [
                'product_id' => $pId,
                'name' => $iName,
                'sku' => $iSku,
                'qty' => $iQty,
                'cost_price' => $iCost,
                'sell_price' => $iSell,
                'line_cost' => $lineCost,
                'line_sell' => $lineSell,
                'line_profit' => $lineProfit,
                'is_custom' => true,
            ];
        }
        $totalProfit = $totalSell - $totalCost;
    } else {
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
            $sku = (string)($p['sku'] ?? '');
            $itemName = (string)($p['name'] ?? $itemName);
            $itemProductId = (int)$p['id'];

            // Use user's amount as sell price (allows override), keep cost from database
            $unitSell = $qty > 0 ? ($amount / $qty) : $amount;
            $isCustom = true;
        } else {
            $unitSell = $qty > 0 ? ($amount / $qty) : $amount;
        }

        $lineSell = $unitSell * $qty;
        $lineCost = $unitCost * $qty;
        $lineProfit = $lineSell - $lineCost;

        $totalSell = $lineSell;
        $totalCost = $lineCost;
        $totalProfit = $lineProfit;

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
    }

    if ($totalSell <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Total sale amount must be greater than zero']);
        exit;
    }

    $invoiceNo = generate_invoice_no($pdo, $ownerId);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('
        INSERT INTO sales (invoice_no, customer_id, sale_date, items_json, total_cost, total_sell, total_profit, paid_amount, notes, admin_id)
        VALUES (:invoice_no, :customer_id, :sale_date, :items_json, :total_cost, :total_sell, :total_profit, :paid_amount, :notes, :aid)
    ');
    $stmt->execute([
        ':invoice_no' => $invoiceNo,
        ':customer_id' => $customerId,
        ':sale_date' => $saleDate === '' ? today_iso() : $saleDate,
        ':items_json' => json_encode($items, JSON_UNESCAPED_UNICODE),
        ':total_cost' => $totalCost,
        ':total_sell' => $totalSell,
        ':total_profit' => $totalProfit,
        ':paid_amount' => max(0.0, min($paymentAmount, $totalSell)),
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
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('ajax_quick_sale error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['ok' => false, 'error' => 'Sale failed: ' . $e->getMessage()]);
}
