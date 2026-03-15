<?php

declare(strict_types=1);

auth_require_login();

if (!app_db_initialized()) {
    http_response_code(400);
    echo 'Database not initialized';
    exit;
}

$pdo = db();
$adminId = app_scope_admin_id();

$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
$customerFilterId = (int)($_GET['customer_id'] ?? 0);

$params = [':aid' => $adminId];
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
        s.id,
        s.invoice_no,
        s.sale_date,
        c.name AS customer_name,
        s.total_sell,
        s.total_cost,
        s.total_profit,
        s.paid_amount,
        s.notes
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    $whereSql
    ORDER BY s.sale_date DESC, s.id DESC
    LIMIT 5000
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'sales_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'wb');
fputcsv($out, ['Invoice', 'Date', 'Customer', 'Sell', 'Paid', 'Due', 'Profit', 'Cost', 'Notes']);
foreach ($rows as $r) {
    $sell = to_float($r['total_sell'] ?? 0);
    $paid = to_float($r['paid_amount'] ?? 0);
    $due = max(0.0, $sell - $paid);
    fputcsv($out, [
        (string)($r['invoice_no'] ?? ''),
        (string)($r['sale_date'] ?? ''),
        (string)($r['customer_name'] ?? ''),
        $sell,
        $paid,
        $due,
        to_float($r['total_profit'] ?? 0),
        to_float($r['total_cost'] ?? 0),
        (string)($r['notes'] ?? ''),
    ]);
}
fclose($out);
exit;

