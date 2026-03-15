<?php

declare(strict_types=1);

if (app_ext_api_read_token() !== '') {
    app_ext_api_allow_cors();
    $adminId = app_ext_api_require_token_admin_id();
    $adminId = app_ext_api_scope_admin_id($adminId);
} else {
    auth_require_login();
    $adminId = app_scope_admin_id();
}

header('Content-Type: application/json; charset=utf-8');

if (!app_db_initialized()) {
    echo json_encode(['ok' => false, 'error' => 'Database not initialized']);
    exit;
}

$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$group = strtolower(trim((string)($_GET['group'] ?? 'day')));
$preset = strtolower(trim((string)($_GET['preset'] ?? '')));
$adminId = app_scope_admin_id();

if ($from === '' && $to === '' && $preset !== 'lifetime') {
    $from = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
    $to = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
}

$group = in_array($group, ['day', 'week', 'month'], true) ? $group : 'day';
$limit = (int)($_GET['limit'] ?? 0);
if ($limit <= 0) {
    $limit = match ($group) {
        'week' => 60,
        'month' => 60,
        default => 90,
    };
}
$limit = max(1, min(500, $limit));

$params = [':aid' => $adminId];
$where = ['(admin_id = :aid OR :aid = 0)'];
if ($from !== '') {
    $where[] = 'sale_date >= :from';
    $params[':from'] = $from;
}
if ($to !== '') {
    $where[] = 'sale_date <= :to';
    $params[':to'] = $to;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$labelExpr = match ($group) {
    'week' => "strftime('%Y-W%W', sale_date)",
    'month' => "strftime('%Y-%m', sale_date)",
    default => 'sale_date',
};

$pdo = db();
$stmt = $pdo->prepare("
    SELECT
        {$labelExpr} AS label,
        MIN(sale_date) AS sort_date,
        COALESCE(SUM(total_sell),0) AS sell_total,
        COALESCE(SUM(total_cost),0) AS cost_total,
        COALESCE(SUM(total_profit),0) AS profit_total,
        COALESCE(SUM(paid_amount),0) AS paid_total
    FROM sales
    $whereSql
    GROUP BY label
    ORDER BY sort_date ASC
    LIMIT {$limit}
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$labels = [];
$sell = [];
$cost = [];
$profit = [];
$paid = [];
$summary = [
    'sell' => 0.0,
    'cost' => 0.0,
    'profit' => 0.0,
    'paid' => 0.0,
    'due' => 0.0,
    'expense' => 0.0,
    'net_profit' => 0.0,
];

foreach ($rows as $r) {
    $labels[] = (string)$r['label'];
    $s = to_float($r['sell_total']);
    $c = to_float($r['cost_total']);
    $pr = to_float($r['profit_total']);
    $pd = to_float($r['paid_total']);
    $sell[] = $s;
    $cost[] = $c;
    $profit[] = $pr;
    $paid[] = $pd;
    $summary['sell'] += $s;
    $summary['cost'] += $c;
    $summary['profit'] += $pr;
    $summary['paid'] += $pd;
}
$summary['due'] = max(0.0, $summary['sell'] - $summary['paid']);

$whereExp = ['(admin_id = :aid OR :aid = 0)'];
$paramsExp = [':aid' => $adminId];
if ($from !== '') {
    $whereExp[] = 'expense_date >= :from';
    $paramsExp[':from'] = $from;
}
if ($to !== '') {
    $whereExp[] = 'expense_date <= :to';
    $paramsExp[':to'] = $to;
}
$whereExpSql = $whereExp ? ('WHERE ' . implode(' AND ', $whereExp)) : '';
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses $whereExpSql");
$stmt->execute($paramsExp);
$summary['expense'] = (float)$stmt->fetchColumn();
$summary['net_profit'] = $summary['profit'] - $summary['expense'];

echo json_encode([
    'ok' => true,
    'from' => $from,
    'to' => $to,
    'group' => $group,
    'preset' => $preset,
    'labels' => $labels,
    'sell' => $sell,
    'cost' => $cost,
    'profit' => $profit,
    'paid' => $paid,
    'summary' => $summary,
], JSON_UNESCAPED_UNICODE);
