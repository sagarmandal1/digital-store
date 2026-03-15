<?php

declare(strict_types=1);

auth_require_login();
csrf_verify();

if (!app_is_super_admin()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($name === '' || $email === '' || $password === '') {
            flash_set('danger', 'Name, Email and Password are required.');
            header('Location: index.php?page=users');
            exit;
        }

        if (strlen($password) < 6) {
            flash_set('danger', 'Password must be at least 6 characters.');
            header('Location: index.php?page=users');
            exit;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO admins (name, email, password_hash, role) VALUES (:name, :email, :hash, 'user')");
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT OR IGNORE INTO settings (key, value, admin_id)
                SELECT key, value, :aid FROM settings
                WHERE admin_id = 1 AND key IN (
                    'company_name','company_logo','currency_symbol',
                    'whatsapp_base_url','whatsapp_api_key','whatsapp_ssl_verify','whatsapp_ca_bundle_path'
                )")->execute([':aid' => $newId]);
            app_audit_log('create', 'user', $newId, ['email' => $email]);
            flash_set('success', 'User created.');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                flash_set('danger', 'Email already exists.');
            } else {
                flash_set('danger', 'Failed to create user.');
            }
        }

        header('Location: index.php?page=users');
        exit;
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));

        if ($id <= 0 || $name === '' || $email === '') {
            flash_set('danger', 'Invalid user update.');
            header('Location: index.php?page=users');
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('danger', 'Invalid email.');
            header('Location: index.php?page=users');
            exit;
        }

        $stmt = $pdo->prepare('SELECT id FROM admins WHERE email = :email AND id <> :id LIMIT 1');
        $stmt->execute([':email' => $email, ':id' => $id]);
        if ($stmt->fetchColumn()) {
            flash_set('danger', 'Email already exists.');
            header('Location: index.php?page=users');
            exit;
        }

        try {
            $stmt = $pdo->prepare('UPDATE admins SET name = :name, email = :email WHERE id = :id');
            $stmt->execute([':name' => $name, ':email' => $email, ':id' => $id]);
            if ($stmt->rowCount() > 0) {
                app_audit_log('update', 'user', $id, ['email' => $email]);
                flash_set('success', 'User updated.');
            } else {
                flash_set('success', 'No changes.');
            }
        } catch (Throwable $e) {
            flash_set('danger', 'Failed to update user.');
        }

        header('Location: index.php?page=users');
        exit;
    }

    if ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $active = (int)($_POST['active'] ?? 1) === 1 ? 1 : 0;
        if ($id <= 1) {
            flash_set('danger', 'Cannot change this user.');
            header('Location: index.php?page=users');
            exit;
        }

        try {
            auth_ensure_otp_table();
            auth_ensure_password_reset_table();
            $stmt = $pdo->prepare('UPDATE admins SET active = :active WHERE id = :id');
            $stmt->execute([':active' => $active, ':id' => $id]);
            if ($stmt->rowCount() > 0) {
                if ($active === 0) {
                    $pdo->prepare('DELETE FROM admin_login_otps WHERE admin_id = :id')->execute([':id' => $id]);
                    $pdo->prepare('DELETE FROM admin_password_resets WHERE admin_id = :id')->execute([':id' => $id]);
                }
                app_audit_log($active === 1 ? 'enable' : 'disable', 'user', $id);
                flash_set('success', $active === 1 ? 'User enabled.' : 'User disabled.');
            } else {
                flash_set('danger', 'User not found.');
            }
        } catch (Throwable $e) {
            flash_set('danger', 'Failed to update status.');
        }

        header('Location: index.php?page=users');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 1) {
            flash_set('danger', 'Cannot delete this user.');
            header('Location: index.php?page=users');
            exit;
        }

        try {
            auth_ensure_otp_table();
            auth_ensure_password_reset_table();
            $counts = [
                'customers' => 0,
                'products' => 0,
                'sales' => 0,
                'payments' => 0,
                'expenses' => 0,
                'prices' => 0,
                'settings' => 0,
            ];
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE admin_id = :id');
            $stmt->execute([':id' => $id]);
            $counts['customers'] = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE admin_id = :id');
            $stmt->execute([':id' => $id]);
            $counts['products'] = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE admin_id = :id');
            $stmt->execute([':id' => $id]);
            $counts['sales'] = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE admin_id = :id');
            $stmt->execute([':id' => $id]);
            $counts['payments'] = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM expenses WHERE admin_id = :id');
            $stmt->execute([':id' => $id]);
            $counts['expenses'] = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM customer_product_prices WHERE admin_id = :id');
            $stmt->execute([':id' => $id]);
            $counts['prices'] = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE admin_id = :id');
            $stmt->execute([':id' => $id]);
            $counts['settings'] = (int)$stmt->fetchColumn();

            $total = array_sum($counts);
            if ($total > 0) {
                flash_set('danger', 'Cannot delete user. Transfer user data first.');
                header('Location: index.php?page=users');
                exit;
            }

            $pdo->prepare('DELETE FROM admin_login_otps WHERE admin_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM admin_password_resets WHERE admin_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM settings WHERE admin_id = :id')->execute([':id' => $id]);
            $stmt = $pdo->prepare('DELETE FROM admins WHERE id = :id');
            $stmt->execute([':id' => $id]);
            if ($stmt->rowCount() > 0) {
                app_audit_log('delete', 'user', $id);
            }
            flash_set('success', 'User deleted.');
        } catch (Throwable $e) {
            flash_set('danger', 'Failed to delete user.');
        }

        header('Location: index.php?page=users');
        exit;
    }

    if ($action === 'set_password') {
        $id = (int)($_POST['id'] ?? 0);
        $password = (string)($_POST['password'] ?? '');
        if ($id <= 0 || $password === '' || strlen($password) < 6) {
            flash_set('danger', 'Invalid password update.');
            header('Location: index.php?page=users');
            exit;
        }

        try {
            auth_ensure_otp_table();
            auth_ensure_password_reset_table();
            $stmt = $pdo->prepare('UPDATE admins SET password_hash = :hash WHERE id = :id');
            $stmt->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);
            if ($stmt->rowCount() > 0) {
                $pdo->prepare('DELETE FROM admin_login_otps WHERE admin_id = :id')->execute([':id' => $id]);
                $pdo->prepare('DELETE FROM admin_password_resets WHERE admin_id = :id')->execute([':id' => $id]);
                app_audit_log('set_password', 'user', $id);
            }
            flash_set('success', 'Password updated.');
        } catch (Throwable $e) {
            flash_set('danger', 'Failed to update password.');
        }

        header('Location: index.php?page=users');
        exit;
    }

    if ($action === 'transfer_admin_data') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('danger', 'Invalid user.');
            header('Location: index.php?page=users');
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE customers SET admin_id = :to WHERE admin_id = 1');
            $stmt->execute([':to' => $id]);
            $stmt = $pdo->prepare('UPDATE products SET admin_id = :to WHERE admin_id = 1');
            $stmt->execute([':to' => $id]);
            $stmt = $pdo->prepare('UPDATE sales SET admin_id = :to WHERE admin_id = 1');
            $stmt->execute([':to' => $id]);
            $stmt = $pdo->prepare('UPDATE payments SET admin_id = :to WHERE admin_id = 1');
            $stmt->execute([':to' => $id]);
            $stmt = $pdo->prepare('UPDATE expenses SET admin_id = :to WHERE admin_id = 1');
            $stmt->execute([':to' => $id]);
            $stmt = $pdo->prepare('UPDATE customer_product_prices SET admin_id = :to WHERE admin_id = 1');
            $stmt->execute([':to' => $id]);
            $pdo->commit();
            app_audit_log('transfer_admin_data', 'user', $id);
            flash_set('success', 'Admin data moved to this user.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('danger', 'Failed to move admin data.');
        }

        header('Location: index.php?page=users');
        exit;
    }

    if ($action === 'transfer_data') {
        $fromId = (int)($_POST['from_id'] ?? 0);
        $toId = (int)($_POST['to_id'] ?? 0);
        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            flash_set('danger', 'Invalid transfer.');
            header('Location: index.php?page=users');
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM admins WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $fromId]);
        if (!$stmt->fetchColumn()) {
            flash_set('danger', 'Source user not found.');
            header('Location: index.php?page=users');
            exit;
        }
        $stmt->execute([':id' => $toId]);
        if (!$stmt->fetchColumn()) {
            flash_set('danger', 'Target user not found.');
            header('Location: index.php?page=users');
            exit;
        }

        $pdo->beginTransaction();
        try {
            $tables = ['customers', 'products', 'sales', 'payments', 'expenses', 'customer_product_prices'];
            foreach ($tables as $t) {
                $pdo->prepare("UPDATE {$t} SET admin_id = :to WHERE admin_id = :from")->execute([':to' => $toId, ':from' => $fromId]);
            }

            $pdo->prepare("INSERT OR IGNORE INTO settings (key, value, admin_id)
                SELECT key, value, :to FROM settings WHERE admin_id = :from")->execute([':to' => $toId, ':from' => $fromId]);
            $pdo->prepare("DELETE FROM settings WHERE admin_id = :from")->execute([':from' => $fromId]);

            $pdo->commit();
            app_audit_log('transfer_data', 'user', $toId, ['from_id' => $fromId]);
            flash_set('success', 'Data transferred.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('danger', 'Failed to transfer data.');
        }

        header('Location: index.php?page=users');
        exit;
    }
}

