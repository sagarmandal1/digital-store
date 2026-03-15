<?php

declare(strict_types=1);

auth_require_login();

if (!app_db_initialized()) {
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <h1 class="h3">Reports</h1>
    <div class="alert alert-warning">Initialize the database first.</div>
    <?php
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php';
    exit;
}

$pdo = db();
$adminId = app_scope_admin_id();
$currency = app_setting('currency_symbol', '');

$tab = (string)($_GET['tab'] ?? 'sales');
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
if ($from === '' && $to === '') {
    $from = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
    $to = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
}

$whereSales = ['(s.admin_id = :aid OR :aid = 0)'];
$paramsSales = [':aid' => $adminId];
if ($from !== '') {
    $whereSales[] = 's.sale_date >= :from';
    $paramsSales[':from'] = $from;
}
if ($to !== '') {
    $whereSales[] = 's.sale_date <= :to';
    $paramsSales[':to'] = $to;
}
$whereSalesSql = $whereSales ? ('WHERE ' . implode(' AND ', $whereSales)) : '';

$wherePay = ['(s.admin_id = :aid OR :aid = 0)'];
$paramsPay = [':aid' => $adminId];
if ($from !== '') {
    $wherePay[] = 'p.payment_date >= :pfrom';
    $paramsPay[':pfrom'] = $from;
}
if ($to !== '') {
    $wherePay[] = 'p.payment_date <= :pto';
    $paramsPay[':pto'] = $to;
}
$wherePaySql = $wherePay ? ('WHERE ' . implode(' AND ', $wherePay)) : '';

