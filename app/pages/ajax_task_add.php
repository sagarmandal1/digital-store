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
$ownerId = app_ext_api_scope_admin_id($tokenAdminId);
if ($ownerId <= 0) {
    app_ext_api_send_json(403, ['ok' => false, 'error' => 'Select a user scope']);
}

app_ensure_customer_tasks_table();
app_ensure_customer_office_rates_table();

$body = file_get_contents('php://input');
$data = json_decode(is_string($body) ? $body : '', true) ?: [];

$customerId = (int)($data['customer_id'] ?? 0);
$taskName = trim((string)($data['task_name'] ?? ''));
$notes = trim((string)($data['notes'] ?? ''));
$amount = to_float($data['amount'] ?? 0);
$sellerId = (int)($data['seller_id'] ?? 0);
$sellerName = trim((string)($data['seller_name'] ?? ''));

if ($sellerId > 0 && $sellerName === '') {
    try {
        $sStmt = db()->prepare('SELECT name FROM sellers WHERE id = :sid');
        $sStmt->execute([':sid' => $sellerId]);
        $sellerName = (string)($sStmt->fetchColumn() ?: '');
    } catch (Throwable $e) {}
}

if ($customerId <= 0 || $taskName === '') {
    app_ext_api_send_json(400, ['ok' => false, 'error' => 'customer_id and task_name required']);
}

// Verify customer exists and belongs to this admin
$stmt = db()->prepare('SELECT 1 FROM customers WHERE id = :id AND admin_id = :aid LIMIT 1');
$stmt->execute([':id' => $customerId, ':aid' => $ownerId]);
if (!$stmt->fetchColumn()) {
    app_ext_api_send_json(404, ['ok' => false, 'error' => 'Customer not found']);
}

try {
    // Extract application ID if present in notes or task name
    $appId = '';
    if (preg_match('/(?:Application\s*ID|আবেদন\s*আইডি)[\s:]*([0-9]{5,})/i', $notes, $m)) {
        $appId = $m[1];
    } elseif (preg_match('/\((\d{5,})\)/', $taskName, $m)) {
        $appId = $m[1];
    }

    $existingPendingId = null;
    if ($appId !== '') {
        // 1. Check if already delivered
        $stmtDelivered = db()->prepare("
            SELECT id FROM customer_tasks
            WHERE (notes LIKE :q OR task_name LIKE :q)
              AND admin_id = :aid
              AND status != 'pending'
            ORDER BY id DESC LIMIT 1
        ");
        $stmtDelivered->execute([
            ':q' => '%' . $appId . '%',
            ':aid' => $ownerId,
        ]);
        $deliveredId = $stmtDelivered->fetchColumn();
        if ($deliveredId) {
            app_ext_api_send_json(400, [
                'ok' => false,
                'error' => "আবেদন ID ({$appId}) দিয়ে ইতিমধ্যে একটি কাজ ডেলিভারি সম্পন্ন হয়েছে (কাজ #{$deliveredId})"
            ]);
        }

        // 2. Check if already pending for this customer (or admin) - update instead of duplicate
        $stmtPending = db()->prepare("
            SELECT id FROM customer_tasks
            WHERE (notes LIKE :q OR task_name LIKE :q)
              AND admin_id = :aid
              AND (customer_id = :cid OR :cid = 0)
              AND status = 'pending'
            ORDER BY id DESC LIMIT 1
        ");
        $stmtPending->execute([
            ':q' => '%' . $appId . '%',
            ':aid' => $ownerId,
            ':cid' => $customerId,
        ]);
        $existingPendingId = $stmtPending->fetchColumn();
    }

    if ($existingPendingId) {
        // Update existing pending task
        $taskId = (int)$existingPendingId;
        $stmt = db()->prepare('
            UPDATE customer_tasks
            SET task_name = :name, notes = :notes, amount = :amount, customer_id = :cid,
                seller_id = :sid, seller_name = :sname
            WHERE id = :id AND admin_id = :aid
        ');
        $stmt->execute([
            ':name' => $taskName,
            ':notes' => $notes,
            ':amount' => $amount,
            ':cid' => $customerId,
            ':sid' => $sellerId > 0 ? $sellerId : null,
            ':sname' => $sellerName,
            ':id' => $taskId,
            ':aid' => $ownerId,
        ]);
        $actionType = 'update';
    } else {
        // Insert new task
        $stmt = db()->prepare('
            INSERT INTO customer_tasks (customer_id, admin_id, task_name, notes, amount, status, seller_id, seller_name)
            VALUES (:cid, :aid, :name, :notes, :amount, \'pending\', :sid, :sname)
        ');
        $stmt->execute([
            ':cid' => $customerId,
            ':aid' => $ownerId,
            ':name' => $taskName,
            ':notes' => $notes,
            ':amount' => $amount,
            ':sid' => $sellerId > 0 ? $sellerId : null,
            ':sname' => $sellerName,
        ]);
        $taskId = (int)db()->lastInsertId();
        $actionType = 'create';
    }

    // Auto-learn customer office rate for future suggestions
    if ($amount > 0 && $notes !== '') {
        try {
            $officeInfo = app_extract_office_info($notes);
            $namesToSave = array_unique(array_filter([
                $officeInfo['zone_label'] ?? '',
                $officeInfo['zone'] ?? '',
                $officeInfo['name'] ?? '',
                $officeInfo['city_corp'] ?? '',
            ]));
            if (!empty($namesToSave) && ($officeInfo['name'] ?? '') !== 'Default') {
                $stmtRate = db()->prepare('
                    INSERT INTO customer_office_rates (customer_id, admin_id, office_name, rate, updated_at)
                    VALUES (:cid, :aid, :office, :rate, datetime(\'now\'))
                    ON CONFLICT(customer_id, office_name, admin_id) DO UPDATE SET 
                        rate = excluded.rate,
                        updated_at = datetime(\'now\')
                ');
                foreach ($namesToSave as $oname) {
                    $stmtRate->execute([
                        ':cid' => $customerId,
                        ':aid' => $ownerId,
                        ':office' => $oname,
                        ':rate' => $amount,
                    ]);
                }
            }
        } catch (Throwable $re) {
            // Silently continue if rate learning has non-fatal error
        }
    }

    app_audit_log($actionType, 'task', $taskId, [
        'customer_id' => $customerId,
        'task_name' => $taskName,
        'amount' => $amount,
    ]);
    echo json_encode([
        'ok' => true,
        'task_id' => $taskId,
        'updated' => ($actionType === 'update'),
    ]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Task creation failed']);
}
