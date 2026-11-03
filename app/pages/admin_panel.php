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
    <h1 class="h3">Admin Panel</h1>
    <div class="alert alert-warning">Initialize the database first.</div>
    <?php
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php';
    exit;
}

$pdo = db();
app_migrate_audit_logs_table();

$stats = [
    'users_total' => 0,
    'users_active' => 0,
    'sales_total' => 0,
    'revenue_total' => 0.0,
    'due_total' => 0.0,
    'expense_total' => 0.0,
];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE role='user'");
    $stmt->execute();
    $stats['users_total'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE role='user' AND COALESCE(active, 1)=1");
    $stmt->execute();
    $stats['users_active'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(total_sell),0) AS sell, COALESCE(SUM(paid_amount),0) AS paid FROM sales');
    $stmt->execute();
    $row = $stmt->fetch() ?: [];
    $stats['sales_total'] = (int)($row['c'] ?? 0);
    $stats['revenue_total'] = to_float($row['sell'] ?? 0);
    $paidTotal = to_float($row['paid'] ?? 0);
    $stats['due_total'] = max(0.0, $stats['revenue_total'] - $paidTotal);

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses');
    $stmt->execute();
    $stats['expense_total'] = to_float($stmt->fetchColumn());
} catch (Throwable $e) {
}

$recentUsers = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, email, COALESCE(active,1) AS active, created_at FROM admins WHERE role='user' ORDER BY id DESC LIMIT 8");
    $stmt->execute();
    $recentUsers = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $recentUsers = [];
}

$recentAudits = [];
try {
    $stmt = $pdo->prepare('SELECT action, entity, entity_id, created_at FROM audit_logs ORDER BY id DESC LIMIT 10');
    $stmt->execute();
    $recentAudits = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $recentAudits = [];
}

$ops = [
    'smtp_ready' => false,
    'wa_ready' => false,
    'registration_enabled' => true,
    'global_notice_on' => false,
];

try {
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key AND admin_id = 0 LIMIT 1');
    $stmt->execute([':key' => 'smtp_host']);
    $smtpHost = trim((string)($stmt->fetchColumn() ?: ''));
    $stmt->execute([':key' => 'smtp_user']);
    $smtpUser = trim((string)($stmt->fetchColumn() ?: ''));
    $stmt->execute([':key' => 'smtp_pass']);
    $smtpPass = trim((string)($stmt->fetchColumn() ?: ''));
    $ops['smtp_ready'] = $smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '';

    $stmt->execute([':key' => 'whatsapp_base_url']);
    $waBase = trim((string)($stmt->fetchColumn() ?: ''));
    $stmt->execute([':key' => 'whatsapp_api_key']);
    $waKey = trim((string)($stmt->fetchColumn() ?: ''));
    $ops['wa_ready'] = $waBase !== '' && $waKey !== '';

    $stmt->execute([':key' => 'public_registration_enabled']);
    $reg = $stmt->fetchColumn();
    $ops['registration_enabled'] = $reg === false ? true : ((string)$reg !== '0');

    $stmt->execute([':key' => 'global_notice']);
    $notice = trim((string)($stmt->fetchColumn() ?: ''));
    $ops['global_notice_on'] = $notice !== '';
} catch (Throwable $e) {
}

$currency = app_setting('currency_symbol', '');

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0">Admin Panel</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="index.php?page=dashboard">Business Dashboard</a>
        <a class="btn btn-primary" href="index.php?page=users">Manage Users</a>
    </div>
</div>

