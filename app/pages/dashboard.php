<?php

declare(strict_types=1);

auth_require_login();

$totals = [
    'customers' => 0,
    'products' => 0,
    'sales_count' => 0,
    'sell_total' => 0.0,
    'cost_total' => 0.0,
    'profit_total' => 0.0,
    'paid_total' => 0.0,
    'due_total' => 0.0,
    'expense_total' => 0.0,
    'net_profit' => 0.0,
];

if (app_db_initialized()) {
    $adminId = app_scope_admin_id();
    $stmt = db()->prepare('SELECT COUNT(*) FROM customers WHERE (admin_id = :aid OR :aid = 0)');
    $stmt->execute([':aid' => $adminId]);
    $totals['customers'] = (int)$stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM products WHERE (admin_id = :aid OR :aid = 0)');
    $stmt->execute([':aid' => $adminId]);
    $totals['products'] = (int)$stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(total_sell),0) AS sell, COALESCE(SUM(total_cost),0) AS cost, COALESCE(SUM(total_profit),0) AS profit FROM sales WHERE (admin_id = :aid OR :aid = 0)');
    $stmt->execute([':aid' => $adminId]);
    $row = $stmt->fetch();
    $totals['sales_count'] = (int)($row['c'] ?? 0);
    $totals['sell_total'] = to_float($row['sell'] ?? 0);
    $totals['cost_total'] = to_float($row['cost'] ?? 0);
    $totals['profit_total'] = to_float($row['profit'] ?? 0);

    $stmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM payments p JOIN sales s ON s.id = p.sale_id WHERE (s.admin_id = :aid OR :aid = 0)');
    $stmt->execute([':aid' => $adminId]);
    $totals['paid_total'] = (float)$stmt->fetchColumn();
    $totals['due_total'] = max(0.0, $totals['sell_total'] - $totals['paid_total']);

    $stmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE (admin_id = :aid OR :aid = 0)');
    $stmt->execute([':aid' => $adminId]);
    $totals['expense_total'] = (float)$stmt->fetchColumn();
    $totals['net_profit'] = $totals['profit_total'] - $totals['expense_total'];
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';

