<?php

declare(strict_types=1);

auth_require_login();
csrf_verify();
app_ensure_service_catalog_tables();

$action = (string)($_GET['action'] ?? 'index');
$id = (int)($_GET['id'] ?? 0);
$colors = ['primary', 'secondary', 'info', 'warning', 'success', 'danger', 'dark'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ownerId = app_require_user_scope_for_write();
    $postAction = (string)($_POST['action'] ?? '');

    if ($postAction === 'save_service') {
        $name = trim((string)($_POST['name'] ?? ''));
        $price = to_float($_POST['default_price'] ?? 0);
        $checklist = trim((string)($_POST['document_checklist'] ?? ''));
        $days = max(0, (int)($_POST['estimated_days'] ?? 0));
        $active = isset($_POST['active']) ? 1 : 0;
        $serviceId = (int)($_POST['id'] ?? 0);
        if ($name === '' || $price < 0) {
            flash_set('danger', 'Service name and a valid price are required.');
        } elseif ($serviceId > 0) {
            $stmt = db()->prepare('UPDATE service_types SET name=:name, default_price=:price, document_checklist=:checklist, estimated_days=:days, active=:active WHERE id=:id AND admin_id=:aid');
            $stmt->execute([':name' => $name, ':price' => $price, ':checklist' => $checklist, ':days' => $days, ':active' => $active, ':id' => $serviceId, ':aid' => $ownerId]);
            app_audit_log('update', 'service_type', $serviceId, ['name' => $name]);
            flash_set('success', 'Service updated.');
        } else {
            $stmt = db()->prepare('INSERT INTO service_types (admin_id, name, default_price, document_checklist, estimated_days, active) VALUES (:aid, :name, :price, :checklist, :days, :active)');
            $stmt->execute([':aid' => $ownerId, ':name' => $name, ':price' => $price, ':checklist' => $checklist, ':days' => $days, ':active' => $active]);
            $newId = (int)db()->lastInsertId();
            app_audit_log('create', 'service_type', $newId, ['name' => $name]);
            flash_set('success', 'Service created.');
        }
    } elseif ($postAction === 'save_status') {
        $name = trim((string)($_POST['name'] ?? ''));
        $color = (string)($_POST['color'] ?? 'secondary');
        $sortOrder = max(0, (int)($_POST['sort_order'] ?? 0));
        $final = isset($_POST['is_final']) ? 1 : 0;
        $statusId = (int)($_POST['id'] ?? 0);
        if ($name === '') {
            flash_set('danger', 'Status name is required.');
        } elseif ($statusId > 0) {
            $stmt = db()->prepare('UPDATE workflow_statuses SET name=:name, color=:color, sort_order=:sort_order, is_final=:final, active=:active WHERE id=:id AND admin_id=:aid');
            $stmt->execute([':name' => $name, ':color' => in_array($color, $colors, true) ? $color : 'secondary', ':sort_order' => $sortOrder, ':final' => $final, ':active' => isset($_POST['active']) ? 1 : 0, ':id' => $statusId, ':aid' => $ownerId]);
            app_audit_log('update', 'workflow_status', $statusId, ['name' => $name]);
            flash_set('success', 'Workflow status updated.');
        } else {
            try {
                $stmt = db()->prepare('INSERT INTO workflow_statuses (admin_id, name, color, sort_order, is_final, active) VALUES (:aid, :name, :color, :sort_order, :final, 1)');
                $stmt->execute([':aid' => $ownerId, ':name' => $name, ':color' => in_array($color, $colors, true) ? $color : 'secondary', ':sort_order' => $sortOrder, ':final' => $final]);
                $newId = (int)db()->lastInsertId();
                app_audit_log('create', 'workflow_status', $newId, ['name' => $name]);
                flash_set('success', 'Workflow status created.');
            } catch (PDOException $e) {
                flash_set('danger', 'A status with this name already exists.');
            }
        }
    } elseif ($postAction === 'delete_service' || $postAction === 'delete_status') {
        $entity = $postAction === 'delete_service' ? 'service_types' : 'workflow_statuses';
        $entityName = $postAction === 'delete_service' ? 'service_type' : 'workflow_status';
        $deleteId = (int)($_POST['id'] ?? 0);
        if ($deleteId > 0) {
            $stmt = db()->prepare("DELETE FROM {$entity} WHERE id=:id AND admin_id=:aid");
            $stmt->execute([':id' => $deleteId, ':aid' => $ownerId]);
            app_audit_log('delete', $entityName, $deleteId);
            flash_set('success', 'Deleted.');
        }
    }
    header('Location: index.php?page=services');
    exit;
}