<div class="row g-3 mb-1">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm bg-primary text-white h-100">
            <div class="card-body">
                <div class="small opacity-75">Total Users</div>
                <div class="h4 mb-0 fw-bold"><?= (int)$stats['users_total'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm bg-success text-white h-100">
            <div class="card-body">
                <div class="small opacity-75">Active Users</div>
                <div class="h4 mb-0 fw-bold"><?= (int)$stats['users_active'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm bg-warning text-dark h-100">
            <div class="card-body">
                <div class="small opacity-75">Platform Revenue</div>
                <div class="h4 mb-0 fw-bold"><?= e(money_fmt($stats['revenue_total'], $currency)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm bg-danger text-white h-100">
            <div class="card-body">
                <div class="small opacity-75">Outstanding Due</div>
                <div class="h4 mb-0 fw-bold"><?= e(money_fmt($stats['due_total'], $currency)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-2">
    <div class="col-12 col-xl-5">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Quick Actions</h2>
                <div class="d-grid gap-2">
                    <a class="btn btn-outline-primary text-start" href="index.php?page=users"><i class="bi bi-people-fill me-2"></i>User Management</a>
                    <a class="btn btn-outline-primary text-start" href="index.php?page=admin_tools"><i class="bi bi-tools me-2"></i>Admin Tools</a>
                    <a class="btn btn-outline-primary text-start" href="index.php?page=audit_logs"><i class="bi bi-clipboard-data me-2"></i>Audit Logs</a>
                    <a class="btn btn-outline-primary text-start" href="index.php?page=reports"><i class="bi bi-graph-up-arrow me-2"></i>Business Reports</a>
                </div>
                <hr>
                <div class="small text-muted mb-2">Switch Scope Quickly</div>
                <div class="d-grid gap-2">
                    <a class="btn btn-sm btn-outline-secondary text-start" href="index.php?page=dashboard&scope_admin_id=0">All Users (Read-only)</a>
                    <?php
                    $scopeUsers = [];
                    try {
                        $stmt = $pdo->prepare("SELECT id, name FROM admins WHERE role='user' ORDER BY id ASC LIMIT 12");
                        $stmt->execute();
                        $scopeUsers = $stmt->fetchAll() ?: [];
                    } catch (Throwable $e) {
                        $scopeUsers = [];
                    }
                    ?>
                    <?php foreach ($scopeUsers as $u): ?>
                        <?php $uid = (int)($u['id'] ?? 0); ?>
                        <a class="btn btn-sm btn-outline-secondary text-start" href="index.php?page=dashboard&scope_admin_id=<?= $uid ?>">User: <?= e((string)($u['name'] ?? ('#' . $uid))) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-7">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h2 class="h5 mb-0">Platform Trend (Monthly)</h2>
                    <span class="badge text-bg-secondary">All Users</span>
                </div>
                <canvas id="platformTrendChart" style="max-height: 230px;"></canvas>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h5 mb-3">System Alerts</h2>
                <div class="list-group list-group-flush small">
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>SMTP Configuration</span>
                        <span class="badge <?= $ops['smtp_ready'] ? 'text-bg-success' : 'text-bg-danger' ?>"><?= $ops['smtp_ready'] ? 'OK' : 'Need Action' ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>WhatsApp API</span>
                        <span class="badge <?= $ops['wa_ready'] ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $ops['wa_ready'] ? 'OK' : 'Need Action' ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>Public Registration</span>
                        <span class="badge <?= $ops['registration_enabled'] ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $ops['registration_enabled'] ? 'Enabled' : 'Disabled' ?></span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>Global Notice</span>
                        <span class="badge <?= $ops['global_notice_on'] ? 'text-bg-info' : 'text-bg-light' ?>"><?= $ops['global_notice_on'] ? 'Active' : 'Off' ?></span>
                    </div>
                </div>
                <div class="mt-3">
                    <a class="btn btn-sm btn-outline-primary" href="index.php?page=admin_tools">Open Admin Tools</a>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h5 mb-3">Recent Users</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$recentUsers): ?>
                                <tr><td colspan="4" class="text-muted">No users found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentUsers as $u): ?>
                                    <?php $active = (int)($u['active'] ?? 1) === 1; ?>
                                    <tr>
                                        <td><?= (int)($u['id'] ?? 0) ?></td>
                                        <td><?= e((string)($u['name'] ?? '')) ?></td>
                                        <td><?= e((string)($u['email'] ?? '')) ?></td>
                                        <td><span class="badge <?= $active ? 'text-bg-success' : 'text-bg-danger' ?>"><?= $active ? 'Active' : 'Disabled' ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Latest Audit Activity</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Action</th>
                                <th>Entity</th>
                                <th class="text-end">ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$recentAudits): ?>
                                <tr><td colspan="4" class="text-muted">No audit activity yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentAudits as $a): ?>
                                    <tr>
                                        <td><?= e(date('Y-m-d H:i', (int)($a['created_at'] ?? 0))) ?></td>
                                        <td><?= e((string)($a['action'] ?? '')) ?></td>
                                        <td><?= e((string)($a['entity'] ?? '')) ?></td>
                                        <td class="text-end"><?= (int)($a['entity_id'] ?? 0) ?></td>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    (function () {
        const canvas = document.getElementById('platformTrendChart');
        if (!canvas) {
            return;
        }
        const currency = <?= json_encode($currency) ?>;
        const chart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    {label: 'Sell', data: [], borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.12)', fill: true, tension: .35, pointRadius: 3},
                    {label: 'Paid', data: [], borderColor: '#198754', backgroundColor: 'rgba(25,135,84,.10)', fill: true, tension: .35, pointRadius: 3},
                    {label: 'Profit', data: [], borderColor: '#6f42c1', backgroundColor: 'rgba(111,66,193,.10)', fill: true, tension: .35, pointRadius: 3},
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {position: 'bottom'},
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const amount = Number(ctx.parsed.y || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                return `${ctx.dataset.label}: ${currency ? currency + ' ' : ''}${amount}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {beginAtZero: true, grid: {color: 'rgba(0,0,0,0.05)'}},
                    x: {grid: {display: false}},
                }
            }
        });

        const url = new URL('index.php', window.location.href);
        url.searchParams.set('page', 'ajax_reports');
        url.searchParams.set('group', 'month');
        url.searchParams.set('preset', 'lifetime');
        url.searchParams.set('limit', '12');

        fetch(url.toString(), {headers: {'Accept': 'application/json'}})
            .then((r) => r.json())
            .then((data) => {
                if (!data || !data.ok) {
                    return;
                }
                chart.data.labels = data.labels || [];
                chart.data.datasets[0].data = data.sell || [];
                chart.data.datasets[1].data = data.paid || [];
                chart.data.datasets[2].data = data.profit || [];
                chart.update();
            })
            .catch(() => {});
    })();
</script>

<?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
