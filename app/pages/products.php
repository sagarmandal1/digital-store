<?php

declare(strict_types=1);

auth_require_login();

csrf_verify();

$action = (string)($_GET['action'] ?? 'index');
$id = (int)($_GET['id'] ?? 0);

if (!app_db_initialized()) {
    $action = 'index';
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($action === 'create' || $action === 'edit')) {
    app_require_user_scope_for_write();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $adminId = app_require_user_scope_for_write();
    if ($id <= 0) {
        flash_set('danger', 'Invalid product.');
        header('Location: index.php?page=products');
        exit;
    }

    try {
        $stmt = db()->prepare('DELETE FROM products WHERE id = :id AND admin_id = :aid');
        $stmt->execute([':id' => $id, ':aid' => $adminId]);
        
        if ($stmt->rowCount() > 0) {
            app_audit_log('delete', 'product', $id);
            flash_set('success', 'Product deleted.');
        } else {
            flash_set('danger', 'Product not found or access denied.');
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash_set('danger', 'Cannot delete product. This product is used in sales records.');
        } else {
            flash_set('danger', 'Error deleting product: ' . $e->getMessage());
        }
    }

    header('Location: index.php?page=products');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'create' || $action === 'edit')) {
    $name = trim((string)($_POST['name'] ?? ''));
    $sku = trim((string)($_POST['sku'] ?? ''));
    $cost = to_float($_POST['cost_price'] ?? 0);
    $sell = to_float($_POST['sell_price'] ?? 0);
    $active = isset($_POST['active']) ? 1 : 0;
    $ownerId = app_require_user_scope_for_write();

    if ($name === '') {
        flash_set('danger', 'Name is required.');
        header('Location: index.php?page=products&action=' . $action . ($action === 'edit' ? '&id=' . $id : ''));
        exit;
    }

    if ($cost < 0 || $sell < 0) {
        flash_set('danger', 'Prices must be non-negative.');
        header('Location: index.php?page=products&action=' . $action . ($action === 'edit' ? '&id=' . $id : ''));
        exit;
    }

    if ($action === 'create') {
        $stmt = db()->prepare('INSERT INTO products (name, sku, cost_price, sell_price, active, admin_id) VALUES (:name, :sku, :cost, :sell, :active, :aid)');
        $stmt->execute([
            ':name' => $name,
            ':sku' => $sku === '' ? null : $sku,
            ':cost' => $cost,
            ':sell' => $sell,
            ':active' => $active,
            ':aid' => $ownerId,
        ]);
        $newId = (int)db()->lastInsertId();
        app_audit_log('create', 'product', $newId, ['name' => $name]);
        flash_set('success', 'Product created.');
        header('Location: index.php?page=products');
        exit;
    }

    if ($id <= 0) {
        flash_set('danger', 'Invalid product.');
        header('Location: index.php?page=products');
        exit;
    }

    $stmt = db()->prepare('UPDATE products SET name=:name, sku=:sku, cost_price=:cost, sell_price=:sell, active=:active WHERE id=:id AND admin_id=:aid');
    $stmt->execute([
        ':id' => $id,
        ':name' => $name,
        ':sku' => $sku === '' ? null : $sku,
        ':cost' => $cost,
        ':sell' => $sell,
        ':active' => $active,
        ':aid' => $ownerId,
    ]);
    if ($stmt->rowCount() > 0) {
        app_audit_log('update', 'product', $id, ['name' => $name]);
    }
    flash_set('success', 'Product updated.');
    header('Location: index.php?page=products');
    exit;
}

