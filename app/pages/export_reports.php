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

$tab = (string)($_GET['tab'] ?? 'sales');
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
if ($from === '' && $to === '') {
    $from = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
    $to = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
}

header('Content-Type: text/csv; charset=utf-8');

$out = fopen('php://output', 'wb');

if ($tab === 'payments') {
    $where = ['(s.admin_id = :aid OR :aid = 0)'];
    $params = [':aid' => $adminId];
    if ($from !== '') {
        $where[] = 'p.payment_date >= :pfrom';
        $params[':pfrom'] = $from;
    }
    if ($to !== '') {
        $where[] = 'p.payment_date <= :pto';
        $params[':pto'] = $to;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $filename = 'payments_' . date('Ymd_His') . '.csv';
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $stmt = $pdo->prepare("
        SELECT
            p.payment_date,
            s.invoice_no,
            c.name AS customer_name,
            p.amount,
            p.method,
            p.note
        FROM payments p
        JOIN sales s ON s.id = p.sale_id
        JOIN customers c ON c.id = s.customer_id
        $whereSql
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT 10000
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    fputcsv($out, ['Payment Date', 'Invoice', 'Customer', 'Amount', 'Method', 'Note']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (string)($r['payment_date'] ?? ''),
            (string)($r['invoice_no'] ?? ''),
            (string)($r['customer_name'] ?? ''),
            to_float($r['amount'] ?? 0),
            (string)($r['method'] ?? ''),
            (string)($r['note'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

if ($tab === 'customers') {
    $filename = 'customer_due_' . date('Ymd_His') . '.csv';
    header('Content-Disposition: attachment; filename="' . $filename . '"');

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
    $rows = $stmt->fetchAll();

    fputcsv($out, ['Customer', 'Total Sell', 'Paid', 'Due']);
    foreach ($rows as $r) {
        $sell = to_float($r['sell_total'] ?? 0);
        $paid = to_float($r['paid_total'] ?? 0);
        $due = max(0.0, $sell - $paid);
        fputcsv($out, [
            (string)($r['name'] ?? ''),
            $sell,
            $paid,
            $due,
        ]);
    }
    fclose($out);
    exit;
}

$where = ['(s.admin_id = :aid OR :aid = 0)'];
$params = [':aid' => $adminId];
if ($from !== '') {
    $where[] = 's.sale_date >= :from';
    $params[':from'] = $from;
}
if ($to !== '') {
    $where[] = 's.sale_date <= :to';
    $params[':to'] = $to;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$filename = 'sales_report_' . date('Ymd_His') . '.csv';
header('Content-Disposition: attachment; filename="' . $filename . '"');

$stmt = $pdo->prepare("
    SELECT
        s.sale_date,
        s.invoice_no,
        c.name AS customer_name,
        s.total_sell,
        s.total_cost,
        s.total_profit,
        s.paid_amount
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    $whereSql
    ORDER BY s.sale_date DESC, s.id DESC
    LIMIT 10000
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

fputcsv($out, ['Date', 'Invoice', 'Customer', 'Sell', 'Paid', 'Due', 'Profit', 'Cost']);
foreach ($rows as $r) {
    $sell = to_float($r['total_sell'] ?? 0);
    $paid = to_float($r['paid_amount'] ?? 0);
    $due = max(0.0, $sell - $paid);
    fputcsv($out, [
        (string)($r['sale_date'] ?? ''),
        (string)($r['invoice_no'] ?? ''),
        (string)($r['customer_name'] ?? ''),
        $sell,
        $paid,
        $due,
        to_float($r['total_profit'] ?? 0),
        to_float($r['total_cost'] ?? 0),
    ]);
}
fclose($out);
exit;