$currency = app_setting('currency_symbol', '');
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0">Dashboard</h1>
    <?php if (app_can_write()): ?>
        <a class="btn btn-primary" href="index.php?page=sales&action=create">New Sale</a>
    <?php else: ?>
        <a class="btn btn-primary disabled" aria-disabled="true">New Sale</a>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-12 col-md-6 col-lg-3">
        <div class="card shadow-sm border-0 bg-primary text-white">
            <div class="card-body">
                <div class="small opacity-75">Customers</div>
                <div class="h3 mb-0 fw-bold"><?= e((string)$totals['customers']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
        <div class="card shadow-sm border-0 bg-success text-white">
            <div class="card-body">
                <div class="small opacity-75">Net Profit</div>
                <div class="h3 mb-0 fw-bold"><?= e(money_fmt($totals['net_profit'], $currency)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
        <div class="card shadow-sm border-0 bg-danger text-white">
            <div class="card-body">
                <div class="small opacity-75">Total Expenses</div>
                <div class="h3 mb-0 fw-bold"><?= e(money_fmt($totals['expense_total'], $currency)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
        <div class="card shadow-sm border-0 bg-warning text-dark">
            <div class="card-body">
                <div class="small opacity-75">Total Due</div>
                <div class="h3 mb-0 fw-bold"><?= e(money_fmt($totals['due_total'], $currency)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-3">
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <div>
                        <div class="fw-bold">Sales Analytics</div>
                        <div class="text-muted small" id="dashRangeLabel">Daily (Last 7 Days)</div>
                    </div>
                    <div class="btn-group" role="group" aria-label="Range">
                        <button type="button" class="btn btn-outline-primary btn-sm" data-range="daily">Daily</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-range="weekly">Weekly</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-range="monthly">Monthly</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-range="lifetime">Lifetime</button>
                    </div>
                </div>
                <canvas id="salesChart" style="max-height: 300px;"></canvas>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h5 class="card-title fw-bold mb-2">Quick Summary</h5>
                <div class="text-muted small mb-3" id="dashSummaryLabel">Daily</div>
                <div class="list-group list-group-flush small">
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted">Total Sales</span>
                        <span class="fw-bold" id="kpiSell"><?= e(money_fmt($totals['sell_total'], $currency)) ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted">Total Cost</span>
                        <span class="fw-bold" id="kpiCost"><?= e(money_fmt($totals['cost_total'], $currency)) ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted">Gross Profit</span>
                        <span class="fw-bold text-success" id="kpiProfit"><?= e(money_fmt($totals['profit_total'], $currency)) ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted">Total Paid</span>
                        <span class="fw-bold" id="kpiPaid"><?= e(money_fmt($totals['paid_total'], $currency)) ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted">Expenses</span>
                        <span class="fw-bold text-danger" id="kpiExpense"><?= e(money_fmt($totals['expense_total'], $currency)) ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted">Due</span>
                        <span class="fw-bold text-danger" id="kpiDue"><?= e(money_fmt($totals['due_total'], $currency)) ?></span>
                    </div>
                </div>
                <div class="mt-4 p-3 bg-light rounded-3 border-start border-success border-4">
                    <div class="text-muted small">Final Net Profit</div>
                    <div class="h4 mb-0 fw-bold text-success" id="kpiNetProfit"><?= e(money_fmt($totals['net_profit'], $currency)) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    (function() {
        const currency = <?= json_encode($currency) ?>;
        const ctx = document.getElementById('salesChart').getContext('2d');

        function fmtMoney(amount) {
            const fixed = (Math.round(amount * 100) / 100).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            return currency ? (currency + ' ' + fixed) : fixed;
        }

        function isoDate(d) {
            const z = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
            return z.toISOString().slice(0, 10);
        }

        function rangeToParams(range) {
            const now = new Date();
            if (range === 'daily') {
                const from = new Date(now);
                from.setDate(from.getDate() - 6);
                return {preset: 'daily', group: 'day', from: isoDate(from), to: isoDate(now), label: 'Daily (Last 7 Days)'};
            }
            if (range === 'weekly') {
                const from = new Date(now);
                from.setDate(from.getDate() - 7 * 7);
                return {preset: 'weekly', group: 'week', from: isoDate(from), to: isoDate(now), label: 'Weekly (Last 8 Weeks)'};
            }
            if (range === 'monthly') {
                const from = new Date(now);
                from.setMonth(from.getMonth() - 11);
                return {preset: 'monthly', group: 'month', from: isoDate(from), to: isoDate(now), label: 'Monthly (Last 12 Months)'};
            }
            return {preset: 'lifetime', group: 'month', from: '', to: '', label: 'Lifetime (All Time)'};
        }

        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    {label: 'Sell', data: [], borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.12)', fill: true, tension: .35, pointRadius: 3},
                    {label: 'Paid', data: [], borderColor: '#198754', backgroundColor: 'rgba(25,135,84,.10)', fill: true, tension: .35, pointRadius: 3},
                    {label: 'Profit', data: [], borderColor: '#6f42c1', backgroundColor: 'rgba(111,66,193,.10)', fill: true, tension: .35, pointRadius: 3}
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {callbacks: {label: (c) => `${c.dataset.label}: ${fmtMoney(c.parsed.y || 0)}`}}
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: {callback: (v) => fmtMoney(Number(v))} },
                    x: { grid: { display: false } }
                }
            }
        });

        async function loadRange(range) {
            const params = rangeToParams(range);
            const url = new URL('index.php', window.location.href);
            url.searchParams.set('page', 'ajax_reports');
            url.searchParams.set('group', params.group);
            url.searchParams.set('preset', params.preset);
            if (params.from) url.searchParams.set('from', params.from); else url.searchParams.delete('from');
            if (params.to) url.searchParams.set('to', params.to); else url.searchParams.delete('to');

            const res = await fetch(url.toString(), {headers: {'Accept': 'application/json'}});
            const data = await res.json();
            if (!data || !data.ok) return;

            const dashRangeLabel = document.getElementById('dashRangeLabel');
            const dashSummaryLabel = document.getElementById('dashSummaryLabel');
            if (dashRangeLabel) dashRangeLabel.textContent = params.label;
            if (dashSummaryLabel) dashSummaryLabel.textContent = params.label;

            chart.data.labels = data.labels || [];
            chart.data.datasets[0].data = data.sell || [];
            chart.data.datasets[1].data = data.paid || [];
            chart.data.datasets[2].data = data.profit || [];
            chart.update();

            const s = data.summary || {};
            const setText = (id, val) => {
                const el = document.getElementById(id);
                if (el) el.textContent = fmtMoney(val || 0);
            };
            setText('kpiSell', s.sell);
            setText('kpiCost', s.cost);
            setText('kpiProfit', s.profit);
            setText('kpiPaid', s.paid);
            setText('kpiDue', s.due);
            setText('kpiExpense', s.expense);
            setText('kpiNetProfit', s.net_profit);

            document.querySelectorAll('[data-range]').forEach(btn => {
                btn.classList.toggle('btn-primary', btn.dataset.range === range);
                btn.classList.toggle('btn-outline-primary', btn.dataset.range !== range);
            });
        }

        document.querySelectorAll('[data-range]').forEach(btn => {
            btn.addEventListener('click', () => loadRange(btn.dataset.range));
        });

        loadRange('daily');
    })();
</script>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
