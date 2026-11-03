<?php

declare(strict_types=1);

auth_require_login();
csrf_verify();
app_ensure_sellers_table();
app_ensure_customer_tasks_table();

$action = (string)($_GET['action'] ?? 'index');
$id = (int)($_GET['id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ownerId = app_require_user_scope_for_write();
    $postAction = (string)($_POST['action'] ?? '');

    if ($postAction === 'save_seller') {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $serviceType = trim((string)($_POST['service_type'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $sellerId = (int)($_POST['id'] ?? 0);

        if ($name === '' || $phone === '') {
            flash_set('danger', 'সেলার নাম এবং হোয়াটসঅ্যাপ নম্বর আবশ্যক।');
        } elseif ($sellerId > 0) {
            $stmt = db()->prepare('
                UPDATE sellers 
                SET name = :name, phone = :phone, service_type = :st, notes = :notes 
                WHERE id = :id
            ');
            $stmt->execute([
                ':name' => $name,
                ':phone' => $phone,
                ':st' => $serviceType,
                ':notes' => $notes,
                ':id' => $sellerId,
            ]);
            app_audit_log('update', 'seller', $sellerId, ['name' => $name, 'phone' => $phone]);
            flash_set('success', "সেলার '{$name}' আপডেট করা হয়েছে।");
        } else {
            $stmt = db()->prepare('
                INSERT INTO sellers (admin_id, name, phone, service_type, notes) 
                VALUES (:aid, :name, :phone, :st, :notes)
            ');
            $stmt->execute([
                ':aid' => $ownerId,
                ':name' => $name,
                ':phone' => $phone,
                ':st' => $serviceType,
                ':notes' => $notes,
            ]);
            $newId = (int)db()->lastInsertId();
            app_audit_log('create', 'seller', $newId, ['name' => $name, 'phone' => $phone]);
            flash_set('success', "নতুন সেলার '{$name}' সফলভাবে যুক্ত হয়েছে।");
        }
    } elseif ($postAction === 'delete_seller') {
        $deleteId = (int)($_POST['id'] ?? 0);
        if ($deleteId > 0) {
            $stmt = db()->prepare('SELECT name FROM sellers WHERE id = :id');
            $stmt->execute([':id' => $deleteId]);
            $sName = (string)($stmt->fetchColumn() ?: '');

            $delStmt = db()->prepare('DELETE FROM sellers WHERE id = :id');
            $delStmt->execute([':id' => $deleteId]);
            app_audit_log('delete', 'seller', $deleteId, ['name' => $sName]);
            flash_set('success', "সেলার '{$sName}' মুছে ফেলা হয়েছে।");
        }
    }

    header('Location: index.php?page=sellers' . ($q !== '' ? '&q=' . urlencode($q) : ''));
    exit;
}

$editSeller = null;
if ($action === 'edit' && $id > 0) {
    $stmt = db()->prepare('SELECT * FROM sellers WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $editSeller = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Fetch sellers with search
$params = [];
$sql = 'SELECT s.*, 
        (SELECT COUNT(*) FROM customer_tasks ct WHERE ct.seller_id = s.id OR ct.seller_name = s.name) as task_count,
        (SELECT COUNT(*) FROM customer_tasks ct WHERE (ct.seller_id = s.id OR ct.seller_name = s.name) AND ct.status = "pending") as pending_count
        FROM sellers s';

if ($q !== '') {
    $sql .= ' WHERE s.name LIKE :q OR s.phone LIKE :q OR s.service_type LIKE :q OR s.notes LIKE :q';
    $params[':q'] = '%' . $q . '%';
}
$sql .= ' ORDER BY s.name ASC, s.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$sellers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalSellers = count($sellers);
$totalPendingTasks = array_sum(array_column($sellers, 'pending_count'));

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1"><i class="bi bi-truck me-2 text-primary"></i>Sellers / Vendors (সেলার ও ভেন্ডর)</h1>
        <p class="text-muted mb-0">জন্মনিবন্ধন, এনআইডি, বা অন্যান্য কাজের জন্য যুক্ত থাকা সেলারদের তালিকা এবং সরাসরি হোয়াটসঅ্যাপ যোগাযোগ।</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($action !== 'create' && !$editSeller): ?>
            <a class="btn btn-primary" href="index.php?page=sellers&action=create<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
                <i class="bi bi-plus-circle me-1"></i>নতুন সেলার যোগ করুন
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Add / Edit Form Card -->
<?php if ($action === 'create' || $editSeller): ?>
    <div class="card shadow-sm border-primary mb-4">
        <div class="card-header bg-primary bg-opacity-10 py-3">
            <h5 class="card-title mb-0 text-primary">
                <i class="bi bi-<?= $editSeller ? 'pencil-square' : 'person-plus' ?> me-2"></i>
                <?= $editSeller ? 'সেলার তথ্য সম্পাদনা (Edit Seller)' : 'নতুন সেলার / ভেন্ডর যোগ করুন (Add Seller)' ?>
            </h5>
        </div>
        <div class="card-body">
            <form method="post" action="index.php?page=sellers<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_seller">
                <input type="hidden" name="id" value="<?= (int)($editSeller['id'] ?? 0) ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">সেলার নাম <span class="text-danger">*</span></label>
                        <input class="form-control" name="name" required 
                               placeholder="যেমন: MEHEDI HASAN, সবুজ ভাই" 
                               value="<?= e((string)($editSeller['name'] ?? '')) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">হোয়াটসঅ্যাপ নম্বর <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-whatsapp text-success"></i></span>
                            <input class="form-control" name="phone" required 
                                   placeholder="যেমন: 01973388955 বা 8801973388955" 
                                   value="<?= e((string)($editSeller['phone'] ?? '')) ?>">
                        </div>
                        <div class="form-text small">দেশের কোডসহ বা ছাড়া যেকোনো ফরম্যাটে দেওয়া যাবে।</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">সার্ভিস / কাজের ধরন (ঐচ্ছিক)</label>
                        <input class="form-control" name="service_type" 
                               placeholder="যেমন: New Birth, Server Copy, NID Correction" 
                               value="<?= e((string)($editSeller['service_type'] ?? '')) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">রেট বা নোট (ঐচ্ছিক)</label>
                        <input class="form-control" name="notes" 
                               placeholder="যেমন: ঢাকা উত্তর জোন ১০: ১২০০৳, ২ দিন সময়" 
                               value="<?= e((string)($editSeller['notes'] ?? '')) ?>">
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-success px-4">
                        <i class="bi bi-check2-circle me-1"></i><?= $editSeller ? 'আপডেট করুন' : 'সেভ করুন' ?>
                    </button>
                    <a class="btn btn-outline-secondary" href="index.php?page=sellers<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">বাতিল</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- Stats & Search Toolbar -->
<div class="card shadow-sm mb-4">
    <div class="card-body py-3">
        <div class="row g-3 align-items-center justify-content-between">
            <div class="col-md-4">
                <form method="get" class="d-flex gap-2">
                    <input type="hidden" name="page" value="sellers">
                    <div class="input-group">
                        <input class="form-control" name="q" placeholder="নাম, নম্বর বা সার্ভিস খুঁজুন..." value="<?= e($q) ?>">
                        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search"></i></button>
                        <?php if ($q !== ''): ?>
                            <a class="btn btn-outline-secondary" href="index.php?page=sellers" title="ক্লিয়ার করুন"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="col-md-8 text-md-end">
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 me-2 px-3 py-2">
                    <i class="bi bi-people me-1"></i>মোট সেলার: <?= $totalSellers ?>
                </span>
                <?php if ($totalPendingTasks > 0): ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle fs-6 px-3 py-2">
                        <i class="bi bi-clock-history me-1"></i>চলমান কাজ: <?= $totalPendingTasks ?>টি
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Sellers List Table -->
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 25%;">সেলার নাম</th>
                    <th style="width: 20%;">WhatsApp নম্বর</th>
                    <th style="width: 18%;">সার্ভিস / ক্যাটাগরি</th>
                    <th>রেট / নোট</th>
                    <th style="width: 12%;" class="text-center">কাজ ট্র্যাকিং</th>
                    <th style="width: 12%;" class="text-end">অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sellers)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                            <?= $q !== '' ? 'কোনো সেলারের সাথে মিল পাওয়া যায়নি।' : 'এখনো কোনো সেলার যুক্ত করা হয়নি। উপরে "নতুন সেলার যোগ করুন" বাটনে ক্লিক করুন।' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sellers as $s): ?>
                        <?php 
                            $digits = preg_replace('/[^\d]/', '', (string)$s['phone']);
                            if (str_starts_with($digits, '01')) {
                                $waPhone = '88' . $digits;
                            } elseif (str_starts_with($digits, '8801')) {
                                $waPhone = $digits;
                            } else {
                                $waPhone = $digits;
                            }
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center fw-bold" style="width: 38px; height: 38px; font-size: 15px;">
                                        <?= mb_strtoupper(mb_substr((string)$s['name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark"><?= e((string)$s['name']) ?></div>
                                        <small class="text-muted">যোগ: <?= date('d M, Y', strtotime((string)$s['created_at'])) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <a href="https://wa.me/<?= e($waPhone) ?>" target="_blank" class="btn btn-sm btn-outline-success d-inline-flex align-items-center gap-1 text-decoration-none">
                                    <i class="bi bi-whatsapp"></i>
                                    <span><?= e((string)$s['phone']) ?></span>
                                </a>
                            </td>
                            <td>
                                <?php if (!empty($s['service_type'])): ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                        <?= e((string)$s['service_type']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($s['notes'])): ?>
                                    <div class="small text-secondary" style="max-width: 280px;"><?= nl2br(e((string)$s['notes'])) ?></div>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php $pending = (int)($s['pending_count'] ?? 0); $total = (int)($s['task_count'] ?? 0); ?>
                                <?php if ($total > 0): ?>
                                    <span class="badge <?= $pending > 0 ? 'bg-warning text-dark' : 'bg-success' ?>" title="মোট: <?= $total ?>টি, পেন্ডিং: <?= $pending ?>টি">
                                        <?= $pending > 0 ? "{$pending} পেন্ডিং" : "✓ সম্পন্ন ({$total})" ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">কোনো কাজ নেই</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a class="btn btn-outline-primary" href="index.php?page=sellers&action=edit&id=<?= (int)$s['id'] ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" title="সম্পাদনা">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="post" action="index.php?page=sellers<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="d-inline" onsubmit="return confirm('আপনি কি নিশ্চিত যে সেলার <?= e((string)$s['name']) ?>-কে মুছে ফেলতে চান?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_seller">
                                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                        <button class="btn btn-outline-danger" type="submit" title="মুছে ফেলুন">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
