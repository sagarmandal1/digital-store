<?php

declare(strict_types=1);

auth_require_login();

csrf_verify();

if (!app_db_initialized()) {
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <h1 class="h3">Invoice</h1>
    <div class="alert alert-warning">Initialize the database first.</div>
    <?php
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php';
    exit;
}

$pdo = db();
$adminId = app_scope_admin_id();
$saleId = (int)($_GET['id'] ?? 0);

if ($saleId <= 0) {
    flash_set('danger', 'Invalid invoice.');
    header('Location: index.php?page=sales');
    exit;
}

// Ensure sale belongs to admin
$stmt = $pdo->prepare('SELECT 1 FROM sales WHERE id = :id AND (admin_id = :aid OR :aid = 0)');
$stmt->execute([':id' => $saleId, ':aid' => $adminId]);
if (!$stmt->fetchColumn()) {
    flash_set('danger', 'Invoice not found.');
    header('Location: index.php?page=sales');
    exit;
}

$action = (string)($_GET['action'] ?? '');
$writeAdminId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string)($_GET['action'] ?? '');
    if (in_array($postAction, ['add_payment', 'edit_payment', 'delete_payment'], true)) {
        $writeAdminId = app_require_user_scope_for_write();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'send_pdf_whatsapp') {
    header('Content-Type: application/json');
    $pdfFile = $_FILES['pdf_file'] ?? null;
    if (!$pdfFile || $pdfFile['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Invalid PDF upload.']);
        exit;
    }

    $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'invoice_' . $saleId . '_' . time() . '.pdf';
    if (!move_uploaded_file($pdfFile['tmp_name'], $tempFile)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to save uploaded PDF.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT c.phone, c.name AS customer_name, s.invoice_no, s.total_sell, s.paid_amount FROM sales s JOIN customers c ON c.id=s.customer_id WHERE s.id=:id LIMIT 1');
    $stmt->execute([':id' => $saleId]);
    $row = $stmt->fetch();
    $phone = (string)($row['phone'] ?? '');
    $invoiceNo = (string)($row['invoice_no'] ?? 'Invoice');
    $customerName = (string)($row['customer_name'] ?? '');
    $total = to_float($row['total_sell'] ?? 0);
    $paid = to_float($row['paid_amount'] ?? 0);
    $due = max(0.0, $total - $paid);

    if ($phone === '') {
        unlink($tempFile);
        echo json_encode(['ok' => false, 'error' => 'Customer phone is empty.']);
        exit;
    }

    $currency = app_setting('currency_symbol', '');
    $company = app_setting('company_name', (string)app_config('app_name', 'Company'));

    $caption = "Invoice: {$invoiceNo}\nCustomer: {$customerName}\nTotal: " . money_fmt($total, $currency) . "\nPaid: " . money_fmt($paid, $currency) . "\nDue: " . money_fmt($due, $currency) . "\n{$company}";

    $res = whatsapp_send_file($phone, $tempFile, $caption);
    unlink($tempFile);

    if (!($res['ok'] ?? false)) {
        $baseUrl = trim((string)app_config('base_url', ''));
        $invoiceUrl = '';
        if ($baseUrl !== '') {
            $invoiceUrl = rtrim($baseUrl, '/') . '/index.php?page=invoice&id=' . $saleId;
        }
        $text = $caption;
        if ($invoiceUrl !== '') {
            $text .= "\nInvoice Link: {$invoiceUrl}";
        }
        $fallback = whatsapp_send_text($phone, $text);
        app_audit_log('send', 'invoice_whatsapp', $saleId, ['type' => 'pdf', 'ok' => false, 'fallback_ok' => (bool)($fallback['ok'] ?? false)]);
        echo json_encode([
            'ok' => false,
            'error' => (string)($res['error'] ?? 'WhatsApp PDF send failed.'),
            'fallback_ok' => (bool)($fallback['ok'] ?? false),
            'fallback_error' => (string)($fallback['error'] ?? ''),
        ]);
        exit;
    }

    app_audit_log('send', 'invoice_whatsapp', $saleId, ['type' => 'pdf', 'ok' => true]);
    echo json_encode($res);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'send_whatsapp') {
    $stmt = $pdo->prepare('SELECT c.phone, c.name AS customer_name, s.invoice_no, s.sale_date, s.total_sell, s.paid_amount FROM sales s JOIN customers c ON c.id=s.customer_id WHERE s.id=:id AND (s.admin_id=:aid OR :aid = 0) LIMIT 1');
    $stmt->execute([':id' => $saleId, ':aid' => $adminId]);
    $row = $stmt->fetch();

    $phone = (string)($row['phone'] ?? '');
    if (trim($phone) === '') {
        flash_set('danger', 'Customer phone is empty. Add phone number in customer profile.');
        header('Location: index.php?page=invoice&id=' . $saleId);
        exit;
    }

    $company = app_setting('company_name', (string)app_config('app_name', 'Company'));
    $currency = app_setting('currency_symbol', '');
    $invoiceNo = (string)($row['invoice_no'] ?? '');
    $saleDate = (string)($row['sale_date'] ?? '');
    $total = to_float($row['total_sell'] ?? 0);
    $paid = to_float($row['paid_amount'] ?? 0);
    $due = max(0.0, $total - $paid);
    $customerName = (string)($row['customer_name'] ?? '');

    $message = "Invoice: {$invoiceNo}\nCustomer: {$customerName}\nDate: {$saleDate}\nTotal: " . money_fmt($total, $currency) . "\nPaid: " . money_fmt($paid, $currency) . "\nDue: " . money_fmt($due, $currency) . "\n{$company}";

    $res = whatsapp_send_text($phone, $message);
    if (!($res['ok'] ?? false)) {
        $err = (string)($res['error'] ?? '');
        $raw = (string)($res['raw'] ?? '');
        $msg = $err !== '' ? $err : ($raw !== '' ? $raw : 'WhatsApp send failed.');
        flash_set('danger', $msg);
        header('Location: index.php?page=invoice&id=' . $saleId);
        exit;
    }

    app_audit_log('send', 'invoice_whatsapp', $saleId, ['type' => 'text', 'ok' => true]);
    flash_set('success', 'Invoice sent on WhatsApp.');
    header('Location: index.php?page=invoice&id=' . $saleId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_GET['action'] ?? '') === 'add_payment') {
    $amount = to_float($_POST['amount'] ?? 0);
    $date = trim((string)($_POST['payment_date'] ?? ''));
    $method = trim((string)($_POST['method'] ?? 'Cash'));
    $note = trim((string)($_POST['note'] ?? ''));

    if ($date === '') {
        $date = today_iso();
    }

    $saleRow = $pdo->prepare('SELECT total_sell, paid_amount FROM sales WHERE id = :id AND admin_id = :aid');
    $saleRow->execute([':id' => $saleId, ':aid' => $writeAdminId]);
    $saleData = $saleRow->fetch();
    if (!$saleData) {
        flash_set('danger', 'Invoice not found.');
        header('Location: index.php?page=sales');
        exit;
    }
    $totalSell = to_float($saleData['total_sell'] ?? 0);
    $paid = to_float($saleData['paid_amount'] ?? 0);

    $due = max(0.0, $totalSell - $paid);
    $amount = max(0.0, min($amount, $due));

    if ($amount <= 0) {
        flash_set('danger', 'Payment amount must be greater than 0 and not exceed due.');
        header('Location: index.php?page=invoice&id=' . $saleId);
        exit;
    }

    $stmt = $pdo->prepare('
        INSERT INTO payments (sale_id, payment_date, amount, method, note, admin_id)
        VALUES (:sale_id, :payment_date, :amount, :method, :note, :aid)
    ');
    $stmt->execute([
        ':sale_id' => $saleId,
        ':payment_date' => $date,
        ':amount' => $amount,
        ':method' => $method === '' ? null : $method,
        ':note' => $note === '' ? null : $note,
        ':aid' => $writeAdminId,
    ]);
    $paymentId = (int)$pdo->lastInsertId();

    // Update paid_amount in sales table
    $stmt = $pdo->prepare('UPDATE sales SET paid_amount = paid_amount + :amount WHERE id = :id AND admin_id = :aid');
    $stmt->execute([':amount' => $amount, ':id' => $saleId, ':aid' => $writeAdminId]);

    flash_set('success', 'Payment added.');
    app_audit_log('create', 'payment', $paymentId, ['sale_id' => $saleId, 'amount' => $amount, 'method' => $method]);

    header('Location: index.php?page=invoice&id=' . $saleId . '&auto_pdf=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_GET['action'] ?? '') === 'edit_payment') {
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $amount = to_float($_POST['amount'] ?? 0);
    $date = trim((string)($_POST['payment_date'] ?? ''));
    $method = trim((string)($_POST['method'] ?? 'Cash'));
    $note = trim((string)($_POST['note'] ?? ''));

    if ($paymentId > 0 && $amount > 0) {
        // Get old amount
        $stmt = $pdo->prepare('SELECT amount FROM payments WHERE id=:id AND sale_id=:sale_id AND admin_id = :aid');
        $stmt->execute([':id' => $paymentId, ':sale_id' => $saleId, ':aid' => $writeAdminId]);
        $oldAmount = to_float($stmt->fetchColumn());

        $stmt = $pdo->prepare('
            UPDATE payments
            SET payment_date=:date, amount=:amount, method=:method, note=:note
            WHERE id=:id AND sale_id=:sale_id AND admin_id = :aid
        ');
        $stmt->execute([
            ':id' => $paymentId,
            ':sale_id' => $saleId,
            ':date' => $date === '' ? today_iso() : $date,
            ':amount' => $amount,
            ':method' => $method === '' ? null : $method,
            ':note' => $note === '' ? null : $note,
            ':aid' => $writeAdminId,
        ]);

        // Update paid_amount in sales table
        $stmt = $pdo->prepare('UPDATE sales SET paid_amount = paid_amount - :old + :new WHERE id = :id AND admin_id = :aid');
        $stmt->execute([':old' => $oldAmount, ':new' => $amount, ':id' => $saleId, ':aid' => $writeAdminId]);

        flash_set('success', 'Payment updated.');
        app_audit_log('update', 'payment', $paymentId, ['sale_id' => $saleId, 'amount' => $amount, 'method' => $method]);
    }
    header('Location: index.php?page=invoice&id=' . $saleId . '&auto_pdf=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_GET['action'] ?? '') === 'delete_payment') {
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    if ($paymentId > 0) {
        // Get payment amount before deleting
        $stmt = $pdo->prepare('SELECT amount FROM payments WHERE id=:id AND sale_id=:sale_id AND admin_id = :aid');
        $stmt->execute([':id' => $paymentId, ':sale_id' => $saleId, ':aid' => $writeAdminId]);
        $payAmount = to_float($stmt->fetchColumn());

        if ($payAmount > 0) {
            $stmt = $pdo->prepare('DELETE FROM payments WHERE id=:id AND sale_id=:sale_id AND admin_id = :aid');
            $stmt->execute([':id' => $paymentId, ':sale_id' => $saleId, ':aid' => $writeAdminId]);

            // Update paid_amount in sales table
            $stmt = $pdo->prepare('UPDATE sales SET paid_amount = paid_amount - :amount WHERE id = :id AND admin_id = :aid');
            $stmt->execute([':amount' => $payAmount, ':id' => $saleId, ':aid' => $writeAdminId]);

            flash_set('success', 'Payment deleted.');
            app_audit_log('delete', 'payment', $paymentId, ['sale_id' => $saleId, 'amount' => $payAmount]);
        }
    }
    header('Location: index.php?page=invoice&id=' . $saleId . '&auto_pdf=1');
    exit;
}

$stmt = $pdo->prepare('
    SELECT s.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone, c.address AS customer_address
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    WHERE s.id = :id
    LIMIT 1
');
$stmt->execute([':id' => $saleId]);
$sale = $stmt->fetch();
if (!$sale) {
    flash_set('danger', 'Invoice not found.');
    header('Location: index.php?page=sales');
    exit;
}

$items = json_decode((string)$sale['items_json'], true);
if (!is_array($items)) {
    $items = [];
}

$stmt = $pdo->prepare('SELECT * FROM payments WHERE sale_id = :id ORDER BY id ASC');
$stmt->execute([':id' => $saleId]);
$payments = $stmt->fetchAll();

$totalSell = to_float($sale['total_sell']);
$totalCost = to_float($sale['total_cost']);
$profit = to_float($sale['total_profit']);
$paidTotal = to_float($sale['paid_amount']);
$due = max(0.0, $totalSell - $paidTotal);

$currency = app_setting('currency_symbol', '');
$company = app_setting('company_name', (string)app_config('app_name', 'Company'));
$companyLogo = app_setting('company_logo', '');

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
?>

<style>
    .ls-1 { letter-spacing: 0.05rem; }
    @media print {
        @page { margin: 0; }
        body { margin: 1.6cm; background: #fff !important; }
        .no-print, .toast-container, .app-sidebar, .offcanvas, .navbar, .btn, .dropdown, .modal { display: none !important; }
        .app-shell, .app-content { display: block !important; padding: 0 !important; margin: 0 !important; }
        main { padding: 0 !important; }
        .container, .container-xxl, .container-fluid { max-width: 100% !important; width: 100% !important; padding: 0 !important; margin: 0 !important; }
        .card { border: 0 !important; box-shadow: none !important; }
        .card-body { padding: 0 !important; }
        .table { page-break-inside: auto; }
        tr, td, th { page-break-inside: avoid; }
        .bg-light { background-color: #f8f9fa !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .text-primary { color: #0d6efd !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .badge { border: 1px solid #dee2e6 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h3 mb-0">Invoice</h1>
        <div class="text-muted small">#<?= e((string)$sale['invoice_no']) ?> · <?= e((string)$sale['sale_date']) ?></div>
    </div>
    <div class="d-flex gap-2 no-print">
        <?php 
        $backUrl = (string)($_GET['return_to'] ?? 'index.php?page=sales');
        if (!str_starts_with($backUrl, 'index.php')) $backUrl = 'index.php?page=sales';
        ?>
        <a class="btn btn-outline-secondary" href="<?= e($backUrl) ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
        <button class="btn btn-success" id="sendPdfBtn" type="button"><i class="bi bi-whatsapp me-1"></i>Send WhatsApp</button>
        <button class="btn btn-outline-primary" type="button" onclick="window.print()">Print</button>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4" id="invoiceCard">
    <div class="card-body p-4 p-md-5">
        <!-- Header -->
        <div class="row align-items-center mb-4">
            <div class="col-12 col-md-6 mb-3 mb-md-0">
                <div class="d-flex align-items-center mb-2">
                    <?php if ($companyLogo !== ''): ?>
                        <div class="me-3">
                            <img src="<?= e($companyLogo) ?>" alt="Logo" style="max-height: 64px; max-width: 180px; object-fit: contain;" crossorigin="anonymous">
                        </div>
                    <?php else: ?>
                        <div class="bg-primary text-white rounded-3 p-2 me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                            <i class="bi bi-receipt fs-3"></i>
                        </div>
                    <?php endif; ?>
                    <div>
                        <h2 class="h4 fw-bold mb-0 text-primary"><?= e($company) ?></h2>
                        <div class="text-muted small">Digital Solutions & Services</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 text-md-end">
                <h1 class="h3 fw-bold mb-1">INVOICE</h1>
                <div class="text-muted small">#<?= e((string)$sale['invoice_no']) ?></div>
                <div class="text-muted small"><?= e((string)$sale['sale_date']) ?></div>
            </div>
        </div>

        <hr class="my-4 opacity-10">

        <!-- Billing Info -->
        <div class="row g-4 mb-5">
            <div class="col-12 col-md-6">
                <div class="text-uppercase text-muted fw-bold small mb-2 ls-1">Bill To</div>
                <div class="fw-bold fs-5 text-dark mb-1"><?= e((string)$sale['customer_name']) ?></div>
                <div class="text-muted small mb-1"><i class="bi bi-envelope me-2"></i><?= e((string)($sale['customer_email'] ?? '')) ?></div>
                <div class="text-muted small mb-1"><i class="bi bi-telephone me-2"></i><?= e((string)($sale['customer_phone'] ?? '')) ?></div>
                <div class="text-muted small"><i class="bi bi-geo-alt me-2"></i><?= e((string)($sale['customer_address'] ?? '')) ?></div>
            </div>
            <div class="col-12 col-md-6 text-md-end">
                <div class="text-uppercase text-muted fw-bold small mb-2 ls-1">Payment Status</div>
                <?php if ($due <= 0.01): ?>
                    <span class="badge rounded-pill bg-success-subtle text-success px-3 py-2 border border-success-subtle fw-bold fs-6">PAID IN FULL</span>
                <?php else: ?>
                    <span class="badge rounded-pill bg-danger-subtle text-danger px-3 py-2 border border-danger-subtle fw-bold fs-6">DUE: <?= e(money_fmt($due, $currency)) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Items Table -->
        <div class="table-responsive mb-4">
            <table class="table table-borderless align-middle">
                <thead class="bg-light text-muted small text-uppercase fw-bold ls-1 border-bottom border-top">
                <tr>
                    <th class="ps-3 py-3">Description</th>
                    <th class="py-3">SKU</th>
                    <th class="text-center py-3">Qty</th>
                    <th class="text-end py-3">Price</th>
                    <th class="text-end pe-3 py-3">Total</th>
                </tr>
                </thead>
                <tbody class="border-bottom">
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td class="ps-3 py-3">
                            <div class="fw-bold text-dark"><?= e((string)($it['name'] ?? '')) ?></div>
                            <?php if (!empty($it['is_custom'])): ?>
                                <span class="badge bg-info-subtle text-info small fw-normal border border-info-subtle">Custom Price</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= e((string)($it['sku'] ?? '')) ?></td>
                        <td class="text-center"><?= (int)($it['qty'] ?? 0) ?></td>
                        <td class="text-end"><?= e(money_fmt(to_float($it['sell_price'] ?? 0), $currency)) ?></td>
                        <td class="text-end pe-3 fw-bold text-dark"><?= e(money_fmt(to_float($it['line_sell'] ?? 0), $currency)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="row justify-content-end mb-4">
            <div class="col-12 col-md-5">
                <div class="bg-light rounded-3 p-4">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Subtotal</span>
                        <span class="fw-semibold"><?= e(money_fmt($totalSell, $currency)) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Paid to Date</span>
                        <span class="text-success fw-semibold"><?= e(money_fmt($paidTotal, $currency)) ?></span>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <span class="h5 fw-bold mb-0">Balance Due</span>
                        <span class="h5 fw-bold mb-0 <?= $due > 0.01 ? 'text-danger' : 'text-success' ?>"><?= e(money_fmt($due, $currency)) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ((string)($sale['notes'] ?? '') !== ''): ?>
            <div class="mt-4 pt-3 border-top">
                <div class="text-uppercase text-muted fw-bold small mb-2 ls-1">Notes</div>
                <div class="text-muted small p-3 bg-light rounded-2 border-start border-primary border-4"><?= e((string)$sale['notes']) ?></div>
            </div>
        <?php endif; ?>

        <div class="text-center mt-5 pt-4 text-muted small">
            <p class="mb-0">Thank you for your business!</p>
            <p>This is a computer generated invoice.</p>
        </div>
    </div>
</div>

<div class="row g-3 no-print">
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Payments</h2>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Note</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$payments): ?>
                            <tr><td colspan="5" class="text-muted">No payments.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><?= e((string)$p['payment_date']) ?></td>
                                    <td><?= e((string)($p['method'] ?? '')) ?></td>
                                    <td><?= e((string)($p['note'] ?? '')) ?></td>
                                    <td class="text-end"><?= e(money_fmt(to_float($p['amount']), $currency)) ?></td>
                                    <td class="text-end">
                                        <button
                                            class="btn btn-sm btn-outline-primary editPaymentBtn"
                                            type="button"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editPaymentModal"
                                            data-id="<?= (int)$p['id'] ?>"
                                            data-date="<?= e((string)$p['payment_date']) ?>"
                                            data-amount="<?= e((string)$p['amount']) ?>"
                                            data-method="<?= e((string)($p['method'] ?? '')) ?>"
                                            data-note="<?= e((string)($p['note'] ?? '')) ?>"
                                        >Edit</button>
                                        <form class="d-inline" method="post" action="index.php?page=invoice&id=<?= $saleId ?>&action=delete_payment" onsubmit="return confirm('Delete this payment?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
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
    </div>

    <div class="col-12 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Add Payment</h2>
                <form method="post" action="index.php?page=invoice&id=<?= $saleId ?>&action=add_payment">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Payment Date</label>
                            <input class="form-control" type="date" name="payment_date" value="<?= e(today_iso()) ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Method</label>
                            <input class="form-control" name="method" value="Cash">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Amount (Due: <?= e(money_fmt($due, $currency)) ?>)</label>
                            <input class="form-control text-end" name="amount" type="number" min="0" step="0.01" value="<?= e((string)$due) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Note</label>
                            <input class="form-control" name="note">
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-success" type="submit" <?= $due <= 0 ? 'disabled' : '' ?>>Save Payment</button>
                        <a class="btn btn-outline-secondary" href="index.php?page=invoice&id=<?= $saleId ?>">Refresh</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

    </div>
</div>

<div class="modal fade" id="editPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="index.php?page=invoice&id=<?= $saleId ?>&action=edit_payment">
                <div class="modal-body">
                    <?= csrf_field() ?>
                    <input type="hidden" name="payment_id" id="editPaymentId" value="">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Payment Date</label>
                            <input class="form-control" type="date" name="payment_date" id="editPaymentDate" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Amount</label>
                            <input class="form-control text-end" type="number" step="0.01" min="0.01" name="amount" id="editPaymentAmount" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Method</label>
                            <select class="form-select" name="method" id="editPaymentMethod">
                                <option value="Cash">Cash</option>
                                <option value="Bank">Bank</option>
                                <option value="Mobile">Mobile</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Note</label>
                            <input class="form-control" name="note" id="editPaymentNote">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
    (function() {
        const sendPdfBtn = document.getElementById('sendPdfBtn');
        const invoiceCard = document.getElementById('invoiceCard');
        const saleId = <?= $saleId ?>;
        const invoiceNo = '<?= e((string)$sale['invoice_no']) ?>';

        async function generateAndSendPdf(isAuto = false) {
            let sentOk = false;
            if (isAuto) {
                // Keep visually active during auto send
                sendPdfBtn.disabled = true;
                sendPdfBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Auto Sending...';
            } else {
                sendPdfBtn.disabled = true;
                sendPdfBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generating...';
            }

            try {
                const opt = {
                    margin: 0,
                    filename: `${invoiceNo}.pdf`,
                    image: { type: 'jpeg', quality: 0.9 },
                    html2canvas: { 
                        scale: 2, 
                        useCORS: true,
                        letterRendering: true,
                        logging: false
                    },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
                };

                // Create a clone to avoid modifying the original view during PDF generation
                const element = invoiceCard.cloneNode(true);
                // Ensure text is black and layout is clean for PDF
                element.style.padding = '20mm';
                element.style.backgroundColor = '#ffffff';
                element.querySelectorAll('.bg-light').forEach(el => el.style.backgroundColor = '#f8f9fa');
                element.querySelectorAll('.text-primary').forEach(el => el.style.color = '#0d6efd');
                element.querySelectorAll('.btn, .no-print').forEach(el => el.remove());

                // Pre-load images in the clone
                const images = element.getElementsByTagName('img');
                const imagePromises = Array.from(images).map(img => {
                    if (img.complete) return Promise.resolve();
                    return new Promise(resolve => {
                        img.onload = img.onerror = resolve;
                    });
                });

                await Promise.all(imagePromises);

                // Use worker to ensure everything is loaded before outputting
                const worker = html2pdf().set(opt).from(element);
                const pdfBlob = await worker.toPdf().output('blob');

                if (!isAuto) {
                    sendPdfBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';
                }

                const formData = new FormData();
                formData.append('pdf_file', pdfBlob, `${invoiceNo}.pdf`);
                formData.append('_csrf_token', '<?= csrf_token() ?>');

                const response = await fetch(`index.php?page=invoice&id=${saleId}&action=send_pdf_whatsapp`, {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                if (result.ok) {
                    sentOk = true;
                    if (!isAuto) {
                        alert('PDF Invoice sent successfully on WhatsApp!');
                    } else {
                        sendPdfBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Sent Auto';
                        sendPdfBtn.classList.replace('btn-success', 'btn-outline-success');
                    }
                } else {
                    const pdfMsg = result.error || 'Failed to send PDF.';
                    const fallbackOk = result.fallback_ok === true;
                    const fallbackErr = result.fallback_error || '';
                    if (fallbackOk) {
                        sentOk = true;
                        if (!isAuto) {
                            alert('PDF send failed, but invoice text was sent on WhatsApp.');
                        } else {
                            sendPdfBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Sent Text';
                            sendPdfBtn.classList.replace('btn-success', 'btn-outline-success');
                        }
                    } else {
                        const msg = fallbackErr !== '' ? fallbackErr : pdfMsg;
                        if (!isAuto) alert(msg);
                        else {
                            sendPdfBtn.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Auto Send Failed';
                            sendPdfBtn.classList.replace('btn-success', 'btn-outline-danger');
                            alert(msg);
                        }
                    }
                }
            } catch (err) {
                const msg = 'Error generating/sending PDF: ' + (err?.message || String(err));
                console.error(err);
                if (!isAuto) alert(msg);
                else {
                    sendPdfBtn.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Auto Send Failed';
                    sendPdfBtn.classList.replace('btn-success', 'btn-outline-danger');
                    alert(msg);
                }
            } finally {
                sendPdfBtn.disabled = false;
                if (!isAuto) {
                    sendPdfBtn.innerHTML = '<i class="bi bi-whatsapp me-1"></i>Send WhatsApp';
                } else if (!sentOk) {
                    sendPdfBtn.innerHTML = '<i class="bi bi-whatsapp me-1"></i>Retry Send WhatsApp';
                }
            }
            return sentOk;
        }

        sendPdfBtn.addEventListener('click', () => generateAndSendPdf(false));

        // Auto PDF if triggered from URL
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('auto_pdf') === '1') {
            // Wait a bit for images/styles to load
            setTimeout(async () => {
                const ok = await generateAndSendPdf(true);
                const returnTo = urlParams.get('return_to');
                if (ok && returnTo) {
                    setTimeout(() => {
                        window.location.href = returnTo;
                    }, 2000);
                }
            }, 1500);
            // Clean up URL
            window.history.replaceState({}, document.title, window.location.pathname + window.location.search.replace(/[&?]auto_pdf=1/, ''));
        }

        document.querySelectorAll('.editPaymentBtn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('editPaymentId').value = btn.getAttribute('data-id');
                document.getElementById('editPaymentDate').value = btn.getAttribute('data-date');
                document.getElementById('editPaymentAmount').value = btn.getAttribute('data-amount');
                document.getElementById('editPaymentMethod').value = btn.getAttribute('data-method') || 'Cash';
                document.getElementById('editPaymentNote').value = btn.getAttribute('data-note');
            });
        });
    })();
</script>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
