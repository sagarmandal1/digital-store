<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
app_ext_api_allow_cors();

if (!app_db_initialized()) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Database not initialized']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_ext_api_send_json(405, ['ok' => false, 'error' => 'POST required']);
}

$tokenAdminId = app_ext_api_require_token_admin_id();
$aid = app_ext_api_scope_admin_id($tokenAdminId);
if ($aid <= 0) {
    app_ext_api_send_json(403, ['ok' => false, 'error' => 'Select a user scope']);
}

$body = file_get_contents('php://input');
$data = json_decode(is_string($body) ? $body : '', true) ?: [];

$customerId = (int)($data['customer_id'] ?? 0);
$type = trim((string)($data['type'] ?? ''));

if ($customerId <= 0 || !in_array($type, ['due_reminder', 'summary'], true)) {
    app_ext_api_send_json(400, ['ok' => false, 'error' => 'customer_id and type required']);
}

// Fetch customer
$stmt = db()->prepare("SELECT * FROM customers WHERE id = :id AND (admin_id = :aid OR :aid = 0)");
$stmt->execute([':id' => $customerId, ':aid' => $aid]);
$customer = $stmt->fetch();
if (!$customer) {
    app_ext_api_send_json(404, ['ok' => false, 'error' => 'Customer not found']);
}

$phone = (string)($customer['phone'] ?? '');
if ($phone === '') {
    app_ext_api_send_json(400, ['ok' => false, 'error' => 'Customer has no phone number']);
}

// Fetch summary
$sumStmt = db()->prepare("
    SELECT 
        COALESCE(SUM(total_sell), 0) AS total_sell,
        COALESCE(SUM(paid_amount), 0) AS total_paid
    FROM sales
    WHERE customer_id = :cid AND (admin_id = :aid OR :aid = 0)
");
$sumStmt->execute([':cid' => $customerId, ':aid' => $aid]);
$sum = $sumStmt->fetch() ?: ['total_sell' => 0, 'total_paid' => 0];
$due = to_float($sum['total_sell']) - to_float($sum['total_paid']);

$message = '';
$companyName = app_setting_for_admin_id($aid, 'company_name', (string)app_config('app_name', 'Digital Store'));
$currency = app_setting_for_admin_id($aid, 'currency_symbol', '');
$totalSell = to_float($sum['total_sell']);
$totalPaid = to_float($sum['total_paid']);

if ($type === 'due_reminder') {
    if ($due <= 0) {
        app_ext_api_send_json(400, ['ok' => false, 'error' => 'No due amount']);
    }
    $message = sprintf(
        "প্রিয় %s,\n\nআপনার বর্তমান বকেয়া: %s\n\nধন্যবাদ,\n%s",
        $customer['name'],
        money_fmt($due, $currency),
        $companyName
    );
} elseif ($type === 'summary') {
    $message = sprintf(
        "প্রিয় %s,\n\nআপনার লেনদেনের সারসংক্ষেপ:\n- মোট ক্রয়: %s\n- মোট পরিশোধ: %s\n- বর্তমান বকেয়া: %s\n\nধন্যবাদ,\n%s",
        $customer['name'],
        money_fmt($totalSell, $currency),
        money_fmt($totalPaid, $currency),
        money_fmt($due, $currency),
        $companyName
    );
}

if ($message === '') {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Could not generate message']);
}

$res = whatsapp_send_text_for_admin_id($aid, $phone, $message);
if (!($res['ok'] ?? false) && $tokenAdminId === 1 && $aid !== 1) {
    $err = (string)($res['error'] ?? '');
    if (stripos($err, 'api key') !== false || stripos($err, 'not configured') !== false) {
        $res = whatsapp_send_text_for_admin_id(1, $phone, $message);
    }
}
if (!($res['ok'] ?? false)) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => (string)($res['error'] ?? 'Failed to send'), 'raw' => (string)($res['raw'] ?? '')]);
}

app_ext_api_send_json(200, ['ok' => true, 'message' => 'Notification sent']);
