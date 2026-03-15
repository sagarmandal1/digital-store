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

$limit = (int)($_GET['limit'] ?? 20);
$limit = max(1, min(100, $limit));
$offset = (int)($_GET['offset'] ?? 0);

try {
    $stmt = db()->prepare("
        SELECT id, category, amount, expense_date, notes
        FROM expenses
        WHERE (admin_id = :aid OR :aid = 0)
        ORDER BY expense_date DESC, id DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindParam(':aid', $aid, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];

    echo json_encode([
        'ok' => true,
        'expenses' => array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'category' => (string)$r['category'],
                'amount' => to_float($r['amount']),
                'date' => (string)$r['expense_date'],
                'notes' => (string)($r['notes'] ?? ''),
            ];
        }, $rows),
    ]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Expenses list failed']);
}