$product = null;
if ($action === 'edit' && $id > 0) {
    $scopeId = app_scope_admin_id();
    $stmt = db()->prepare('SELECT * FROM products WHERE id = :id AND admin_id = :aid');
    $stmt->execute([':id' => $id, ':aid' => $scopeId]);
    $product = $stmt->fetch();
    if (!$product) {
        flash_set('danger', 'Product not found.');
        header('Location: index.php?page=products');
        exit;
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$products = [];
if (app_db_initialized()) {
    $adminId = app_scope_admin_id();
    if ($search !== '') {
        $stmt = db()->prepare("SELECT * FROM products WHERE (name LIKE :q OR sku LIKE :q) AND (admin_id = :aid OR :aid = 0) ORDER BY id DESC LIMIT 300");
        $stmt->execute([':q' => '%' . $search . '%', ':aid' => $adminId]);
        $products = $stmt->fetchAll();
    } else {
        $stmt = db()->prepare('SELECT * FROM products WHERE (admin_id = :aid OR :aid = 0) ORDER BY id DESC LIMIT 300');
        $stmt->execute([':aid' => $adminId]);
        $products = $stmt->fetchAll();
    }
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
$currency = app_setting('currency_symbol', '');
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0">Products</h1>
    <?php if (app_can_write()): ?>
        <a class="btn btn-primary" href="index.php?page=products&action=create">Add Product</a>
    <?php else: ?>
        <a class="btn btn-primary disabled" aria-disabled="true">Add Product</a>
    <?php endif; ?>
</div>

<?php if ($action === 'create' || $action === 'edit'): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h5 mb-3"><?= $action === 'create' ? 'Create Product' : 'Edit Product' ?></h2>
            <form method="post" action="index.php?page=products&action=<?= e($action) ?><?= $action === 'edit' ? '&id=' . (int)$id : '' ?>">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label">Name</label>
                        <input class="form-control" name="name" value="<?= e((string)($product['name'] ?? '')) ?>" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">SKU</label>
                        <input class="form-control" name="sku" value="<?= e((string)($product['sku'] ?? '')) ?>">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Cost Price</label>
                        <input class="form-control" name="cost_price" type="number" step="0.01" min="0" value="<?= e((string)($product['cost_price'] ?? '0')) ?>" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Default Sell Price</label>
                        <input class="form-control" name="sell_price" type="number" step="0.01" min="0" value="<?= e((string)($product['sell_price'] ?? '0')) ?>" required>
                    </div>
                    <div class="col-12 col-md-4 d-flex align-items-end">
                        <div class="form-check">
                            <?php $isActive = (int)($product['active'] ?? 1) === 1; ?>
                            <input class="form-check-input" type="checkbox" name="active" id="active" <?= $isActive ? 'checked' : '' ?>>
                            <label class="form-check-label" for="active">Active</label>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-success" type="submit">Save</button>
                    <a class="btn btn-outline-secondary" href="index.php?page=products">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form class="row g-2 mb-3" method="get" action="index.php">
            <input type="hidden" name="page" value="products">
            <div class="col-12 col-md-6">
                <input class="form-control" name="q" value="<?= e($search) ?>" placeholder="Search name/SKU">
            </div>
            <div class="col-12 col-md-auto">
                <button class="btn btn-outline-primary" type="submit">Search</button>
                <a class="btn btn-outline-secondary" href="index.php?page=products">Reset</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>SKU</th>
                    <th class="text-end">Cost</th>
                    <th class="text-end">Sell</th>
                    <th class="text-center">Active</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$products): ?>
                    <tr><td colspan="7" class="text-muted">No products found.</td></tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td><?= (int)$p['id'] ?></td>
                            <td><?= e((string)$p['name']) ?></td>
                            <td><?= e((string)($p['sku'] ?? '')) ?></td>
                            <td class="text-end"><?= e(money_fmt(to_float($p['cost_price']), $currency)) ?></td>
                            <td class="text-end"><?= e(money_fmt(to_float($p['sell_price']), $currency)) ?></td>
                            <td class="text-center"><?= (int)$p['active'] === 1 ? 'Yes' : 'No' ?></td>
                            <td class="text-end">
                                <?php if (app_can_write()): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="index.php?page=products&action=edit&id=<?= (int)$p['id'] ?>">Edit</a>
                                <?php else: ?>
                                    <a class="btn btn-sm btn-outline-primary disabled" aria-disabled="true">Edit</a>
                                <?php endif; ?>
                                <form class="d-inline" method="post" action="index.php?page=products&action=delete" onsubmit="return confirm('Delete this product?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit" <?= app_can_write() ? '' : 'disabled' ?>>Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