$stmt = $pdo->prepare("
    SELECT
        a.id, a.name, a.email, a.created_at, a.role, COALESCE(a.active, 1) AS active,
        (SELECT COUNT(*) FROM customers c WHERE c.admin_id = a.id) AS customers_count,
        (SELECT COUNT(*) FROM products p WHERE p.admin_id = a.id) AS products_count,
        (SELECT COUNT(*) FROM sales s WHERE s.admin_id = a.id) AS sales_count,
        (SELECT COUNT(*) FROM payments pp WHERE pp.admin_id = a.id) AS payments_count,
        (SELECT COUNT(*) FROM expenses e WHERE e.admin_id = a.id) AS expenses_count
    FROM admins a
    WHERE a.role='user'
    ORDER BY a.id ASC
");
$stmt->execute();
$users = $stmt->fetchAll();

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0">Users</h1>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Create User</h2>
                <form method="post" action="index.php?page=users" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input class="form-control" type="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input class="form-control" type="password" name="password" minlength="6" required>
                    </div>
                    <button class="btn btn-primary" type="submit">Create</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">All Users</h2>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Data</th>
                            <th>Created</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$users): ?>
                            <tr><td colspan="7" class="text-muted">No users.</td></tr>
                        <?php else: ?>
                            <?php foreach ($users as $u): ?>
                                <?php $uid = (int)($u['id'] ?? 0); ?>
                                <?php $isActive = (int)($u['active'] ?? 1) === 1; ?>
                                <tr>
                                    <td><?= $uid ?></td>
                                    <td><?= e((string)($u['name'] ?? '')) ?></td>
                                    <td><?= e((string)($u['email'] ?? '')) ?></td>
                                    <td>
                                        <span class="badge <?= $isActive ? 'text-bg-success' : 'text-bg-danger' ?>">
                                            <?= $isActive ? 'Active' : 'Disabled' ?>
                                        </span>
                                    </td>
                                    <td class="small text-muted">
                                        C: <?= (int)($u['customers_count'] ?? 0) ?>,
                                        P: <?= (int)($u['products_count'] ?? 0) ?>,
                                        S: <?= (int)($u['sales_count'] ?? 0) ?>,
                                        Pay: <?= (int)($u['payments_count'] ?? 0) ?>,
                                        E: <?= (int)($u['expenses_count'] ?? 0) ?>
                                    </td>
                                    <td class="text-muted small"><?= e((string)($u['created_at'] ?? '')) ?></td>
                                    <td class="text-end">
                                        <div class="dropdown d-inline">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" type="button">Manage</button>
                                            <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 320px;">
                                                <div class="small text-muted mb-2">Edit User</div>
                                                <form method="post" action="index.php?page=users" class="d-grid gap-2">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="update">
                                                    <input type="hidden" name="id" value="<?= $uid ?>">
                                                    <input class="form-control form-control-sm" name="name" value="<?= e((string)($u['name'] ?? '')) ?>" required>
                                                    <input class="form-control form-control-sm" type="email" name="email" value="<?= e((string)($u['email'] ?? '')) ?>" required>
                                                    <button class="btn btn-sm btn-outline-primary" type="submit">Save Changes</button>
                                                </form>
                                                <hr class="my-2">
                                                <div class="small text-muted mb-2">Account Status</div>
                                                <form method="post" action="index.php?page=users" class="d-grid" onsubmit="return confirm('Change user status?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="toggle_active">
                                                    <input type="hidden" name="id" value="<?= $uid ?>">
                                                    <input type="hidden" name="active" value="<?= $isActive ? 0 : 1 ?>">
                                                    <button class="btn btn-sm <?= $isActive ? 'btn-outline-warning' : 'btn-outline-success' ?>" type="submit">
                                                        <?= $isActive ? 'Disable User' : 'Enable User' ?>
                                                    </button>
                                                </form>
                                                <hr class="my-2">
                                                <div class="small text-muted mb-2">Set New Password</div>
                                                <form method="post" action="index.php?page=users" class="d-flex gap-2">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="set_password">
                                                    <input type="hidden" name="id" value="<?= $uid ?>">
                                                    <input class="form-control form-control-sm" type="password" name="password" minlength="6" placeholder="New password" required>
                                                    <button class="btn btn-sm btn-primary" type="submit">Save</button>
                                                </form>
                                                <hr class="my-2">
                                                <form method="post" action="index.php?page=users" onsubmit="return confirm('Delete this user?')" class="d-grid">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $uid ?>">
                                                    <button class="btn btn-sm btn-outline-danger" type="submit" <?= $uid <= 1 ? 'disabled' : '' ?>>Delete</button>
                                                </form>
                                                <hr class="my-2">
                                                <form method="post" action="index.php?page=users" onsubmit="return confirm('Move all admin (id=1) data to this user?')" class="d-grid">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="transfer_admin_data">
                                                    <input type="hidden" name="id" value="<?= $uid ?>">
                                                    <button class="btn btn-sm btn-outline-warning" type="submit">Move Admin Data</button>
                                                </form>
                                                <div class="small text-muted mt-2">
                                                    <a href="index.php?page=dashboard&scope_admin_id=<?= $uid ?>" class="text-decoration-none">View this user's data</a>
                                                </div>
                                            </div>
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
    </div>
</div>

<div class="card shadow-sm mt-3">
    <div class="card-body">
        <h2 class="h5 mb-3">Transfer Data Between Users</h2>
        <form method="post" action="index.php?page=users" class="row g-2 align-items-end" onsubmit="return confirm('Transfer all data from one user to another?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="transfer_data">
            <div class="col-12 col-md-4">
                <label class="form-label">From</label>
                <select class="form-select" name="from_id" required>
                    <option value="">Select user</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)($u['id'] ?? 0) ?>"><?= e((string)($u['name'] ?? '')) ?> (<?= e((string)($u['email'] ?? '')) ?>)</option>
                    <?php endforeach; ?>
                    <option value="1">Super Admin (id=1)</option>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">To</label>
                <select class="form-select" name="to_id" required>
                    <option value="">Select user</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)($u['id'] ?? 0) ?>"><?= e((string)($u['name'] ?? '')) ?> (<?= e((string)($u['email'] ?? '')) ?>)</option>
                    <?php endforeach; ?>
                    <option value="1">Super Admin (id=1)</option>
                </select>
            </div>
            <div class="col-12 col-md-auto">
                <button class="btn btn-outline-primary" type="submit">Transfer Data</button>
            </div>
        </form>
    </div>
</div>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
