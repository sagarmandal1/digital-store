<?php

declare(strict_types=1);

auth_require_login();

if (!app_is_super_admin()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if (!app_db_initialized()) {
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <h1 class="h3">Audit Logs</h1>
    <div class="alert alert-warning">Initialize the database first.</div>
    <?php
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php';
    exit;
}

app_migrate_audit_logs_table();

$pdo = db();

$actorId = (int)($_GET['actor_id'] ?? 0);
$entity = trim((string)($_GET['entity'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

$where = [];
$params = [];
if ($actorId > 0) {
    $where[] = 'al.actor_admin_id = :actor_id';
    $params[':actor_id'] = $actorId;
}
if ($entity !== '') {
    $where[] = 'al.entity = :entity';
    $params[':entity'] = $entity;
}
if ($action !== '') {
    $where[] = 'al.action = :action';
    $params[':action'] = $action;
}
if ($from !== '') {
    $where[] = 'al.created_at >= :from';
    $params[':from'] = strtotime($from . ' 00:00:00') ?: 0;
}
if ($to !== '') {
    $where[] = 'al.created_at <= :to';
    $params[':to'] = strtotime($to . ' 23:59:59') ?: time();
}
if ($q !== '') {
    $where[] = '(al.entity LIKE :q OR al.action LIKE :q OR al.meta_json LIKE :q OR aa.email LIKE :q OR aa.name LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("
    SELECT
        al.*,
        aa.name AS actor_name,
        aa.email AS actor_email,
        sa.name AS scope_name,
        sa.email AS scope_email
    FROM audit_logs al
    LEFT JOIN admins aa ON aa.id = al.actor_admin_id
    LEFT JOIN admins sa ON sa.id = al.scope_admin_id
    $whereSql
    ORDER BY al.id DESC
    LIMIT 500
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$admins = [];
try {
    $a = $pdo->prepare('SELECT id, name, email FROM admins ORDER BY id ASC');
    $a->execute();
    $admins = $a->fetchAll();
} catch (Throwable $e) {
    $admins = [];
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0">Audit Logs</h1>
</div>

<form class="row g-2 mb-3" method="get" action="index.php">
    <input type="hidden" name="page" value="audit_logs">
    <div class="col-12 col-md-3">
        <label class="form-label">Actor</label>
        <select class="form-select" name="actor_id">
            <option value="0">All</option>
            <?php foreach ($admins as $ad): ?>
                <?php $aid = (int)($ad['id'] ?? 0); ?>
                <option value="<?= $aid ?>" <?= $actorId === $aid ? 'selected' : '' ?>>
                    <?= e((string)($ad['name'] ?? '')) ?> (<?= e((string)($ad['email'] ?? '')) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Entity</label>
        <input class="form-control" name="entity" value="<?= e($entity) ?>" placeholder="sale/payment">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Action</label>
        <input class="form-control" name="action" value="<?= e($action) ?>" placeholder="create/update">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">From</label>
        <input class="form-control" type="date" name="from" value="<?= e($from) ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">To</label>
        <input class="form-control" type="date" name="to" value="<?= e($to) ?>">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Search</label>
        <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="email, action, meta...">
    </div>
    <div class="col-12 col-md-auto d-flex align-items-end">
        <button class="btn btn-primary" type="submit">Apply</button>
        <a class="btn btn-outline-secondary ms-2" href="index.php?page=audit_logs">Reset</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase fw-bold border-bottom">
                <tr>
                    <th class="ps-3 py-3">Time</th>
                    <th class="py-3">Actor</th>
                    <th class="py-3">Scope</th>
                    <th class="py-3">Action</th>
                    <th class="py-3">Entity</th>
                    <th class="py-3 text-end">ID</th>
                    <th class="py-3">Meta</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-muted ps-3 py-3">No logs found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $ts = (int)($r['created_at'] ?? 0);
                        $meta = (string)($r['meta_json'] ?? '');
                        if (strlen($meta) > 180) {
                            $meta = substr($meta, 0, 180) . '...';
                        }
                        $actorLabel = (string)($r['actor_name'] ?? '');
                        if ($actorLabel === '') {
                            $actorLabel = (string)($r['actor_email'] ?? ('#' . (int)($r['actor_admin_id'] ?? 0)));
                        }
                        $scopeLabel = (string)($r['scope_name'] ?? '');
                        if ($scopeLabel === '') {
                            $scopeLabel = (string)($r['scope_email'] ?? ('#' . (int)($r['scope_admin_id'] ?? 0)));
                        }
                        ?>
                        <tr>
                            <td class="ps-3"><?= e($ts > 0 ? date('Y-m-d H:i:s', $ts) : '-') ?></td>
                            <td><?= e($actorLabel) ?></td>
                            <td><?= e($scopeLabel) ?></td>
                            <td><?= e((string)($r['action'] ?? '')) ?></td>
                            <td><?= e((string)($r['entity'] ?? '')) ?></td>
                            <td class="text-end"><?= (int)($r['entity_id'] ?? 0) ?></td>
                            <td><code><?= e($meta) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>

