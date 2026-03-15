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

$saleId = (int)($data['sale_id'] ?? 0);
if ($saleId <= 0) {
    app_ext_api_send_json(400, ['ok' => false, 'error' => 'sale_id required']);
}

try {
    $stmt = db()->prepare('
        SELECT
            s.id, s.invoice_no, s.sale_date, s.total_sell, s.paid_amount,
            c.name AS customer_name, c.phone
        FROM sales s
        JOIN customers c ON c.id = s.customer_id
        WHERE s.id = :id AND (s.admin_id = :aid OR :aid = 0)
        LIMIT 1
    ');
    $stmt->execute([':id' => $saleId, ':aid' => $aid]);
    $row = $stmt->fetch();
    if (!$row) {
        app_ext_api_send_json(404, ['ok' => false, 'error' => 'Invoice not found']);
    }

    $phone = trim((string)($row['phone'] ?? ''));
    if ($phone === '') {
        app_ext_api_send_json(400, ['ok' => false, 'error' => 'Customer phone is empty']);
    }

    $company = app_setting_for_admin_id($aid, 'company_name', (string)app_config('app_name', 'Company'));
    $currency = app_setting_for_admin_id($aid, 'currency_symbol', '');

    $invoiceNo = (string)($row['invoice_no'] ?? '');
    $saleDate = (string)($row['sale_date'] ?? '');
    $total = to_float($row['total_sell'] ?? 0);
    $paid = to_float($row['paid_amount'] ?? 0);
    $due = max(0.0, $total - $paid);
    $customerName = (string)($row['customer_name'] ?? '');

    $message = "প্রিয় {$customerName},\n\nআপনার লেনদেনের সারসংক্ষেপ:\n" .
               "• ইনভয়েস: {$invoiceNo}\n" .
               "• তারিখ: {$saleDate}\n" .
               "• মোট বিল: " . money_fmt($total, $currency) . "\n" .
               "• পরিশোধ: " . money_fmt($paid, $currency) . "\n" .
               "• বকেয়া: " . money_fmt($due, $currency) . "\n\n" .
               "ধন্যবাদ,\n" .
               "{$company}";

    $res = whatsapp_send_text_for_admin_id($aid, $phone, $message);
    if (!($res['ok'] ?? false) && $tokenAdminId === 1 && $aid !== 1) {
        $err = (string)($res['error'] ?? '');
        if (stripos($err, 'api key') !== false || stripos($err, 'not configured') !== false) {
            $res = whatsapp_send_text_for_admin_id(1, $phone, $message);
        }
    }
    if (!($res['ok'] ?? false)) {
        app_ext_api_send_json(500, ['ok' => false, 'error' => (string)($res['error'] ?? 'WhatsApp send failed'), 'raw' => (string)($res['raw'] ?? '')]);
    }

    app_ext_api_send_json(200, ['ok' => true, 'message' => 'Invoice sent']);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Invoice send failed']);
}
