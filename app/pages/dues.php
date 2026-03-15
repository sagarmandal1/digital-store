<?php

declare(strict_types=1);

auth_require_login();

csrf_verify();

if (!app_db_initialized()) {
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <h1 class="h3">Due</h1>
    <div class="alert alert-warning">Initialize the database first.</div>
    <?php
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php';
    exit;
}

$pdo = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$viewerAdminId = app_scope_admin_id();
$currency = app_setting('currency_symbol', '');

$customers = $pdo->prepare('SELECT id, name FROM customers WHERE (admin_id = :aid OR :aid = 0) ORDER BY name ASC');
$customers->execute([':aid' => $viewerAdminId]);
$customers = $customers->fetchAll();

$customerId = (int)($_GET['customer_id'] ?? 0);
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$showAll = (int)($_GET['all'] ?? 0) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'collect') {
    $ownerId = app_require_user_scope_for_write();
    $saleId = (int)($_POST['sale_id'] ?? 0);
    $amount = to_float($_POST['amount'] ?? 0);
    $paymentDate = trim((string)($_POST['payment_date'] ?? ''));
    $method = trim((string)($_POST['method'] ?? 'Cash'));
    $note = trim((string)($_POST['note'] ?? ''));
    $returnTo = (string)($_POST['return_to'] ?? 'index.php?page=dues');

    if ($paymentDate === '') {
        $paymentDate = today_iso();
    }

    if ($saleId <= 0) {
        flash_set('danger', 'Invalid invoice.');
        header('Location: index.php?page=dues');
        exit;
    }

    $stmt = $pdo->prepare('SELECT total_sell, paid_amount FROM sales WHERE id = :id AND admin_id = :aid LIMIT 1');
    $stmt->execute([':id' => $saleId, ':aid' => $ownerId]);
    $saleData = $stmt->fetch();
    if (!$saleData) {
        flash_set('danger', 'Invoice not found.');
        header('Location: index.php?page=dues');
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
        ':payment_date' => $paymentDate,
        ':amount' => $amount,
        ':method' => $method === '' ? null : $method,
        ':note' => $note === '' ? null : $note,
        ':aid' => $ownerId,
    ]);
    $paymentId = (int)$pdo->lastInsertId();

    // Update paid_amount in sales table
    $stmt = $pdo->prepare('UPDATE sales SET paid_amount = paid_amount + :amount WHERE id = :id AND admin_id = :aid');
    $stmt->execute([':amount' => $amount, ':id' => $saleId, ':aid' => $ownerId]);

    flash_set('success', 'Due collected.');
    app_audit_log('create', 'payment', $paymentId, ['sale_id' => $saleId, 'amount' => $amount, 'method' => $method]);

    // Redirect to invoice page to trigger PDF generation and sending
    // Also include return_to to go back to dues page after sending
    if (!str_starts_with($returnTo, 'index.php')) {
        $returnTo = 'index.php?page=dues';
    }
    header('Location: index.php?page=invoice&id=' . $saleId . '&auto_pdf=1&return_to=' . urlencode($returnTo));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'notify') {
    $ownerId = app_require_user_scope_for_write();
    $saleId = (int)($_POST['sale_id'] ?? 0);
    $message = trim((string)($_POST['message'] ?? ''));
    $returnTo = (string)($_POST['return_to'] ?? 'index.php?page=dues');

    if ($saleId <= 0) {
        flash_set('danger', 'Invalid invoice.');
        header('Location: index.php?page=dues');
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT
            s.invoice_no, s.sale_date, s.total_sell,
            c.name AS customer_name, c.phone,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.sale_id = s.id), 0) AS paid_amount
        FROM sales s
        JOIN customers c ON c.id = s.customer_id
        WHERE s.id = :id AND s.admin_id = :aid
        LIMIT 1
    ');
    $stmt->execute([':id' => $saleId, ':aid' => $ownerId]);
    $row = $stmt->fetch();

    $phone = (string)($row['phone'] ?? '');
    if (trim($phone) === '') {
        flash_set('danger', 'Customer phone is empty. Add phone number in customer profile.');
        header('Location: index.php?page=invoice&id=' . $saleId);
        exit;
    }

    $invoiceNo = (string)($row['invoice_no'] ?? '');
    $saleDate = (string)($row['sale_date'] ?? '');
    $total = to_float($row['total_sell'] ?? 0);
    $paid = to_float($row['paid_amount'] ?? 0);
    $due = max(0.0, $total - $paid);
    $customerName = (string)($row['customer_name'] ?? '');

    $company = app_setting('company_name', (string)app_config('app_name', 'Company'));
    $currencySym = app_setting('currency_symbol', '');

    $baseUrl = trim((string)app_config('base_url', ''));
    $invoiceUrl = $baseUrl !== '' ? (rtrim($baseUrl, '/') . '/index.php?page=invoice&id=' . $saleId) : ('http://localhost:8000/?page=invoice&id=' . $saleId);

    if ($message === '') {
        $message =
            "Payment Due Reminder\n" .
            "Invoice: {$invoiceNo}\n" .
            "Customer: {$customerName}\n" .
            "Date: {$saleDate}\n" .
            "Total: " . money_fmt($total, $currencySym) . "\n" .
            "Paid: " . money_fmt($paid, $currencySym) . "\n" .
            "Due: " . money_fmt($due, $currencySym) . "\n" .
            "Invoice: {$invoiceUrl}\n" .
            $company;
    }

    $res = whatsapp_send_text($phone, $message);
    if (!($res['ok'] ?? false)) {
        $err = (string)($res['error'] ?? '');
        $raw = (string)($res['raw'] ?? '');
        $msg = $err !== '' ? $err : ($raw !== '' ? $raw : 'WhatsApp send failed.');
        flash_set('danger', $msg);
    } else {
        flash_set('success', 'Due reminder sent on WhatsApp.');
    }

    $returnTo = ltrim($returnTo);
    if (str_starts_with($returnTo, 'index.php')) {
        header('Location: ' . $returnTo);
    } else {
        header('Location: index.php?page=dues');
    }
    exit;
}

