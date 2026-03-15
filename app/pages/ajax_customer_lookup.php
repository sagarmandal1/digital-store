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
$phone = trim((string)($_GET['phone'] ?? ''));
$name = trim((string)($_GET['name'] ?? ''));

try {
    $baseSql = "SELECT * FROM customers WHERE (admin_id = :aid OR :aid = 0)";
    $baseParams = [':aid' => $aid];

    $findByPhone = function (string $phone) use ($baseSql, $baseParams) {
        $sql = $baseSql;
        $params = $baseParams;
        $digits = (string)preg_replace('/\D+/', '', $phone);
        if ($digits !== '') {
            $norm = "phone";
            $norm = "REPLACE({$norm}, ' ', '')";
            $norm = "REPLACE({$norm}, '-', '')";
            $norm = "REPLACE({$norm}, '+', '')";
            $norm = "REPLACE({$norm}, '(', '')";
            $norm = "REPLACE({$norm}, ')', '')";
            $norm = "REPLACE({$norm}, char(160), '')";   // nbsp
            $norm = "REPLACE({$norm}, char(8239), '')";  // narrow nbsp
            $norm = "REPLACE({$norm}, char(8209), '')";  // non-breaking hyphen
            $norm = "REPLACE({$norm}, char(8211), '')";  // en dash
            $norm = "REPLACE({$norm}, char(8212), '')";  // em dash
            $norm = "REPLACE({$norm}, char(8722), '')";  // minus sign
            $sql .= " AND {$norm} LIKE :p";
            $params[':p'] = '%' . $digits . '%';
        } else {
            $sql .= " AND phone LIKE :praw";
            $params[':praw'] = '%' . $phone . '%';
        }
        $sql .= " ORDER BY id DESC LIMIT 1";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    };

    $findByName = function (string $name) use ($baseSql, $baseParams) {
        $sql = $baseSql . " AND name LIKE :n ORDER BY id DESC LIMIT 1";
        $params = $baseParams;
        $params[':n'] = '%' . $name . '%';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    };

    if ($phone === '' && $name === '') {
        app_ext_api_send_json(400, ['ok' => false, 'error' => 'Provide phone or name']);
    }

    $c = null;
    if ($phone !== '') {
        $c = $findByPhone($phone);
    }
    if (!$c && $name !== '') {
        $c = $findByName($name);
    }

    if (!$c) {
        echo json_encode(['ok' => true, 'found' => false]);
        exit;
    }

    $cid = (int)$c['id'];
    $sumStmt = db()->prepare("
        SELECT 
            COALESCE(SUM(total_sell), 0) AS total_sell,
            COALESCE(SUM(paid_amount), 0) AS total_paid
        FROM sales
        WHERE customer_id = :cid AND (admin_id = :aid OR :aid = 0)
    ");
    $sumStmt->execute([':cid' => $cid, ':aid' => $aid]);
    $sum = $sumStmt->fetch() ?: ['total_sell' => 0, 'total_paid' => 0];
    $sell = to_float($sum['total_sell'] ?? 0);
    $paid = to_float($sum['total_paid'] ?? 0);
    $due = max(0.0, $sell - $paid);

    $salesStmt = db()->prepare("
        SELECT id, invoice_no, sale_date, total_sell, paid_amount 
        FROM sales 
        WHERE customer_id = :cid AND (admin_id = :aid OR :aid = 0)
        ORDER BY sale_date DESC, id DESC
        LIMIT 5
    ");
    $salesStmt->execute([':cid' => $cid, ':aid' => $aid]);
    $recentSales = $salesStmt->fetchAll() ?: [];

    $payStmt = db()->prepare("
        SELECT p.id, p.payment_date, p.amount, p.method, s.invoice_no
        FROM payments p
        JOIN sales s ON s.id = p.sale_id
        WHERE s.customer_id = :cid AND (p.admin_id = :aid OR :aid = 0)
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT 5
    ");
    $payStmt->execute([':cid' => $cid, ':aid' => $aid]);
    $recentPayments = $payStmt->fetchAll() ?: [];

    app_ext_api_ensure_customer_notes_table();
    $notesStmt = db()->prepare("
        SELECT id, note, created_at
        FROM customer_notes
        WHERE customer_id = :cid AND (admin_id = :aid OR :aid = 0)
        ORDER BY id DESC
        LIMIT 5
    ");
    $notesStmt->execute([':cid' => $cid, ':aid' => $aid]);
    $notes = $notesStmt->fetchAll() ?: [];

    echo json_encode([
        'ok' => true,
        'found' => true,
        'customer' => [
            'id' => $cid,
            'name' => (string)($c['name'] ?? ''),
            'email' => (string)($c['email'] ?? ''),
            'phone' => (string)($c['phone'] ?? ''),
            'address' => (string)($c['address'] ?? ''),
            'category' => (string)($c['category'] ?? ''),
        ],
        'summary' => [
            'total_sell' => $sell,
            'total_paid' => $paid,
            'total_due' => $due,
        ],
        'recent_sales' => array_map(function ($s) {
            return [
                'id' => (int)$s['id'],
                'invoice_no' => (string)$s['invoice_no'],
                'sale_date' => (string)$s['sale_date'],
                'total_sell' => to_float($s['total_sell']),
                'paid_amount' => to_float($s['paid_amount']),
            ];
        }, $recentSales),
        'recent_payments' => array_map(function ($p) {
            return [
                'id' => (int)$p['id'],
                'payment_date' => (string)$p['payment_date'],
                'amount' => to_float($p['amount']),
                'method' => (string)($p['method'] ?? ''),
                'invoice_no' => (string)$p['invoice_no'],
            ];
        }, $recentPayments),
        'notes' => array_map(function ($n) {
            return [
                'id' => (int)$n['id'],
                'note' => (string)$n['note'],
                'created_at' => (string)$n['created_at'],
            ];
        }, $notes),
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Lookup failed']);
}
