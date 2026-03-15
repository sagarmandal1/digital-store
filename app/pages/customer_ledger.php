<?php

declare(strict_types=1);

auth_require_login();
csrf_verify();

$pdo = db();
$viewerAdminId = app_scope_admin_id();
$sessionAdminId = (int)($_SESSION['admin_id'] ?? 0);
$customerId = (int)($_GET['id'] ?? 0);

if ($customerId <= 0) {
    flash_set('danger', 'Invalid customer.');
    header('Location: index.php?page=customers');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id AND (admin_id = :aid OR :aid = 0) LIMIT 1');
$stmt->execute([':id' => $customerId, ':aid' => $viewerAdminId]);
$customer = $stmt->fetch();

if (!$customer) {
    flash_set('danger', 'Customer not found.');
    header('Location: index.php?page=customers');
    exit;
}

$customerOwnerId = (int)($customer['admin_id'] ?? 0);
if ($customerOwnerId <= 0) {
    $customerOwnerId = $sessionAdminId;
}

// Bulk Payment Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_GET['action'] ?? '') === 'bulk_pay') {
    app_require_user_scope_for_write();
    $amount = to_float($_POST['amount'] ?? 0);
    $date = trim((string)($_POST['payment_date'] ?? today_iso()));
    $method = trim((string)($_POST['method'] ?? 'Cash'));
    $note = trim((string)($_POST['note'] ?? 'Bulk Payment'));

    if ($amount <= 0) {
        flash_set('danger', 'Amount must be greater than 0.');
        header('Location: index.php?page=customer_ledger&id=' . $customerId);
        exit;
    }

    $pdo->beginTransaction();
    try {
        // Find all unpaid sales for this customer, oldest first
        $stmt = $pdo->prepare('
            SELECT id, total_sell, paid_amount 
            FROM sales 
            WHERE customer_id = :cid AND admin_id = :aid AND (total_sell - paid_amount) > 0.01 
            ORDER BY sale_date ASC, id ASC
        ');
        $stmt->execute([':cid' => $customerId, ':aid' => $customerOwnerId]);
        $unpaidSales = $stmt->fetchAll();

        $remaining = $amount;
        foreach ($unpaidSales as $sale) {
            if ($remaining <= 0) break;

            $saleId = (int)$sale['id'];
            $due = to_float($sale['total_sell']) - to_float($sale['paid_amount']);
            $payForThis = min($remaining, $due);

            if ($payForThis > 0) {
                // Insert payment record
                $stmt = $pdo->prepare('INSERT INTO payments (sale_id, payment_date, amount, method, note, admin_id) VALUES (:sid, :date, :amt, :method, :note, :aid)');
                $stmt->execute([
                    ':sid' => $saleId,
                    ':date' => $date,
                    ':amt' => $payForThis,
                    ':method' => $method,
                    ':note' => $note . " (Distributed)",
                    ':aid' => $customerOwnerId,
                ]);

                // Update sale paid_amount
                $stmt = $pdo->prepare('UPDATE sales SET paid_amount = paid_amount + :amt WHERE id = :sid AND admin_id = :aid');
                $stmt->execute([':amt' => $payForThis, ':sid' => $saleId, ':aid' => $customerOwnerId]);

                $remaining -= $payForThis;
            }
        }

        $pdo->commit();
        app_audit_log('bulk_pay', 'customer', $customerId, ['amount' => $amount, 'method' => $method]);
        flash_set('success', 'Bulk payment processed and distributed successfully.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('danger', 'Error processing bulk payment: ' . $e->getMessage());
    }

    header('Location: index.php?page=customer_ledger&id=' . $customerId);
    exit;
}

// Statement PDF Sending Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_GET['action'] ?? '') === 'send_statement_pdf') {
    header('Content-Type: application/json');
    $pdfFile = $_FILES['pdf_file'] ?? null;
    if (!$pdfFile || $pdfFile['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Invalid PDF upload.']);
        exit;
    }

    $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'statement_' . $customerId . '_' . time() . '.pdf';
    if (!move_uploaded_file($pdfFile['tmp_name'], $tempFile)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to save uploaded PDF.']);
        exit;
    }

    $phone = (string)($customer['phone'] ?? '');
    if ($phone === '') {
        unlink($tempFile);
        echo json_encode(['ok' => false, 'error' => 'Customer phone is empty.']);
        exit;
    }

    // Get current balance for caption
    $stmt = $pdo->prepare('
        SELECT 
            (SELECT COALESCE(SUM(total_sell), 0) FROM sales WHERE customer_id = :cid AND admin_id = :aid) - 
            (SELECT COALESCE(SUM(paid_amount), 0) FROM sales WHERE customer_id = :cid AND admin_id = :aid) as balance
    ');
    $stmt->execute([':cid' => $customerId, ':aid' => $customerOwnerId]);
    $balance = to_float($stmt->fetchColumn());
    
    $company = app_setting('company_name', 'Company');
    $currency = app_setting('currency_symbol', '');
    $caption = "Account Statement: " . e((string)$customer['name']) . "\nCurrent Due: " . money_fmt($balance, $currency) . "\n{$company}";

    $res = whatsapp_send_file($phone, $tempFile, $caption);
    unlink($tempFile);

    echo json_encode($res);
    exit;
}

// Fetch all sales and payments for ledger
$stmt = $pdo->prepare("
    SELECT 'sale' as type, id, sale_date as date, invoice_no as ref, total_sell as debit, 0 as credit, notes, NULL as method
    FROM sales WHERE customer_id = :cid AND admin_id = :aid
    UNION ALL
    SELECT 'payment' as type, p.id, p.payment_date as date, s.invoice_no as ref, 0 as debit, p.amount as credit, p.note as notes, p.method
    FROM payments p
    JOIN sales s ON s.id = p.sale_id
    WHERE s.customer_id = :cid AND s.admin_id = :aid
    ORDER BY date ASC, id ASC
");
$stmt->execute([':cid' => $customerId, ':aid' => $customerOwnerId]);
$ledger = $stmt->fetchAll();

$company = app_setting('company_name', 'Company');
$currency = app_setting('currency_symbol', '');
$companyLogo = app_setting('company_logo', '');

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 no-print">
    <div>
        <h1 class="h3 mb-0">Customer Ledger</h1>
        <div class="text-muted small"><?= e((string)$customer['name']) ?> · Statement</div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="index.php?page=customers"><i class="bi bi-arrow-left me-1"></i>Back</a>
        <button class="btn btn-outline-success" id="sendStatementBtn"><i class="bi bi-whatsapp me-1"></i>Send Statement PDF</button>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#bulkPayModal"><i class="bi bi-cash-coin me-1"></i>Bulk Pay</button>
        <button class="btn btn-outline-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print Statement</button>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4" id="ledgerContainer">
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
                            <i class="bi bi-journal-text fs-3"></i>
                        </div>
                    <?php endif; ?>
                    <div>
                        <h2 class="h4 fw-bold mb-0 text-primary"><?= e($company) ?></h2>
                        <div class="text-muted small">Customer Statement / Ledger</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 text-md-end">
                <h1 class="h3 fw-bold mb-1">STATEMENT</h1>
                <div class="text-muted small">Generated on: <?= today_iso() ?></div>
            </div>
        </div>

        <hr class="my-4 opacity-10">

        <!-- Customer Info -->
        <div class="row g-4 mb-5">
            <div class="col-12 col-md-6">
                <div class="text-uppercase text-muted fw-bold small mb-2 ls-1">Customer Details</div>
                <div class="fw-bold fs-5 text-dark mb-1"><?= e((string)$customer['name']) ?></div>
                <div class="text-muted small mb-1"><i class="bi bi-telephone me-2"></i><?= e((string)($customer['phone'] ?? 'N/A')) ?></div>
                <div class="text-muted small"><i class="bi bi-geo-alt me-2"></i><?= e((string)($customer['address'] ?? 'N/A')) ?></div>
            </div>
            <div class="col-12 col-md-6 text-md-end">
                <div class="text-uppercase text-muted fw-bold small mb-2 ls-1">Account Summary</div>
                <div class="h4 fw-bold mb-0 text-danger" id="currentBalanceDisplay">Calculating...</div>
                <div class="text-muted small">Total Due Balance</div>
            </div>
        </div>

        <!-- Ledger Table -->
        <div class="table-responsive">
            <table class="table table-borderless align-middle" id="ledgerTable">
                <thead class="bg-light text-muted small text-uppercase fw-bold ls-1 border-bottom border-top">
                    <tr>
                        <th class="ps-3 py-3">Date</th>
                        <th class="py-3">Reference</th>
                        <th class="py-3">Type</th>
                        <th class="text-end py-3">Debit (+)</th>
                        <th class="text-end py-3">Credit (-)</th>
                        <th class="text-end pe-3 py-3">Balance</th>
                    </tr>
                </thead>
                <tbody class="border-bottom">
                    <?php 
                    $runningBalance = 0.0;
                    foreach ($ledger as $row): 
                        $debit = to_float($row['debit']);
                        $credit = to_float($row['credit']);
                        $runningBalance += ($debit - $credit);
                    ?>
                        <tr>
                            <td class="ps-3 py-3 text-muted small"><?= e((string)$row['date']) ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?= e((string)$row['ref']) ?></div>
                                <div class="text-muted small" style="max-width: 250px;"><?= e((string)$row['notes']) ?></div>
                            </td>
                            <td>
                                <?php if ($row['type'] === 'sale'): ?>
                                    <span class="badge bg-danger-subtle text-danger px-2 border border-danger-subtle fw-normal">Purchase</span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success px-2 border border-success-subtle fw-normal">Payment</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end <?= $debit > 0 ? 'text-dark fw-semibold' : 'text-muted' ?>">
                                <?php if ($row['type'] === 'payment'): ?>
                                    <span class="badge bg-success-subtle text-success px-2 border border-success-subtle fw-normal">
                                        <?php 
                                            $method = strtolower((string)($row['method'] ?? 'cash'));
                                            $icon = 'bi-cash';
                                            if (str_contains($method, 'bkash')) $icon = 'bi-phone';
                                            elseif (str_contains($method, 'nagad')) $icon = 'bi-phone-fill';
                                            elseif (str_contains($method, 'bank')) $icon = 'bi-bank';
                                        ?>
                                        <i class="bi <?= $icon ?> me-1"></i><?= e((string)($row['method'] ?? 'Cash')) ?>
                                    </span>
                                <?php else: ?>
                                    <?= $debit > 0 ? money_fmt($debit, $currency) : '-' ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end <?= $credit > 0 ? 'text-success fw-bold' : 'text-muted' ?>">
                                <?= $credit > 0 ? money_fmt($credit, $currency) : '-' ?>
                            </td>
                            <td class="text-end pe-3 fw-bold <?= $runningBalance > 0.01 ? 'text-danger' : 'text-success' ?>">
                                <?= money_fmt($runningBalance, $currency) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="text-center mt-5 pt-4 text-muted small">
            <p class="mb-0">Thank you for your business!</p>
            <p>This is a computer generated account statement.</p>
        </div>
    </div>
</div>

<!-- Bulk Pay Modal -->
<div class="modal fade" id="bulkPayModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold">Bulk Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="index.php?page=customer_ledger&id=<?= $customerId ?>&action=bulk_pay">
                <?= csrf_field() ?>
                <div class="modal-body p-4">
                    <p class="text-muted small mb-4">This amount will be automatically applied to the oldest unpaid invoices first.</p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Amount</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><?= e($currency) ?></span>
                            <input type="number" step="0.01" class="form-control form-control-lg" name="amount" id="bulkAmount" required>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small">Date</label>
                            <input type="date" class="form-control" name="payment_date" value="<?= today_iso() ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Method</label>
                            <select class="form-select" name="method">
                                <option value="Cash">Cash</option>
                                <option value="Bank">Bank Transfer</option>
                                <option value="bKash">bKash</option>
                                <option value="Nagad">Nagad</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label small">Note (Optional)</label>
                        <textarea class="form-control" name="note" rows="2" placeholder="Bulk collection"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-modal="dismiss">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Apply Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

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
        .bg-light { background-color: #f8f9fa !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .text-primary { color: #0d6efd !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .badge { border: 1px solid #dee2e6 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
    (function() {
        const runningBalance = <?= $runningBalance ?>;
        const balanceDisplay = document.getElementById('currentBalanceDisplay');
        const bulkAmount = document.getElementById('bulkAmount');
        const currency = '<?= e($currency) ?>';
        const sendStatementBtn = document.getElementById('sendStatementBtn');
        const ledgerContainer = document.getElementById('ledgerContainer');
        const customerId = <?= $customerId ?>;

        balanceDisplay.innerText = currency + runningBalance.toLocaleString(undefined, {minimumFractionDigits: 2});
        bulkAmount.value = runningBalance > 0 ? runningBalance.toFixed(2) : '';

        // Statement PDF Sending
        sendStatementBtn.addEventListener('click', async () => {
            sendStatementBtn.disabled = true;
            sendStatementBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generating PDF...';

            try {
                const opt = {
                    margin: 0,
                    filename: `Statement_${customerId}.pdf`,
                    image: { type: 'jpeg', quality: 1 },
                    html2canvas: { scale: 3, useCORS: true },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
                };

                const element = ledgerContainer.cloneNode(true);
                element.style.padding = '20mm';
                element.style.backgroundColor = '#ffffff';
                element.querySelectorAll('.bg-light').forEach(el => el.style.backgroundColor = '#f8f9fa');
                element.querySelectorAll('.text-primary').forEach(el => el.style.color = '#0d6efd');
                
                // Pre-load images
                const images = element.getElementsByTagName('img');
                await Promise.all(Array.from(images).map(img => {
                    if (img.complete) return Promise.resolve();
                    return new Promise(resolve => img.onload = img.onerror = resolve);
                }));

                const pdfBlob = await html2pdf().set(opt).from(element).output('blob');

                sendStatementBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';

                const formData = new FormData();
                formData.append('pdf_file', pdfBlob, `Statement.pdf`);
                formData.append('_csrf_token', '<?= csrf_token() ?>');

                const response = await fetch(`index.php?page=customer_ledger&id=${customerId}&action=send_statement_pdf`, {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                if (result.ok) {
                    alert('Statement PDF sent successfully!');
                } else {
                    alert('Error: ' + (result.error || 'Failed to send PDF.'));
                }
            } catch (err) {
                console.error(err);
                alert('Error: ' + err.message);
            } finally {
                sendStatementBtn.disabled = false;
                sendStatementBtn.innerHTML = '<i class="bi bi-whatsapp me-1"></i>Send Statement PDF';
            }
        });
    })();
</script>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