$scopeId = app_scope_admin_id();
$service = null;
$status = null;
if ($action === 'edit_service' && $id > 0) {
    $stmt = db()->prepare('SELECT * FROM service_types WHERE id=:id AND admin_id=:aid');
    $stmt->execute([':id' => $id, ':aid' => $scopeId]);
    $service = $stmt->fetch() ?: null;
}
if ($action === 'edit_status' && $id > 0) {
    $stmt = db()->prepare('SELECT * FROM workflow_statuses WHERE id=:id AND admin_id=:aid');
    $stmt->execute([':id' => $id, ':aid' => $scopeId]);
    $status = $stmt->fetch() ?: null;
}

$services = [];
$statuses = [];
if (app_db_initialized()) {
    $stmt = db()->prepare('SELECT * FROM service_types WHERE admin_id=:aid OR :aid=0 ORDER BY active DESC, name COLLATE NOCASE');
    $stmt->execute([':aid' => $scopeId]);
    $services = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT * FROM workflow_statuses WHERE admin_id=:aid OR :aid=0 ORDER BY sort_order, name COLLATE NOCASE');
    $stmt->execute([':aid' => $scopeId]);
    $statuses = $stmt->fetchAll();
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
$currency = app_setting('currency_symbol', '৳');
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h3 mb-1">Services & Workflow</h1>
        <p class="text-muted mb-0">Create the services, document checklist, price and work statuses you use every day.</p>
    </div>
</div>

<?php if (!app_can_write()): ?>
    <div class="alert alert-warning">Select a user from the top-right menu to manage that user's services.</div>
<?php else: ?>
    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <div class="card shadow-sm h-100"><div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3"><h2 class="h5 mb-0">Service catalogue</h2><a class="btn btn-sm btn-primary" href="index.php?page=services&action=create_service">Add service</a></div>
                <?php if ($action === 'create_service' || $service): ?>
                    <form method="post" class="border rounded p-3 mb-3">
                        <?= csrf_field() ?><input type="hidden" name="action" value="save_service"><input type="hidden" name="id" value="<?= (int)($service['id'] ?? 0) ?>">
                        <div class="row g-3"><div class="col-md-7"><label class="form-label">Service name</label><input class="form-control" name="name" required value="<?= e((string)($service['name'] ?? '')) ?>" placeholder="e.g. Birth registration correction"></div><div class="col-md-5"><label class="form-label">Default price (<?= e($currency) ?>)</label><input class="form-control" type="number" min="0" step="0.01" name="default_price" required value="<?= e((string)($service['default_price'] ?? '0')) ?>"></div><div class="col-md-4"><label class="form-label">Estimated days</label><input class="form-control" type="number" min="0" name="estimated_days" value="<?= e((string)($service['estimated_days'] ?? '0')) ?>"></div><div class="col-md-8"><label class="form-label">Required documents / checklist</label><textarea class="form-control" rows="2" name="document_checklist" placeholder="One item per line"><?= e((string)($service['document_checklist'] ?? '')) ?></textarea></div><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="active" id="serviceActive" <?= (int)($service['active'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="serviceActive">Active service</label></div></div></div>
                        <div class="mt-3 d-flex gap-2"><button class="btn btn-success">Save service</button><a class="btn btn-outline-secondary" href="index.php?page=services">Cancel</a></div>
                    </form>
                <?php endif; ?>
                <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Service</th><th>Checklist</th><th>Price</th><th>Time</th><th></th></tr></thead><tbody><?php if (!$services): ?><tr><td colspan="5" class="text-muted text-center py-4">No services yet. Add your first service.</td></tr><?php endif; ?><?php foreach ($services as $row): ?><tr><td><div class="fw-semibold"><?= e((string)$row['name']) ?></div><span class="badge text-bg-<?= (int)$row['active'] === 1 ? 'success' : 'secondary' ?>"><?= (int)$row['active'] === 1 ? 'Active' : 'Inactive' ?></span></td><td class="small text-muted" style="white-space:pre-line"><?= e((string)$row['document_checklist']) ?></td><td><?= e($currency) ?> <?= number_format(to_float($row['default_price']), 2) ?></td><td><?= (int)$row['estimated_days'] ?> day(s)</td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="index.php?page=services&action=edit_service&id=<?= (int)$row['id'] ?>">Edit</a><form method="post" class="d-inline" onsubmit="return confirm('Delete this service?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_service"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div>
            </div></div>
        </div>
        <div class="col-12 col-xl-5"><div class="card shadow-sm h-100"><div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-3"><h2 class="h5 mb-0">Workflow statuses</h2><a class="btn btn-sm btn-primary" href="index.php?page=services&action=create_status">Add status</a></div>
            <?php if ($action === 'create_status' || $status): ?><form method="post" class="border rounded p-3 mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="save_status"><input type="hidden" name="id" value="<?= (int)($status['id'] ?? 0) ?>"><div class="row g-2"><div class="col-md-6"><label class="form-label">Status</label><input class="form-control" name="name" required value="<?= e((string)($status['name'] ?? '')) ?>" placeholder="e.g. Processing"></div><div class="col-md-3"><label class="form-label">Order</label><input class="form-control" type="number" min="0" name="sort_order" value="<?= e((string)($status['sort_order'] ?? '0')) ?>"></div><div class="col-md-3"><label class="form-label">Colour</label><select class="form-select" name="color"><?php foreach ($colors as $color): ?><option value="<?= e($color) ?>" <?= ($status['color'] ?? 'secondary') === $color ? 'selected' : '' ?>><?= e(ucfirst($color)) ?></option><?php endforeach; ?></select></div><div class="col-12 d-flex gap-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_final" id="statusFinal" <?= (int)($status['is_final'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="statusFinal">Final status</label></div><div class="form-check"><input class="form-check-input" type="checkbox" name="active" id="statusActive" <?= (int)($status['active'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="statusActive">Active</label></div></div></div><div class="mt-3 d-flex gap-2"><button class="btn btn-success">Save status</button><a class="btn btn-outline-secondary" href="index.php?page=services">Cancel</a></div></form><?php endif; ?>
            <div class="list-group list-group-flush"><?php if (!$statuses): ?><div class="text-muted text-center py-4">Add statuses such as New, Documents Pending, Processing, Ready and Delivered.</div><?php endif; ?><?php foreach ($statuses as $row): ?><div class="list-group-item d-flex align-items-center gap-2 px-0"><span class="badge text-bg-<?= e((string)$row['color']) ?>"><?= e((string)$row['name']) ?></span><span class="small text-muted">Order <?= (int)$row['sort_order'] ?><?= (int)$row['is_final'] === 1 ? ' · Final' : '' ?><?= (int)$row['active'] === 0 ? ' · Inactive' : '' ?></span><div class="ms-auto"><a class="btn btn-sm btn-outline-primary" href="index.php?page=services&action=edit_status&id=<?= (int)$row['id'] ?>">Edit</a><form method="post" class="d-inline" onsubmit="return confirm('Delete this status?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_status"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></div></div><?php endforeach; ?></div>
        </div></div></div>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
