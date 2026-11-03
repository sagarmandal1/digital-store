<?php

declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

$page = (string)($_GET['page'] ?? '');
if ($page === '') {
    if (auth_is_logged_in()) {
        $page = app_is_super_admin() ? 'admin_panel' : 'dashboard';
    } else {
        $page = 'login';
    }
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
    'services' => 'services.php',
    'pricing' => 'pricing.php',
    'sales' => 'sales.php',
    'dues' => 'dues.php',
    'invoice' => 'invoice.php',
    'customer_ledger' => 'customer_ledger.php',
    'expenses' => 'expenses.php',
    'reports' => 'reports.php',
    'settings' => 'settings.php',
    'admin_panel' => 'admin_panel.php',
    'admin_tools' => 'admin_tools.php',
    'audit_logs' => 'audit_logs.php',
    'ajax_audit_logs' => 'ajax_audit_logs.php',
    'ajax_sales_list' => 'ajax_sales_list.php',
    'ajax_expenses_list' => 'ajax_expenses_list.php',
    'ajax_profile' => 'ajax_profile.php',
    'ajax_extension_bootstrap' => 'ajax_extension_bootstrap.php',
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
    'ajax_task_add' => 'ajax_task_add.php',
    'ajax_task_list' => 'ajax_task_list.php',
    'ajax_task_deliver' => 'ajax_task_deliver.php',
    'ajax_task_update' => 'ajax_task_update.php',
    'ajax_task_delete' => 'ajax_task_delete.php',
    'ajax_suggest_rate' => 'ajax_suggest_rate.php',
    'ajax_custom_price_save' => 'ajax_custom_price_save.php',
    'ajax_check_application_id' => 'ajax_check_application_id.php',
    'ajax_quick_templates' => 'ajax_quick_templates.php',
    'ajax_sellers' => 'ajax_sellers.php',
];

if (!isset($allowed[$page])) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . $allowed[$page];
