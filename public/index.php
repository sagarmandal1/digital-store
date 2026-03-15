<?php

declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$page = (string)($_GET['page'] ?? '');
if ($page === '') {
    $page = auth_is_logged_in() ? 'dashboard' : 'login';
}

$CURRENT_PAGE = $page;

$allowed = [
    'login' => 'login.php',
    'register' => 'register.php',
    'otp' => 'otp.php',
    'forgot' => 'forgot.php',
    'reset_password' => 'reset_password.php',
    'profile' => 'profile.php',
    'logout' => 'logout.php',
    'users' => 'users.php',
    'export_sales' => 'export_sales.php',
    'export_reports' => 'export_reports.php',
    'dashboard' => 'dashboard.php',
    'customers' => 'customers.php',
    'products' => 'products.php',
    'pricing' => 'pricing.php',
    'sales' => 'sales.php',
    'dues' => 'dues.php',
    'invoice' => 'invoice.php',
    'customer_ledger' => 'customer_ledger.php',
    'expenses' => 'expenses.php',
    'reports' => 'reports.php',
    'settings' => 'settings.php',
    'admin_tools' => 'admin_tools.php',
    'audit_logs' => 'audit_logs.php',
    'ajax_audit_logs' => 'ajax_audit_logs.php',
    'ajax_sales_list' => 'ajax_sales_list.php',
    'ajax_expenses_list' => 'ajax_expenses_list.php',
    'ajax_profile' => 'ajax_profile.php',
    'ajax_price' => 'ajax_price.php',
    'ajax_reports' => 'ajax_reports.php',
    'ajax_customer_lookup' => 'ajax_customer_lookup.php',
    'ajax_customer_quick_add' => 'ajax_customer_quick_add.php',
    'ajax_customer_update' => 'ajax_customer_update.php',
    'ajax_quick_sale' => 'ajax_quick_sale.php',
    'ajax_quick_payment' => 'ajax_quick_payment.php',
    'ajax_customer_note_add' => 'ajax_customer_note_add.php',
    'ajax_customer_ledger_events' => 'ajax_customer_ledger_events.php',
    'ajax_products_lookup' => 'ajax_products_lookup.php',
    'ajax_expense_add' => 'ajax_expense_add.php',
    'ajax_daily_stats' => 'ajax_daily_stats.php',
    'ajax_dues_list' => 'ajax_dues_list.php',
    'ajax_send_notification' => 'ajax_send_notification.php',
    'ajax_send_invoice' => 'ajax_send_invoice.php',
];

if (!isset($allowed[$page])) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . $allowed[$page];