$stmt = $pdo->prepare("
    SELECT
        s.*,
        c.name AS customer_name
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    $whereSalesSql
    ORDER BY s.sale_date DESC, s.id DESC
    LIMIT 500
");
$stmt->execute($paramsSales);
$salesRows = $stmt->fetchAll();

$salesSummary = [
    'sell' => 0.0,
    'cost' => 0.0,
    'profit' => 0.0,
    'paid' => 0.0,
    'due' => 0.0,
];
foreach ($salesRows as $s) {
    $sell = to_float($s['total_sell']);
    $cost = to_float($s['total_cost']);
    $profit = to_float($s['total_profit']);
    $paid = to_float($s['paid_amount']);
    $salesSummary['sell'] += $sell;
    $salesSummary['cost'] += $cost;
    $salesSummary['profit'] += $profit;
    $salesSummary['paid'] += $paid;
}
$salesSummary['due'] = max(0.0, $salesSummary['sell'] - $salesSummary['paid']);

$stmt = $pdo->prepare("
    SELECT
        p.*,
        s.invoice_no,
        c.name AS customer_name
    FROM payments p
    JOIN sales s ON s.id = p.sale_id
    JOIN customers c ON c.id = s.customer_id
    $wherePaySql
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT 500
");
$stmt->execute($paramsPay);
$paymentRows = $stmt->fetchAll();

$paymentTotal = 0.0;
foreach ($paymentRows as $p) {
    $paymentTotal += to_float($p['amount']);
}

$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        COALESCE(SUM(s.total_sell), 0) AS sell_total,
        COALESCE(SUM(s.paid_amount), 0) AS paid_total
    FROM customers c
    LEFT JOIN sales s ON s.customer_id = c.id AND (s.admin_id = :aid OR :aid = 0)
    WHERE (c.admin_id = :aid OR :aid = 0)
    GROUP BY c.id
    ORDER BY c.name ASC
");
$stmt->execute([':aid' => $adminId]);
$customerDueRows = $stmt->fetchAll();

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="h3 mb-3">Reports</h1>

<form class="row g-2 mb-3" method="get" action="index.php">
    <input type="hidden" name="page" value="reports">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="col-12 col-md-3">
        <label class="form-label">From</label>
        <input class="form-control" type="date" name="from" value="<?= e($from) ?>">
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label">To</label>
        <input class="form-control" type="date" name="to" value="<?= e($to) ?>">
    </div>
    <div class="col-12 col-md-auto d-flex align-items-end">
        <button class="btn btn-outline-primary" type="submit">Apply</button>
        <a class="btn btn-outline-secondary ms-2" href="index.php?page=reports">Reset</a>
        <a class="btn btn-outline-success ms-2" href="index.php?page=export_reports&tab=<?= e($tab) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>">Export CSV</a>
    </div>
</form>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'sales' ? 'active' : '' ?>" href="index.php?page=reports&tab=sales&from=<?= e($from) ?>&to=<?= e($to) ?>">Sales</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'payments' ? 'active' : '' ?>" href="index.php?page=reports&tab=payments&from=<?= e($from) ?>&to=<?= e($to) ?>">Payments</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'due' ? 'active' : '' ?>" href="index.php?page=reports&tab=due">Customer Due</a></li>
</ul>

<?php if ($tab === 'sales'): ?>
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-8">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="fw-semibold">Sales Trend</div>
                        <div class="text-muted small">Sell / Paid / Profit</div>
                    </div>
                    <div style="height: 300px;">
                        <canvas id="salesChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="fw-semibold">Paid vs Due</div>
                        <div class="text-muted small"><?= e($from) ?> to <?= e($to) ?></div>
                    </div>
                    <div style="height: 300px;">
                        <canvas id="dueChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-md-3">
            <div class="card shadow-sm"><div class="card-body">
                <div class="text-muted small">Sell</div>
                <div class="h5 mb-0" id="kpiSell"><?= e(money_fmt($salesSummary['sell'], $currency)) ?></div>
            </div></div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card shadow-sm"><div class="card-body">
                <div class="text-muted small">Cost</div>
                <div class="h5 mb-0" id="kpiCost"><?= e(money_fmt($salesSummary['cost'], $currency)) ?></div>
            </div></div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card shadow-sm"><div class="card-body">
                <div class="text-muted small">Profit</div>
                <div class="h5 mb-0" id="kpiProfit"><?= e(money_fmt($salesSummary['profit'], $currency)) ?></div>
            </div></div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card shadow-sm"><div class="card-body">
                <div class="text-muted small">Due</div>
                <div class="h5 mb-0" id="kpiDue"><?= e(money_fmt($salesSummary['due'], $currency)) ?></div>
            </div></div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
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
                    <?php if (!$salesRows): ?>
                        <tr><td colspan="8" class="text-muted">No sales in this range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($salesRows as $s): ?>
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
                                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="index.php?page=invoice&id=<?= (int)$s['id'] ?>">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        (async function () {
            const from = <?= json_encode($from) ?>;
            const to = <?= json_encode($to) ?>;
            const currency = <?= json_encode($currency) ?>;

            function fmtMoney(amount) {
                const fixed = (Math.round(amount * 100) / 100).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                return currency ? (currency + ' ' + fixed) : fixed;
            }

            const url = `index.php?page=ajax_reports&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
            const res = await fetch(url, {headers: {'Accept': 'application/json'}});
            const data = await res.json();
            if (!data || !data.ok) return;

            const sellEl = document.getElementById('kpiSell');
            const costEl = document.getElementById('kpiCost');
            const profitEl = document.getElementById('kpiProfit');
            const dueEl = document.getElementById('kpiDue');
            if (sellEl) sellEl.textContent = fmtMoney(data.summary.sell || 0);
            if (costEl) costEl.textContent = fmtMoney(data.summary.cost || 0);
            if (profitEl) profitEl.textContent = fmtMoney(data.summary.profit || 0);
            if (dueEl) dueEl.textContent = fmtMoney(data.summary.due || 0);

            const labels = data.labels || [];
            const sell = data.sell || [];
            const paid = data.paid || [];
            const profit = data.profit || [];

            const salesCtx = document.getElementById('salesChart');
            if (salesCtx) {
                new Chart(salesCtx, {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [
                            {label: 'Sell', data: sell, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.15)', fill: true, tension: .35},
                            {label: 'Paid', data: paid, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,.10)', fill: true, tension: .35},
                            {label: 'Profit', data: profit, borderColor: '#6f42c1', backgroundColor: 'rgba(111,66,193,.10)', fill: true, tension: .35}
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {callbacks: {label: (ctx) => `${ctx.dataset.label}: ${fmtMoney(ctx.parsed.y || 0)}`}}
                        },
                        scales: {
                            y: {ticks: {callback: (v) => fmtMoney(Number(v))}}
                        }
                    }
                });
            }

            const dueCtx = document.getElementById('dueChart');
            if (dueCtx) {
                new Chart(dueCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Paid', 'Due'],
                        datasets: [{
                            data: [data.summary.paid || 0, data.summary.due || 0],
                            backgroundColor: ['#198754', '#dc3545']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {callbacks: {label: (ctx) => `${ctx.label}: ${fmtMoney(ctx.parsed || 0)}`}}
                        }
                    }
                });
            }
        })();
    </script>
<?php elseif ($tab === 'payments'): ?>
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <div class="card shadow-sm"><div class="card-body">
                <div class="text-muted small">Collected</div>
                <div class="h5 mb-0"><?= e(money_fmt($paymentTotal, $currency)) ?></div>
            </div></div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Method</th>
                        <th>Note</th>
                        <th class="text-end">Amount</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$paymentRows): ?>
                        <tr><td colspan="6" class="text-muted">No payments in this range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($paymentRows as $p): ?>
                            <tr>
                                <td><?= e((string)$p['payment_date']) ?></td>
                                <td><?= e((string)$p['invoice_no']) ?></td>
                                <td><?= e((string)$p['customer_name']) ?></td>
                                <td><?= e((string)($p['method'] ?? '')) ?></td>
                                <td><?= e((string)($p['note'] ?? '')) ?></td>
                                <td class="text-end"><?= e(money_fmt(to_float($p['amount']), $currency)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                    <tr>
                        <th>Customer</th>
                        <th class="text-end">Sell</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Due</th>
                        <th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$customerDueRows): ?>
                        <tr><td colspan="5" class="text-muted">No customers.</td></tr>
                    <?php else: ?>
                        <?php foreach ($customerDueRows as $c): ?>
                            <?php
                            $sell = to_float($c['sell_total']);
                            $paid = to_float($c['paid_total']);
                            $due = max(0.0, $sell - $paid);
                            ?>
                            <tr>
                                <td><?= e((string)$c['name']) ?></td>
                                <td class="text-end"><?= e(money_fmt($sell, $currency)) ?></td>
                                <td class="text-end"><?= e(money_fmt($paid, $currency)) ?></td>
                                <td class="text-end fw-semibold"><?= e(money_fmt($due, $currency)) ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="index.php?page=dues&customer_id=<?= (int)$c['id'] ?>">Due</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
