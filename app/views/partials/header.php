<?php

declare(strict_types=1);

$appName = (string)app_config('app_name', 'Admin');
$flash = flash_get();
$currency = app_setting('currency_symbol', '');
$adminName = (string)($_SESSION['admin_name'] ?? 'Admin');
$isLoggedIn = auth_is_logged_in();
$currentPage = (string)($GLOBALS['CURRENT_PAGE'] ?? '');
$isSuperAdmin = $isLoggedIn && app_is_super_admin();

if ($isSuperAdmin && isset($_GET['scope_admin_id'])) {
    $_SESSION['scope_admin_id'] = max(0, (int)$_GET['scope_admin_id']);
}

$scopeAdminId = $isSuperAdmin ? app_scope_admin_id() : (int)($_SESSION['admin_id'] ?? 0);
$canWrite = $isLoggedIn && app_can_write();
$adminUsers = [];
if ($isSuperAdmin && app_db_initialized()) {
    try {
        $stmt = db()->prepare("SELECT id, name, email FROM admins WHERE role='user' ORDER BY id ASC");
        $stmt->execute();
        $adminUsers = $stmt->fetchAll();
    } catch (Throwable $e) {
        $adminUsers = [];
    }
}

$scopeLabel = '';
if ($isSuperAdmin) {
    if ($scopeAdminId === 0) {
        $scopeLabel = 'All Users';
    } else {
        $scopeLabel = 'User #' . $scopeAdminId;
        foreach ($adminUsers as $u) {
            if ((int)($u['id'] ?? 0) === $scopeAdminId) {
                $scopeLabel = (string)($u['name'] ?? $scopeLabel);
                break;
            }
        }
    }
}