$where = ['(s.admin_id = :aid OR :aid = 0)'];
$params = [':aid' => $viewerAdminId];

if ($customerId > 0) {
    $where[] = 's.customer_id = :customer_id';
    $params[':customer_id'] = $customerId;
}
if ($from !== '') {
    $where[] = 's.sale_date >= :from';
    $params[':from'] = $from;
}
if ($to !== '') {
    $where[] = 's.sale_date <= :to';
    $params[':to'] = $to;
}
if ($q !== '') {
    $where[] = '(s.invoice_no LIKE :q OR c.name LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$dueCondition = $showAll ? '' : ($whereSql ? 'AND (s.total_sell - s.paid_amount) > 0.01' : 'WHERE (s.total_sell - s.paid_amount) > 0.01');

$stmt = $pdo->prepare("
    SELECT
        s.id,
        s.invoice_no,
        s.sale_date,
        s.total_sell,
        s.total_cost,
        s.total_profit,
        s.paid_amount,
        c.name AS customer_name,
        (SELECT MAX(payment_date) FROM payments pp WHERE pp.sale_id = s.id) AS last_payment_date
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    $whereSql
    $dueCondition
    ORDER BY s.sale_date DESC, s.id DESC
    LIMIT 500
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$summary = [
    'sell' => 0.0,
    'cost' => 0.0,
    'profit' => 0.0,
    'paid' => 0.0,
    'due' => 0.0,
    'open_invoices' => 0,
    'customers_due' => 0,
];
$customersDueSet = [];
foreach ($rows as $r) {
    $sell = to_float($r['total_sell']);
    $cost = to_float($r['total_cost']);
    $profit = to_float($r['total_profit']);
    $paid = to_float($r['paid_amount']);
    $due = max(0.0, $sell - $paid);
    $summary['sell'] += $sell;
    $summary['cost'] += $cost;
    $summary['profit'] += $profit;
    $summary['paid'] += $paid;
    $summary['due'] += $due;
    if ($due > 0) {
        $summary['open_invoices']++;
        $customersDueSet[(string)$r['customer_name']] = true;
    }
}
$summary['customers_due'] = count($customersDueSet);

$returnTo = 'index.php?' . http_build_query(array_merge(['page' => 'dues'], array_filter([
    'customer_id' => $customerId > 0 ? (string)$customerId : null,
    'from' => $from !== '' ? $from : null,
    'to' => $to !== '' ? $to : null,
    'q' => $q !== '' ? $q : null,
    'all' => $showAll ? '1' : null,
], fn($v) => $v !== null)));

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h3 mb-0">Due</h1>
        <div class="text-muted small"><?= $showAll ? 'All invoices' : 'Only due invoices' ?></div>
    </div>
    <a class="btn btn-primary" href="index.php?page=sales&action=create"><i class="bi bi-plus-circle me-1"></i>New Sale</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4 col-lg-2">
        <div class="card shadow-sm"><div class="card-body">
            <div class="text-muted small">Sell</div>
            <div class="h5 mb-0"><?= e(money_fmt($summary['sell'], $currency)) ?></div>
        </div></div>
    </div>
    <div class="col-12 col-md-4 col-lg-2">
        <div class="card shadow-sm"><div class="card-body">
            <div class="text-muted small">Paid</div>
            <div class="h5 mb-0"><?= e(money_fmt($summary['paid'], $currency)) ?></div>
        </div></div>
    </div>
    <div class="col-12 col-md-4 col-lg-2">
        <div class="card shadow-sm"><div class="card-body">
            <div class="text-muted small">Due</div>
            <div class="h5 mb-0 text-danger"><?= e(money_fmt($summary['due'], $currency)) ?></div>
        </div></div>
    </div>
    <div class="col-12 col-md-4 col-lg-2">
        <div class="card shadow-sm"><div class="card-body">
            <div class="text-muted small">Profit</div>
            <div class="h5 mb-0"><?= e(money_fmt($summary['profit'], $currency)) ?></div>
        </div></div>
    </div>
    <div class="col-12 col-md-4 col-lg-2">
        <div class="card shadow-sm"><div class="card-body">
            <div class="text-muted small">Open Invoices</div>
            <div class="h5 mb-0"><?= (int)$summary['open_invoices'] ?></div>
        </div></div>
    </div>
    <div class="col-12 col-md-4 col-lg-2">
        <div class="card shadow-sm"><div class="card-body">
            <div class="text-muted small">Customers Due</div>
            <div class="h5 mb-0"><?= (int)$summary['customers_due'] ?></div>
        </div></div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form class="row g-2 mb-3" method="get" action="index.php">
            <input type="hidden" name="page" value="dues">
            <div class="col-12 col-md-4">
                <label class="form-label">Customer</label>
                <select class="form-select" name="customer_id">
                    <option value="">All customers</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $customerId ? 'selected' : '' ?>><?= e((string)$c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">From</label>
                <input class="form-control" type="date" name="from" value="<?= e($from) ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">To</label>
                <input class="form-control" type="date" name="to" value="<?= e($to) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">Search</label>
                <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Invoice or customer">
            </div>
            <div class="col-12 col-md-1 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="showAll" name="all" <?= $showAll ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="showAll">All</label>
                </div>
            </div>
            <div class="col-12 d-flex align-items-end gap-2">
                <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a class="btn btn-outline-secondary" href="index.php?page=dues">Reset</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Invoice</th>
                    <th>Customer</th>
                    <th class="text-end">Sell</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Due</th>
                    <th>Last Payment</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="text-muted">No invoices found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $sell = to_float($r['total_sell']);
                        $paid = to_float($r['paid_amount']);
                        $due = max(0.0, $sell - $paid);
                        ?>
                        <tr>
                            <td><?= e((string)$r['sale_date']) ?></td>
                            <td class="fw-semibold"><?= e((string)$r['invoice_no']) ?></td>
                            <td><?= e((string)$r['customer_name']) ?></td>
                            <td class="text-end"><?= e(money_fmt($sell, $currency)) ?></td>
                            <td class="text-end"><?= e(money_fmt($paid, $currency)) ?></td>
                            <td class="text-end <?= $due > 0 ? 'text-danger fw-semibold' : '' ?>"><?= e(money_fmt($due, $currency)) ?></td>
                            <td><?= e((string)($r['last_payment_date'] ?? '')) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="index.php?page=invoice&id=<?= (int)$r['id'] ?>"><i class="bi bi-receipt me-1"></i>Invoice</a>
                                <button
                                    class="btn btn-sm btn-outline-success notifyBtn"
                                    type="button"
                                    data-bs-toggle="modal"
                                    data-bs-target="#notifyModal"
                                    data-sale-id="<?= (int)$r['id'] ?>"
                                    data-invoice-no="<?= e((string)$r['invoice_no']) ?>"
                                    data-customer-name="<?= e((string)$r['customer_name']) ?>"
                                    data-due="<?= e((string)$due) ?>"
                                    <?= $due <= 0 ? 'disabled' : '' ?>
                                ><i class="bi bi-whatsapp me-1"></i>Notify</button>
                                <button
                                    class="btn btn-sm btn-success collectBtn"
                                    type="button"
                                    data-bs-toggle="modal"
                                    data-bs-target="#collectModal"
                                    data-sale-id="<?= (int)$r['id'] ?>"
                                    data-invoice-no="<?= e((string)$r['invoice_no']) ?>"
                                    data-customer-name="<?= e((string)$r['customer_name']) ?>"
                                    data-due="<?= e((string)$due) ?>"
                                    <?= $due <= 0 ? 'disabled' : '' ?>
                                ><i class="bi bi-cash-coin me-1"></i>Collect</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="notifyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Send Due Reminder</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="index.php?page=dues">
                <div class="modal-body">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="notify">
                    <input type="hidden" name="sale_id" id="notifySaleId" value="">
                    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">

                    <div class="mb-2">
                        <div class="text-muted small">Invoice</div>
                        <div class="fw-semibold" id="notifyInvoiceText">-</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Message</label>
                        <textarea class="form-control" name="message" id="notifyMessage" rows="6"></textarea>
                        <div class="text-muted small mt-1">Leave empty to use default reminder message.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-whatsapp me-1"></i>Send</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="collectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Collect Due</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="index.php?page=dues">
                <div class="modal-body">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="collect">
                    <input type="hidden" name="sale_id" id="collectSaleId" value="">
                    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">

                    <div class="mb-2">
                        <div class="text-muted small">Invoice</div>
                        <div class="fw-semibold" id="collectInvoiceText">-</div>
                    </div>

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
                            <label class="form-label">Amount (Due: <span id="collectDueText">0</span>)</label>
                            <input class="form-control text-end" name="amount" id="collectAmount" type="number" min="0" step="0.01" value="0" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Note</label>
                            <input class="form-control" name="note" placeholder="Due collection">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    (function () {
        const currency = <?= json_encode($currency) ?>;
        function fmtMoney(amount) {
            const fixed = (Math.round(amount * 100) / 100).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            return currency ? (currency + ' ' + fixed) : fixed;
        }

        document.querySelectorAll('.collectBtn').forEach(btn => {
            btn.addEventListener('click', () => {
                const saleId = btn.getAttribute('data-sale-id') || '';
                const invoiceNo = btn.getAttribute('data-invoice-no') || '';
                const customerName = btn.getAttribute('data-customer-name') || '';
                const due = parseFloat(btn.getAttribute('data-due') || '0') || 0;

                document.getElementById('collectSaleId').value = saleId;
                document.getElementById('collectInvoiceText').textContent = `${invoiceNo} · ${customerName}`;
                document.getElementById('collectDueText').textContent = fmtMoney(due);
                document.getElementById('collectAmount').value = String(due.toFixed(2));
            });
        });

        document.querySelectorAll('.notifyBtn').forEach(btn => {
            btn.addEventListener('click', () => {
                const saleId = btn.getAttribute('data-sale-id') || '';
                const invoiceNo = btn.getAttribute('data-invoice-no') || '';
                const customerName = btn.getAttribute('data-customer-name') || '';
                const due = parseFloat(btn.getAttribute('data-due') || '0') || 0;

                document.getElementById('notifySaleId').value = saleId;
                document.getElementById('notifyInvoiceText').textContent = `${invoiceNo} · ${customerName}`;
                document.getElementById('notifyMessage').value = `Payment Due Reminder\nInvoice: ${invoiceNo}\nCustomer: ${customerName}\nDue: ${fmtMoney(due)}\n`;
            });
        });
    })();
</script>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
