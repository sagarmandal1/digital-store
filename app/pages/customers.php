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
        flash_set('danger', 'Invalid customer.');
        header('Location: index.php?page=customers');
        exit;
    }

    try {
        $stmt = db()->prepare('DELETE FROM customers WHERE id = :id AND admin_id = :aid');
        $stmt->execute([':id' => $id, ':aid' => $adminId]);

        if ($stmt->rowCount() > 0) {
            app_audit_log('delete', 'customer', $id);
            flash_set('success', 'Customer deleted.');
        } else {
            flash_set('danger', 'Customer not found or access denied.');
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash_set('danger', 'Cannot delete customer. This customer has sales records. Delete sales records first.');
        } else {
            flash_set('danger', 'Error deleting customer: ' . $e->getMessage());
        }
    }

    header('Location: index.php?page=customers');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'create' || $action === 'edit')) {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $category = trim((string)($_POST['category'] ?? 'Regular'));
    $ownerId = app_require_user_scope_for_write();

    if ($name === '') {
        flash_set('danger', 'Name is required.');
        header('Location: index.php?page=customers&action=' . $action . ($action === 'edit' ? '&id=' . $id : ''));
        exit;
    }

    if ($action === 'create') {
        $stmt = db()->prepare('INSERT INTO customers (name, email, phone, address, category, admin_id) VALUES (:name, :email, :phone, :address, :cat, :aid)');
        $stmt->execute([
            ':name' => $name,
            ':email' => $email === '' ? null : $email,
            ':phone' => $phone === '' ? null : $phone,
            ':address' => $address === '' ? null : $address,
            ':cat' => $category,
            ':aid' => $ownerId,
        ]);
        $newId = (int)db()->lastInsertId();
        app_audit_log('create', 'customer', $newId, ['name' => $name]);
        flash_set('success', 'Customer created.');
        header('Location: index.php?page=customers');
        exit;
    }

    if ($id <= 0) {
        flash_set('danger', 'Invalid customer.');
        header('Location: index.php?page=customers');
        exit;
    }

    $stmt = db()->prepare('UPDATE customers SET name=:name, email=:email, phone=:phone, address=:address, category=:cat WHERE id=:id AND admin_id=:aid');
    $stmt->execute([
        ':id' => $id,
        ':name' => $name,
        ':email' => $email === '' ? null : $email,
        ':phone' => $phone === '' ? null : $phone,
        ':address' => $address === '' ? null : $address,
        ':cat' => $category,
        ':aid' => $ownerId,
    ]);
    if ($stmt->rowCount() > 0) {
        app_audit_log('update', 'customer', $id, ['name' => $name]);
    }
    flash_set('success', 'Customer updated.');
    header('Location: index.php?page=customers');
    exit;
}

