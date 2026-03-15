<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
app_ext_api_allow_cors();

if (!app_db_initialized()) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Database not initialized']);
}

$tokenAdminId = app_ext_api_require_token_admin_id();
$aid = app_ext_api_scope_admin_id($tokenAdminId);
$aid = max(0, $aid);

$today = today_iso();

try {
    // Today's Sales
    $stmt = db()->prepare("SELECT COALESCE(SUM(total_sell), 0) FROM sales WHERE sale_date = :d AND (admin_id = :aid OR :aid = 0)");
    $stmt->execute([':d' => $today, ':aid' => $aid]);
    $sales = to_float($stmt->fetchColumn());

    // Today's Payments
    $stmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_date = :d AND (admin_id = :aid OR :aid = 0)");
    $stmt->execute([':d' => $today, ':aid' => $aid]);
    $payments = to_float($stmt->fetchColumn());

    // Today's Expenses
    $stmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date = :d AND (admin_id = :aid OR :aid = 0)");
    $stmt->execute([':d' => $today, ':aid' => $aid]);
    $expenses = to_float($stmt->fetchColumn());

    echo json_encode([
        'ok' => true,
        'date' => $today,
        'stats' => [
            'sales' => $sales,
            'payments' => $payments,
            'expenses' => $expenses,
            'net' => $payments - $expenses,
        ],
    ]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Stats failed']);
}
