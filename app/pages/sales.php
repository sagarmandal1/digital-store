<?php

declare(strict_types=1);

auth_require_login();

csrf_verify();

if (!app_db_initialized()) {
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <h1 class="h3">Sales</h1>
    <div class="alert alert-warning">Initialize the database first.</div>
    <?php
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php';
    exit;
}

function generate_invoice_no(PDO $pdo, int $adminId): string
{
    $prefix = 'INV-' . (new DateTimeImmutable('now'))->format('Ymd') . '-';
    for ($i = 0; $i < 5; $i++) {
        $candidate = $prefix . random_int(1000, 9999);
        $stmt = $pdo->prepare('SELECT 1 FROM sales WHERE invoice_no = :inv AND admin_id = :aid LIMIT 1');
        $stmt->execute([':inv' => $candidate, ':aid' => $adminId]);
        if (!$stmt->fetchColumn()) {
            return $candidate;
        }
    }
    return $prefix . bin2hex(random_bytes(3));
}

function customer_price_for_product(PDO $pdo, int $customerId, int $productId, int $adminId): array
{
    $stmt = $pdo->prepare('
        SELECT
            p.id,
            p.name,
            p.sku,
            p.cost_price,
            p.sell_price AS default_sell_price,
            cpp.custom_sell_price
        FROM products p
        LEFT JOIN customer_product_prices cpp
            ON cpp.product_id = p.id AND cpp.customer_id = :customer_id AND cpp.admin_id = :aid
        WHERE p.id = :product_id AND p.admin_id = :aid
        LIMIT 1
    ');
    $stmt->execute([
        ':customer_id' => $customerId,
        ':product_id' => $productId,
        ':aid' => $adminId,
    ]);
    $row = $stmt->fetch();
    if (!$row) {
        return [];
    }

    $sell = $row['custom_sell_price'] !== null ? to_float($row['custom_sell_price']) : to_float($row['default_sell_price']);

    return [
        'product_id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'sku' => (string)($row['sku'] ?? ''),
        'cost_price' => to_float($row['cost_price']),
        'sell_price' => $sell,
        'is_custom' => $row['custom_sell_price'] !== null,
    ];
}

$pdo = db();
$action = (string)($_GET['action'] ?? 'index');

if ($action === 'create') {
    app_require_user_scope_for_write();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $saleId = (int)($_POST['sale_id'] ?? 0);
    $adminId = app_require_user_scope_for_write();
    if ($saleId <= 0) {
        flash_set('danger', 'Invalid sale.');
        header('Location: index.php?page=sales');
        exit;
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM sales WHERE id = :id AND admin_id = :aid');
        $stmt->execute([':id' => $saleId, ':aid' => $adminId]);
        
        if ($stmt->rowCount() > 0) {
            app_audit_log('delete', 'sale', $saleId);
            flash_set('success', 'Sale deleted.');
        } else {
            flash_set('danger', 'Sale not found or access denied.');
        }
    } catch (PDOException $e) {
        flash_set('danger', 'Error deleting sale: ' . $e->getMessage());
    }

    header('Location: index.php?page=sales');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $adminId = app_require_user_scope_for_write();
    $saleDate = trim((string)($_POST['sale_date'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $paymentAmount = to_float($_POST['payment_amount'] ?? 0);
    $paymentDate = trim((string)($_POST['payment_date'] ?? ''));
    $paymentMethod = trim((string)($_POST['payment_method'] ?? 'Cash'));

    if ($customerId <= 0) {
        flash_set('danger', 'Select a customer.');
        header('Location: index.php?page=sales&action=create');
        exit;
    }
    
    // Check if customer belongs to admin
    $stmt = $pdo->prepare('SELECT 1 FROM customers WHERE id = :id AND admin_id = :aid');
    $stmt->execute([':id' => $customerId, ':aid' => $adminId]);
    if (!$stmt->fetchColumn()) {
        flash_set('danger', 'Invalid customer.');
        header('Location: index.php?page=sales&action=create');
        exit;
    }
    if ($saleDate === '') {
        $saleDate = today_iso();
    }

    $rows = $_POST['items'] ?? [];
    if (!is_array($rows) || count($rows) === 0) {
        flash_set('danger', 'Add at least one item.');
        header('Location: index.php?page=sales&action=create');
        exit;
    }

    $items = [];
    $totalCost = 0.0;
    $totalSell = 0.0;

    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $productId = (int)($r['product_id'] ?? 0);
        $qty = (int)($r['qty'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            continue;
        }
        $p = customer_price_for_product($pdo, $customerId, $productId, $adminId);
        if (!$p) {
            continue;
        }
        $lineCost = $p['cost_price'] * $qty;
        $lineSell = $p['sell_price'] * $qty;
        $items[] = [
            'product_id' => $p['product_id'],
            'name' => $p['name'],
            'sku' => $p['sku'],
            'qty' => $qty,
            'cost_price' => $p['cost_price'],
            'sell_price' => $p['sell_price'],
            'line_cost' => $lineCost,
            'line_sell' => $lineSell,
            'line_profit' => $lineSell - $lineCost,
            'is_custom' => $p['is_custom'],
        ];
        $totalCost += $lineCost;
        $totalSell += $lineSell;
    }

    if (!$items) {
        flash_set('danger', 'No valid items found.');
        header('Location: index.php?page=sales&action=create');
        exit;
    }

    $totalProfit = $totalSell - $totalCost;
    if ($paymentDate === '') {
        $paymentDate = $saleDate;
    }
    $paymentAmount = max(0.0, min($paymentAmount, $totalSell));

    $invoiceNo = generate_invoice_no($pdo, $adminId);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            INSERT INTO sales (invoice_no, customer_id, sale_date, items_json, total_cost, total_sell, total_profit, paid_amount, notes, admin_id)
            VALUES (:invoice_no, :customer_id, :sale_date, :items_json, :total_cost, :total_sell, :total_profit, :paid_amount, :notes, :aid)
        ');
        $stmt->execute([
            ':invoice_no' => $invoiceNo,
            ':customer_id' => $customerId,
            ':sale_date' => $saleDate,
            ':items_json' => json_encode($items, JSON_UNESCAPED_UNICODE),
            ':total_cost' => $totalCost,
            ':total_sell' => $totalSell,
            ':total_profit' => $totalProfit,
            ':paid_amount' => $paymentAmount,
            ':notes' => $notes === '' ? null : $notes,
            ':aid' => $adminId,
        ]);
        $saleId = (int)$pdo->lastInsertId();

        if ($paymentAmount > 0) {
            $stmt = $pdo->prepare('
                INSERT INTO payments (sale_id, payment_date, amount, method, note, admin_id)
                VALUES (:sale_id, :payment_date, :amount, :method, :note, :aid)
            ');
            $stmt->execute([
                ':sale_id' => $saleId,
                ':payment_date' => $paymentDate,
                ':amount' => $paymentAmount,
                ':method' => $paymentMethod === '' ? null : $paymentMethod,
                ':note' => 'Initial payment',
                ':aid' => $adminId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    flash_set('success', 'Sale created.');
    app_audit_log('create', 'sale', $saleId, ['invoice_no' => $invoiceNo, 'customer_id' => $customerId, 'total_sell' => $totalSell, 'paid_amount' => $paymentAmount]);
    header('Location: index.php?page=invoice&id=' . $saleId . '&auto_pdf=1');
    exit;
}

$currency = app_setting('currency_symbol', '');
$adminId = app_scope_admin_id();
$customers = $pdo->prepare('SELECT id, name FROM customers WHERE (admin_id = :aid OR :aid = 0) ORDER BY name ASC');
$customers->execute([':aid' => $adminId]);
$customers = $customers->fetchAll();

$products = $pdo->prepare('SELECT id, name, sku FROM products WHERE active = 1 AND (admin_id = :aid OR :aid = 0) ORDER BY name ASC');
$products->execute([':aid' => $adminId]);
$products = $products->fetchAll();

$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
$customerFilterId = (int)($_GET['customer_id'] ?? 0);

$listAdminId = app_scope_admin_id();
$params = [':aid' => $listAdminId];
$where = ['(s.admin_id = :aid OR :aid = 0)'];
if ($dateFrom !== '') {
    $where[] = 's.sale_date >= :from';
    $params[':from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 's.sale_date <= :to';
    $params[':to'] = $dateTo;
}
if ($customerFilterId > 0) {
    $where[] = 's.customer_id = :customer_id';
    $params[':customer_id'] = $customerFilterId;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("
    SELECT
        s.*,
        c.name AS customer_name
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    $whereSql
    ORDER BY s.id DESC
    LIMIT 200
");
$stmt->execute($params);
$sales = $stmt->fetchAll();

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0">Sales</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="index.php?page=export_sales&from=<?= e($dateFrom) ?>&to=<?= e($dateTo) ?>&customer_id=<?= (int)$customerFilterId ?>">Export CSV</a>
        <?php if (app_can_write()): ?>
            <a class="btn btn-primary" href="index.php?page=sales&action=create">New Sale</a>
        <?php else: ?>
            <a class="btn btn-primary disabled" aria-disabled="true">New Sale</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($action === 'create'): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h5 mb-3">Create Sale</h2>
            <form method="post" action="index.php?page=sales&action=create" id="saleForm">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label">Customer</label>
                        <select class="form-select" name="customer_id" id="customerId" required>
                            <option value="">Select customer...</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"><?= e((string)$c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Sale Date</label>
                        <input class="form-control" type="date" name="sale_date" value="<?= e(today_iso()) ?>" required>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Invoice</label>
                        <input class="form-control" value="Auto" disabled>
                    </div>
                </div>

                <div class="table-responsive mt-3">
                    <table class="table table-bordered align-middle" id="itemsTable">
                        <thead class="table-light">
                        <tr>
                            <th style="min-width: 260px;">Product</th>
                            <th style="width: 120px;" class="text-end">Qty</th>
                            <th style="width: 160px;" class="text-end">Sell Price</th>
                            <th style="width: 160px;" class="text-end">Line Total</th>
                            <th style="width: 60px;"></th>
                        </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <button class="btn btn-outline-primary" type="button" id="addRowBtn">Add Item</button>

                <div class="row g-3 mt-2">
                    <div class="col-12 col-md-6">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="row g-2">
                            <div class="col-12 col-md-6">
                                <label class="form-label">Initial Payment</label>
                                <input class="form-control text-end" name="payment_amount" type="number" step="0.01" min="0" value="0">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Payment Date</label>
                                <input class="form-control" name="payment_date" type="date" value="<?= e(today_iso()) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Payment Method</label>
                                <input class="form-control" name="payment_method" value="Cash">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-3">
                    <div class="text-end">
                        <div class="text-muted small">Total</div>
                        <div class="h4 mb-0" id="grandTotal"><?= e(money_fmt(0.0, $currency)) ?></div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-success" type="submit">Save & View Invoice</button>
                    <a class="btn btn-outline-secondary" href="index.php?page=sales">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        const currency = <?= json_encode($currency) ?>;
        const products = <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => (string)$p['name'], 'sku' => (string)($p['sku'] ?? '')], $products), JSON_UNESCAPED_UNICODE) ?>;

        function fmtMoney(amount) {
            const fixed = (Math.round(amount * 100) / 100).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            return currency ? (currency + ' ' + fixed) : fixed;
        }

        function addRow() {
            const tbody = document.querySelector('#itemsTable tbody');
            const idx = tbody.children.length;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <select class="form-select form-select-sm productSel" name="items[${idx}][product_id]" required>
                        <option value="">Select product...</option>
                        ${products.map(p => `<option value="${p.id}">${escapeHtml(p.name)}${p.sku ? ' ('+escapeHtml(p.sku)+')' : ''}</option>`).join('')}
                    </select>
                </td>
                <td><input class="form-control form-control-sm text-end qtyInp" name="items[${idx}][qty]" type="number" min="1" step="1" value="1" required></td>
                <td><input class="form-control form-control-sm text-end sellInp" type="number" step="0.01" min="0" value="0" readonly></td>
                <td class="text-end fw-semibold lineTotal">0.00</td>
                <td class="text-end"><button class="btn btn-sm btn-outline-danger delBtn" type="button">X</button></td>
            `;
            tbody.appendChild(tr);

            tr.querySelector('.delBtn').addEventListener('click', () => {
                tr.remove();
                renumberRows();
                recalcTotals();
            });

            tr.querySelector('.productSel').addEventListener('change', async (e) => {
                await refreshRowPricing(tr);
                recalcTotals();
            });

            tr.querySelector('.qtyInp').addEventListener('input', () => {
                recalcTotals();
            });
        }

        function renumberRows() {
            const rows = [...document.querySelectorAll('#itemsTable tbody tr')];
            rows.forEach((tr, idx) => {
                tr.querySelector('.productSel').setAttribute('name', `items[${idx}][product_id]`);
                tr.querySelector('.qtyInp').setAttribute('name', `items[${idx}][qty]`);
            });
        }

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
        }

        async function refreshRowPricing(tr) {
            const customerId = document.getElementById('customerId').value;
            const productId = tr.querySelector('.productSel').value;
            const sellInp = tr.querySelector('.sellInp');
            if (!customerId || !productId) {
                sellInp.value = '0';
                return;
            }
            const url = `index.php?page=ajax_price&customer_id=${encodeURIComponent(customerId)}&product_id=${encodeURIComponent(productId)}`;
            const res = await fetch(url, {headers: {'Accept': 'application/json'}});
            const data = await res.json();
            if (data && data.ok) {
                sellInp.value = String(data.sell_price ?? 0);
            } else {
                sellInp.value = '0';
            }
        }

        function recalcTotals() {
            let grand = 0;
            document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {
                const qty = parseInt(tr.querySelector('.qtyInp').value || '0', 10);
                const sell = parseFloat(tr.querySelector('.sellInp').value || '0');
                const line = Math.max(0, qty) * Math.max(0, sell);
                tr.querySelector('.lineTotal').textContent = fmtMoney(line);
                grand += line;
            });
            document.getElementById('grandTotal').textContent = fmtMoney(grand);
        }

        document.getElementById('addRowBtn').addEventListener('click', () => {
            addRow();
        });

        document.getElementById('customerId').addEventListener('change', async () => {
            const rows = [...document.querySelectorAll('#itemsTable tbody tr')];
            for (const tr of rows) {
                await refreshRowPricing(tr);
            }
            recalcTotals();
        });

        addRow();
        recalcTotals();
    </script>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form class="row g-2 mb-3" method="get" action="index.php">
            <input type="hidden" name="page" value="sales">
            <div class="col-12 col-md-4">
                <label class="form-label">Customer</label>
                <select class="form-select" name="customer_id">
                    <option value="">All customers</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $customerFilterId ? 'selected' : '' ?>>
                            <?= e((string)$c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">From</label>
                <input class="form-control" type="date" name="from" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">To</label>
                <input class="form-control" type="date" name="to" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-12 col-md-auto d-flex align-items-end">
                <button class="btn btn-outline-primary" type="submit">Filter</button>
                <a class="btn btn-outline-secondary ms-2" href="index.php?page=sales">Reset</a>
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
                    <th class="text-end">Profit</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$sales): ?>
                    <tr><td colspan="8" class="text-muted">No sales found.</td></tr>
                <?php else: ?>
                    <?php foreach ($sales as $s): ?>
                        <?php
                        $sell = to_float($s['total_sell']);
                        $paid = to_float($s['paid_amount']);
                        $due = max(0.0, $sell - $paid);
                        ?>
                        <tr>
                            <td><?= e((string)$s['sale_date']) ?></td>
                            <td><?= e((string)$s['invoice_no']) ?></td>
                            <td><?= e((string)$s['customer_name']) ?></td>
                            <td class="text-end"><?= e(money_fmt($sell, $currency)) ?></td>
                            <td class="text-end"><?= e(money_fmt($paid, $currency)) ?></td>
                            <td class="text-end"><?= e(money_fmt($due, $currency)) ?></td>
                            <td class="text-end"><?= e(money_fmt(to_float($s['total_profit']), $currency)) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="index.php?page=invoice&id=<?= (int)$s['id'] ?>">View</a>
                                <form class="d-inline" method="post" action="index.php?page=sales&action=delete" onsubmit="return confirm('Delete this sale and its payments?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="sale_id" value="<?= (int)$s['id'] ?>">
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

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
