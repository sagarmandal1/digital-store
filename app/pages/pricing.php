<?php

declare(strict_types=1);

auth_require_login();

csrf_verify();

if (!app_db_initialized()) {
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <h1 class="h3">Custom Pricing</h1>
    <div class="alert alert-warning">Initialize the database first.</div>
    <?php
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php';
    exit;
}

$customerId = (int)($_GET['customer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $adminId = app_require_user_scope_for_write();
    
    $stmt = db()->prepare('SELECT 1 FROM customers WHERE id = :id AND admin_id = :aid LIMIT 1');
    $stmt->execute([':id' => $customerId, ':aid' => $adminId]);
    if (!$stmt->fetchColumn()) {
        flash_set('danger', 'Invalid customer.');
        header('Location: index.php?page=pricing');
        exit;
    }

    $custom = $_POST['custom'] ?? [];
    if (!is_array($custom)) {
        $custom = [];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmtUpsert = $pdo->prepare('
            INSERT INTO customer_product_prices (customer_id, product_id, custom_sell_price, admin_id)
            VALUES (:customer_id, :product_id, :custom_sell_price, :aid)
            ON CONFLICT(customer_id, product_id, admin_id) DO UPDATE SET custom_sell_price=excluded.custom_sell_price
        ');
        $stmtDelete = $pdo->prepare('DELETE FROM customer_product_prices WHERE customer_id=:customer_id AND product_id=:product_id AND admin_id=:aid');

        foreach ($custom as $productId => $value) {
            $pid = (int)$productId;
            if ($pid <= 0) {
                continue;
            }

            $raw = trim((string)$value);
            if ($raw === '') {
                $stmtDelete->execute([':customer_id' => $customerId, ':product_id' => $pid, ':aid' => $adminId]);
                continue;
            }

            $price = to_float($raw);
            if ($price < 0) {
                continue;
            }

            $stmtUpsert->execute([
                ':customer_id' => $customerId,
                ':product_id' => $pid,
                ':custom_sell_price' => $price,
                ':aid' => $adminId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    flash_set('success', 'Custom prices saved.');
    header('Location: index.php?page=pricing&customer_id=' . $customerId);
    exit;
}

$adminId = app_scope_admin_id();
$stmt = db()->prepare('SELECT id, name, email FROM customers WHERE (admin_id = :aid OR :aid = 0) ORDER BY name ASC');
$stmt->execute([':aid' => $adminId]);
$customers = $stmt->fetchAll();

$stmt = db()->prepare('SELECT id, name, sku, cost_price, sell_price, active FROM products WHERE (admin_id = :aid OR :aid = 0) ORDER BY name ASC');
$stmt->execute([':aid' => $adminId]);
$products = $stmt->fetchAll();

$customPrices = [];
if ($customerId > 0) {
    $stmt = db()->prepare('SELECT admin_id FROM customers WHERE id = :id AND (admin_id = :aid OR :aid = 0) LIMIT 1');
    $stmt->execute([':id' => $customerId, ':aid' => $adminId]);
    $customerOwnerId = (int)($stmt->fetchColumn() ?: 0);
    if ($customerOwnerId > 0) {
        $stmt = db()->prepare('SELECT product_id, custom_sell_price FROM customer_product_prices WHERE customer_id=:customer_id AND admin_id=:aid');
        $stmt->execute([':customer_id' => $customerId, ':aid' => $customerOwnerId]);
        foreach ($stmt->fetchAll() as $row) {
            $customPrices[(int)$row['product_id']] = to_float($row['custom_sell_price']);
        }
    } else {
        $customerId = 0; // Invalid customer for this admin
    }
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
$currency = app_setting('currency_symbol', '');
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0">Custom Pricing</h1>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get" action="index.php">
            <input type="hidden" name="page" value="pricing">
            <div class="col-12 col-md-6">
                <label class="form-label">Customer</label>
                <select class="form-select" name="customer_id" required>
                    <option value="">Select customer...</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $customerId ? 'selected' : '' ?>>
                            <?= e((string)$c['name']) ?><?= ($c['email'] ?? '') !== '' ? ' (' . e((string)$c['email']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-auto">
                <button class="btn btn-outline-primary" type="submit">Load</button>
            </div>
        </form>
    </div>
</div>

<?php if ($customerId > 0): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post" action="index.php?page=pricing">
                <?= csrf_field() ?>
                <input type="hidden" name="customer_id" value="<?= $customerId ?>">

                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th class="text-end">Cost</th>
                            <th class="text-end">Default Sell</th>
                            <th class="text-end">Custom Sell</th>
                            <th class="text-center">Active</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($products as $p): ?>
                            <?php
                            $pid = (int)$p['id'];
                            $customVal = array_key_exists($pid, $customPrices) ? (string)$customPrices[$pid] : '';
                            ?>
                            <tr>
                                <td><?= e((string)$p['name']) ?></td>
                                <td><?= e((string)($p['sku'] ?? '')) ?></td>
                                <td class="text-end"><?= e(money_fmt(to_float($p['cost_price']), $currency)) ?></td>
                                <td class="text-end"><?= e(money_fmt(to_float($p['sell_price']), $currency)) ?></td>
                                <td class="text-end" style="max-width: 180px;">
                                    <input class="form-control form-control-sm text-end" name="custom[<?= $pid ?>]" value="<?= e($customVal) ?>" placeholder="(blank = default)" inputmode="decimal">
                                </td>
                                <td class="text-center"><?= (int)$p['active'] === 1 ? 'Yes' : 'No' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex gap-2">
                    <button class="btn btn-success" type="submit">Save Prices</button>
                    <a class="btn btn-outline-secondary" href="index.php?page=pricing&customer_id=<?= $customerId ?>">Reload</a>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-info">Select a customer to manage custom prices.</div>
<?php endif; ?>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