$customer = null;
if ($action === 'edit' && $id > 0) {
    $scopeId = app_scope_admin_id();
    $stmt = db()->prepare('SELECT * FROM customers WHERE id = :id AND admin_id = :aid');
    $stmt->execute([':id' => $id, ':aid' => $scopeId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        flash_set('danger', 'Customer not found.');
        header('Location: index.php?page=customers');
        exit;
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$categoryFilter = trim((string)($_GET['cat'] ?? ''));
$categoryFilter = in_array($categoryFilter, ['Regular', 'VIP'], true) ? $categoryFilter : '';
$customers = [];
if (app_db_initialized()) {
    $adminId = app_scope_admin_id();
    $sql = "
        SELECT 
            c.*,
            COALESCE((SELECT SUM(s.total_sell) FROM sales s WHERE s.customer_id = c.id AND (s.admin_id = :aid OR :aid = 0)), 0) as total_sell,
            COALESCE((SELECT SUM(s.paid_amount) FROM sales s WHERE s.customer_id = c.id AND (s.admin_id = :aid OR :aid = 0)), 0) as total_paid
        FROM customers c
        WHERE (c.admin_id = :aid OR :aid = 0)
    ";

    $params = [':aid' => $adminId];
    if ($categoryFilter !== '') {
        $sql .= " AND c.category = :cat";
        $params[':cat'] = $categoryFilter;
    }

    if ($search !== '') {
        $stmt = db()->prepare($sql . " AND (c.name LIKE :q OR c.email LIKE :q OR c.phone LIKE :q) ORDER BY c.id DESC LIMIT 200");
        $params[':q'] = '%' . $search . '%';
        $stmt->execute($params);
        $customers = $stmt->fetchAll();
    } else {
        $stmt = db()->prepare($sql . " ORDER BY c.id DESC LIMIT 200");
        $stmt->execute($params);
        $customers = $stmt->fetchAll();
    }
}

$summary = [
    'count' => 0,
    'total_sell' => 0.0,
    'total_paid' => 0.0,
    'total_due' => 0.0,
];
foreach ($customers as $c) {
    $sell = to_float($c['total_sell'] ?? 0);
    $paid = to_float($c['total_paid'] ?? 0);
    $due = max(0.0, $sell - $paid);
    $summary['count']++;
    $summary['total_sell'] += $sell;
    $summary['total_paid'] += $paid;
    $summary['total_due'] += $due;
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';

?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h1 class="h3 mb-0">Customers</h1>
    <?php if (app_can_write()): ?>
        <a class="btn btn-primary" href="index.php?page=customers&action=create"><i class="bi bi-person-plus me-1"></i>Add Customer</a>
    <?php else: ?>
        <a class="btn btn-primary disabled" aria-disabled="true"><i class="bi bi-person-plus me-1"></i>Add Customer</a>
    <?php endif; ?>
</div>

<?php if ($action === 'create' || $action === 'edit'): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h5 mb-3"><?= $action === 'create' ? 'Create Customer' : 'Edit Customer' ?></h2>
            <form method="post" action="index.php?page=customers&action=<?= e($action) ?><?= $action === 'edit' ? '&id=' . (int)$id : '' ?>">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label">Name</label>
                        <input class="form-control" name="name" value="<?= e((string)($customer['name'] ?? '')) ?>" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Email</label>
                        <input class="form-control" name="email" type="email" value="<?= e((string)($customer['email'] ?? '')) ?>">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Phone</label>
                        <input class="form-control" name="phone" value="<?= e((string)($customer['phone'] ?? '')) ?>">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category">
                            <option value="Regular" <?= ($customer['category'] ?? 'Regular') === 'Regular' ? 'selected' : '' ?>>Regular</option>
                            <option value="VIP" <?= ($customer['category'] ?? '') === 'VIP' ? 'selected' : '' ?>>VIP</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" rows="2"><?= e((string)($customer['address'] ?? '')) ?></textarea>
                    </div>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-success" type="submit">Save</button>
                    <a class="btn btn-outline-secondary" href="index.php?page=customers">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form class="row g-2 mb-3" method="get" action="index.php">
            <input type="hidden" name="page" value="customers">
            <div class="col-12 col-lg-6">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input class="form-control" name="q" value="<?= e($search) ?>" placeholder="Search name/email/phone">
                </div>
            </div>
            <div class="col-12 col-md-5 col-lg-3">
                <select class="form-select" name="cat">
                    <option value="" <?= $categoryFilter === '' ? 'selected' : '' ?>>All categories</option>
                    <option value="Regular" <?= $categoryFilter === 'Regular' ? 'selected' : '' ?>>Regular</option>
                    <option value="VIP" <?= $categoryFilter === 'VIP' ? 'selected' : '' ?>>VIP</option>
                </select>
            </div>
            <div class="col-12 col-md-auto d-flex gap-2">
                <button class="btn btn-outline-primary" type="submit">Search</button>
                <a class="btn btn-outline-secondary" href="index.php?page=customers">Reset</a>
            </div>
        </form>

        <div class="row g-2 mb-3">
            <div class="col-6 col-lg-3">
                <div class="p-3 rounded border bg-body-tertiary">
                    <div class="text-muted small">Customers</div>
                    <div class="h5 mb-0 fw-bold"><?= (int)$summary['count'] ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="p-3 rounded border bg-body-tertiary">
                    <div class="text-muted small">Total Sell</div>
                    <div class="h6 mb-0 fw-semibold"><?= money_fmt($summary['total_sell'], $currency) ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="p-3 rounded border bg-body-tertiary">
                    <div class="text-muted small">Paid</div>
                    <div class="h6 mb-0 fw-semibold text-success"><?= money_fmt($summary['total_paid'], $currency) ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="p-3 rounded border bg-body-tertiary">
                    <div class="text-muted small">Due</div>
                    <div class="h6 mb-0 fw-semibold <?= $summary['total_due'] > 0.01 ? 'text-danger' : 'text-success' ?>"><?= money_fmt($summary['total_due'], $currency) ?></div>
                </div>
            </div>
        </div>

        <div class="d-md-none vstack gap-2">
            <?php if (!$customers): ?>
                <div class="text-muted text-center py-4">No customers found.</div>
            <?php else: ?>
                <?php foreach ($customers as $c): ?>
                    <?php
                    $cid = (int)$c['id'];
                    $totalSell = to_float($c['total_sell']);
                    $totalPaid = to_float($c['total_paid']);
                    $dueBalance = max(0.0, $totalSell - $totalPaid);
                    $phone = trim((string)($c['phone'] ?? ''));
                    $email = trim((string)($c['email'] ?? ''));
                    ?>
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between gap-2">
                                <div class="min-w-0">
                                    <div class="fw-bold text-truncate">
                                        <?= e((string)($c['name'] ?? '')) ?>
                                        <?php if (($c['category'] ?? '') === 'VIP'): ?>
                                            <span class="badge bg-warning text-dark small fw-normal ms-1" style="font-size: 0.65rem;">VIP</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ((string)($c['address'] ?? '') !== ''): ?>
                                        <div class="text-muted small text-truncate"><?= e((string)($c['address'] ?? '')) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <div class="small text-muted">Due</div>
                                    <div class="fw-bold <?= $dueBalance > 0.01 ? 'text-danger' : 'text-success' ?>"><?= money_fmt($dueBalance, $currency) ?></div>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mt-2 small">
                                <?php if ($phone !== ''): ?>
                                    <a class="text-decoration-none" href="tel:<?= e($phone) ?>"><i class="bi bi-telephone me-1"></i><?= e($phone) ?></a>
                                <?php else: ?>
                                    <span class="text-muted"><i class="bi bi-telephone me-1"></i>-</span>
                                <?php endif; ?>
                                <?php if ($email !== ''): ?>
                                    <a class="text-decoration-none" href="mailto:<?= e($email) ?>"><i class="bi bi-envelope me-1"></i><?= e($email) ?></a>
                                <?php else: ?>
                                    <span class="text-muted"><i class="bi bi-envelope me-1"></i>-</span>
                                <?php endif; ?>
                            </div>
                            <div class="row g-2 mt-3">
                                <div class="col-6">
                                    <a class="btn btn-outline-secondary w-100 btn-sm" href="index.php?page=customer_ledger&id=<?= $cid ?>"><i class="bi bi-journal-text me-1"></i>Ledger</a>
                                </div>
                                <div class="col-6">
                                    <a class="btn btn-outline-secondary w-100 btn-sm" href="index.php?page=dues&customer_id=<?= $cid ?>"><i class="bi bi-cash-stack me-1"></i>Dues</a>
                                </div>
                                <?php if (app_can_write()): ?>
                                    <div class="col-6">
                                        <a class="btn btn-outline-primary w-100 btn-sm" href="index.php?page=customers&action=edit&id=<?= $cid ?>"><i class="bi bi-pencil me-1"></i>Edit</a>
                                    </div>
                                    <div class="col-6">
                                        <form method="post" action="index.php?page=customers&action=delete" onsubmit="return confirm('Delete this customer? This will NOT delete their sales records.')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $cid ?>">
                                            <button class="btn btn-outline-danger w-100 btn-sm" type="submit"><i class="bi bi-trash me-1"></i>Delete</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="col-12">
                                        <button class="btn btn-outline-primary w-100 btn-sm" type="button" disabled><i class="bi bi-pencil me-1"></i>Edit</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex justify-content-between mt-3 small">
                                <div class="text-muted">Total: <span class="fw-semibold"><?= money_fmt($totalSell, $currency) ?></span></div>
                                <div class="text-muted">Paid: <span class="fw-semibold text-success"><?= money_fmt($totalPaid, $currency) ?></span></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="table-responsive d-none d-md-block">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th class="text-end">Total Sell</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Due Balance</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$customers): ?>
                        <tr>
                            <td colspan="7" class="text-muted text-center py-4">No customers found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $c): ?>
                            <?php
                            $totalSell = to_float($c['total_sell']);
                            $totalPaid = to_float($c['total_paid']);
                            $dueBalance = max(0.0, $totalSell - $totalPaid);
                            ?>
                            <tr>
                                <td><?= (int)$c['id'] ?></td>
                                <td>
                                    <div class="fw-bold"><?= e((string)$c['name']) ?>
                                        <?php if (($c['category'] ?? '') === 'VIP'): ?>
                                            <span class="badge bg-warning text-dark small fw-normal ms-1" style="font-size: 0.65rem;">VIP</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-muted small"><?= e((string)($c['address'] ?? '')) ?></div>
                                </td>
                                <td>
                                    <div class="small"><i class="bi bi-telephone me-1"></i><?= e((string)($c['phone'] ?? '-')) ?></div>
                                    <div class="small"><i class="bi bi-envelope me-1"></i><?= e((string)($c['email'] ?? '-')) ?></div>
                                </td>
                                <td class="text-end fw-semibold"><?= money_fmt($totalSell, $currency) ?></td>
                                <td class="text-end text-success"><?= money_fmt($totalPaid, $currency) ?></td>
                                <td class="text-end fw-bold <?= $dueBalance > 0.01 ? 'text-danger' : 'text-success' ?>">
                                    <?= money_fmt($dueBalance, $currency) ?>
                                </td>
                                <td class="text-end">
                                    <div class="dropdown d-inline">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                            Action
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <?php if (app_can_write()): ?>
                                                <li><a class="dropdown-item" href="index.php?page=customers&action=edit&id=<?= (int)$c['id'] ?>"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                                            <?php else: ?>
                                                <li><a class="dropdown-item disabled" aria-disabled="true"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                                            <?php endif; ?>
                                            <li><a class="dropdown-item" href="index.php?page=customer_ledger&id=<?= (int)$c['id'] ?>"><i class="bi bi-journal-text me-2"></i>Statement / Ledger</a></li>
                                            <li><a class="dropdown-item" href="index.php?page=dues&customer_id=<?= (int)$c['id'] ?>"><i class="bi bi-cash-stack me-2"></i>View Dues</a></li>
                                            <li>
                                                <hr class="dropdown-divider">
                                            </li>
                                            <li>
                                                <form method="post" action="index.php?page=customers&action=delete" onsubmit="return confirm('Delete this customer? This will NOT delete their sales records.')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                                    <button class="dropdown-item text-danger" type="submit" <?= app_can_write() ? '' : 'disabled' ?>><i class="bi bi-trash me-2"></i>Delete</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (app_can_write()): ?>
    <div class="d-md-none" style="height: 72px;"></div>
    <div class="d-md-none fixed-bottom p-3" style="background: rgba(var(--bs-body-bg-rgb), 0.92); backdrop-filter: blur(6px); border-top: 1px solid rgba(0,0,0,.08);">
        <a class="btn btn-primary w-100" href="index.php?page=customers&action=create"><i class="bi bi-person-plus me-1"></i>Add Customer</a>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>