function nav_active(string $key, string $currentPage): string
{
    return $key === $currentPage ? 'active' : '';
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script>
        (function () {
            const saved = localStorage.getItem('theme');
            const theme = saved === 'dark' || saved === 'light' ? saved : 'light';
            document.documentElement.setAttribute('data-bs-theme', theme);
        })();
    </script>
    <style>
        .app-shell { min-height: 100vh; display: flex; }
        .app-sidebar { width: 280px; border-right: 1px solid rgba(0,0,0,.1); }
        [data-bs-theme="dark"] .app-sidebar { border-right-color: rgba(255,255,255,.12); }
        .app-content { min-width: 0; }
        .nav-pill-icon { width: 1.25rem; }
        .toast-container { z-index: 1080; }
        .table-responsive { overflow-y: visible; }
        .dropdown-menu { z-index: 2000; }
    </style>
</head>
<body class="bg-body">
<?php if ($isLoggedIn): ?>
    <div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas" aria-labelledby="sidebarOffcanvasLabel">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title" id="sidebarOffcanvasLabel"><?= e($appName) ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body p-0">
            <div class="p-3">
                <div class="text-muted small mb-2">Menu</div>
                <div class="nav nav-pills flex-column gap-1">
                    <a class="nav-link <?= e(nav_active('dashboard', $currentPage)) ?>" href="index.php?page=dashboard"><i class="bi bi-speedometer2 me-2 nav-pill-icon"></i>Dashboard</a>
                    <a class="nav-link <?= e(nav_active('customers', $currentPage)) ?>" href="index.php?page=customers"><i class="bi bi-people me-2 nav-pill-icon"></i>Customers</a>
                    <a class="nav-link <?= e(nav_active('products', $currentPage)) ?>" href="index.php?page=products"><i class="bi bi-box-seam me-2 nav-pill-icon"></i>Products</a>
                    <a class="nav-link <?= e(nav_active('pricing', $currentPage)) ?>" href="index.php?page=pricing"><i class="bi bi-tag me-2 nav-pill-icon"></i>Custom Pricing</a>
                    <a class="nav-link <?= e(nav_active('sales', $currentPage)) ?>" href="index.php?page=sales"><i class="bi bi-receipt me-2 nav-pill-icon"></i>Sales</a>
                    <a class="nav-link <?= e(nav_active('dues', $currentPage)) ?>" href="index.php?page=dues"><i class="bi bi-cash-coin me-2 nav-pill-icon"></i>Due</a>
                    <a class="nav-link <?= e(nav_active('expenses', $currentPage)) ?>" href="index.php?page=expenses"><i class="bi bi-wallet2 me-2 nav-pill-icon"></i>Expenses</a>
                    <a class="nav-link <?= e(nav_active('reports', $currentPage)) ?>" href="index.php?page=reports"><i class="bi bi-graph-up-arrow me-2 nav-pill-icon"></i>Reports</a>
                    <a class="nav-link <?= e(nav_active('settings', $currentPage)) ?>" href="index.php?page=settings"><i class="bi bi-gear me-2 nav-pill-icon"></i>Settings</a>
                    <a class="nav-link <?= e(nav_active('profile', $currentPage)) ?>" href="index.php?page=profile"><i class="bi bi-person-circle me-2 nav-pill-icon"></i>Profile</a>
                    <?php if ($isSuperAdmin): ?>
                        <a class="nav-link <?= e(nav_active('users', $currentPage)) ?>" href="index.php?page=users"><i class="bi bi-people-fill me-2 nav-pill-icon"></i>User Management</a>
                        <a class="nav-link <?= e(nav_active('admin_tools', $currentPage)) ?>" href="index.php?page=admin_tools"><i class="bi bi-tools me-2 nav-pill-icon"></i>Admin Tools</a>
                        <a class="nav-link <?= e(nav_active('audit_logs', $currentPage)) ?>" href="index.php?page=audit_logs"><i class="bi bi-clipboard-data me-2 nav-pill-icon"></i>Audit Logs</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="app-shell">
        <aside class="app-sidebar d-none d-lg-flex flex-column p-3 bg-body-tertiary">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <a class="text-decoration-none fw-semibold" href="index.php?page=dashboard"><?= e($appName) ?></a>
                <button class="btn btn-sm btn-outline-secondary" type="button" id="themeToggleSidebar"><i class="bi bi-moon-stars"></i></button>
            </div>
            <div class="text-muted small mb-2">Menu</div>
            <div class="nav nav-pills flex-column gap-1">
                <a class="nav-link <?= e(nav_active('dashboard', $currentPage)) ?>" href="index.php?page=dashboard"><i class="bi bi-speedometer2 me-2 nav-pill-icon"></i>Dashboard</a>
                <a class="nav-link <?= e(nav_active('customers', $currentPage)) ?>" href="index.php?page=customers"><i class="bi bi-people me-2 nav-pill-icon"></i>Customers</a>
                <a class="nav-link <?= e(nav_active('products', $currentPage)) ?>" href="index.php?page=products"><i class="bi bi-box-seam me-2 nav-pill-icon"></i>Products</a>
                <a class="nav-link <?= e(nav_active('pricing', $currentPage)) ?>" href="index.php?page=pricing"><i class="bi bi-tag me-2 nav-pill-icon"></i>Custom Pricing</a>
                <a class="nav-link <?= e(nav_active('sales', $currentPage)) ?>" href="index.php?page=sales"><i class="bi bi-receipt me-2 nav-pill-icon"></i>Sales</a>
                <a class="nav-link <?= e(nav_active('dues', $currentPage)) ?>" href="index.php?page=dues"><i class="bi bi-cash-coin me-2 nav-pill-icon"></i>Due</a>
                <a class="nav-link <?= e(nav_active('expenses', $currentPage)) ?>" href="index.php?page=expenses"><i class="bi bi-wallet2 me-2 nav-pill-icon"></i>Expenses</a>
                <a class="nav-link <?= e(nav_active('reports', $currentPage)) ?>" href="index.php?page=reports"><i class="bi bi-graph-up-arrow me-2 nav-pill-icon"></i>Reports</a>
                <a class="nav-link <?= e(nav_active('settings', $currentPage)) ?>" href="index.php?page=settings"><i class="bi bi-gear me-2 nav-pill-icon"></i>Settings</a>
                <a class="nav-link <?= e(nav_active('profile', $currentPage)) ?>" href="index.php?page=profile"><i class="bi bi-person-circle me-2 nav-pill-icon"></i>Profile</a>
                <?php if ($isSuperAdmin): ?>
                    <a class="nav-link <?= e(nav_active('users', $currentPage)) ?>" href="index.php?page=users"><i class="bi bi-people-fill me-2 nav-pill-icon"></i>User Management</a>
                    <a class="nav-link <?= e(nav_active('admin_tools', $currentPage)) ?>" href="index.php?page=admin_tools"><i class="bi bi-tools me-2 nav-pill-icon"></i>Admin Tools</a>
                    <a class="nav-link <?= e(nav_active('audit_logs', $currentPage)) ?>" href="index.php?page=audit_logs"><i class="bi bi-clipboard-data me-2 nav-pill-icon"></i>Audit Logs</a>
                <?php endif; ?>
            </div>
            <div class="mt-auto pt-3 border-top">
                <div class="d-flex align-items-center justify-content-between">
                    <a href="index.php?page=profile" class="text-decoration-none text-muted small"><?= e($adminName) ?></a>
                    <a class="btn btn-sm btn-outline-danger" href="index.php?page=logout"><i class="bi bi-box-arrow-right"></i></a>
                </div>
            </div>
        </aside>

        <div class="app-content flex-grow-1">
            <nav class="navbar navbar-expand bg-body-tertiary border-bottom sticky-top">
                <div class="container-fluid">
                    <button class="btn btn-outline-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas" aria-controls="sidebarOffcanvas">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="d-flex align-items-center ms-2">
                        <span class="fw-semibold"><?= e(ucfirst($currentPage !== '' ? $currentPage : 'dashboard')) ?></span>
                        <?php if ($isSuperAdmin): ?>
                            <span class="badge ms-2 <?= $scopeAdminId === 0 ? 'text-bg-warning' : 'text-bg-secondary' ?>">
                                Viewing: <?= e($scopeLabel) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="ms-auto d-flex align-items-center gap-2">
                        <button class="btn btn-outline-secondary" type="button" id="themeToggleTop"><i class="bi bi-moon-stars"></i></button>
                        <div class="dropdown">
                            <button class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" type="button">
                                <i class="bi bi-person-circle me-1"></i><?= e($adminName) ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if ($isSuperAdmin): ?>
                                    <li class="dropdown-header">Viewing</li>
                                    <li>
                                        <a class="dropdown-item <?= $scopeAdminId === 0 ? 'active' : '' ?>" href="index.php?page=<?= e($currentPage) ?>&scope_admin_id=0">
                                            <i class="bi bi-layers me-2"></i>All Users
                                        </a>
                                    </li>
                                    <?php foreach ($adminUsers as $u): ?>
                                        <?php $uid = (int)($u['id'] ?? 0); ?>
                                        <li>
                                            <a class="dropdown-item <?= $scopeAdminId === $uid ? 'active' : '' ?>" href="index.php?page=<?= e($currentPage) ?>&scope_admin_id=<?= $uid ?>">
                                                <i class="bi bi-person me-2"></i><?= e((string)($u['name'] ?? 'User')) ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="index.php?page=users"><i class="bi bi-people-fill me-2"></i>User Management</a></li>
                                    <li><a class="dropdown-item" href="index.php?page=admin_tools"><i class="bi bi-tools me-2"></i>Admin Tools</a></li>
                                    <li><a class="dropdown-item" href="index.php?page=audit_logs"><i class="bi bi-clipboard-data me-2"></i>Audit Logs</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item" href="index.php?page=settings"><i class="bi bi-gear me-2"></i>Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="index.php?page=logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </nav>

            <main class="container-fluid py-4">
                <div class="container-xxl">
<?php else: ?>
    <main class="container py-5">
<?php endif; ?>
        <?php if ($isSuperAdmin && $scopeAdminId === 0): ?>
            <div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <div class="fw-semibold">All Users mode (read-only)</div>
                    <div class="small">Select a user from the top-right menu to add/edit data.</div>
                </div>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-dark dropdown-toggle" data-bs-toggle="dropdown" type="button">Select User</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php foreach ($adminUsers as $u): ?>
                            <?php $uid = (int)($u['id'] ?? 0); ?>
                            <li>
                                <a class="dropdown-item" href="index.php?page=<?= e($currentPage !== '' ? $currentPage : 'dashboard') ?>&scope_admin_id=<?= $uid ?>">
                                    <?= e((string)($u['name'] ?? 'User')) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!app_db_initialized()): ?>
            <div class="alert alert-warning">
                Database not initialized. Run: <code>php scripts/init_db.php</code>
            </div>
        <?php endif; ?>

        <?php if ($flash): ?>
            <?php
            $type = in_array($flash['type'], ['success', 'danger', 'warning', 'info'], true) ? $flash['type'] : 'info';
            $toastClass = match ($type) {
                'success' => 'text-bg-success',
                'danger' => 'text-bg-danger',
                'warning' => 'text-bg-warning',
                default => 'text-bg-primary',
            };
            ?>
            <div class="toast-container position-fixed top-0 end-0 p-3">
                <div id="flashToast" class="toast <?= e($toastClass) ?>" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex">
                        <div class="toast-body"><?= e((string)$flash['message']) ?></div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
