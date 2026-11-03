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

app_ensure_sellers_table();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string)($_GET['action'] ?? ''));

try {
    if ($method === 'GET' || ($method === 'POST' && $action === 'list')) {
        $stmt = db()->prepare("
            SELECT id, admin_id, name, phone, service_type, notes, created_at
            FROM sellers
            ORDER BY name ASC, id DESC
        ");
        $stmt->execute();
        $sellers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'ok' => true,
            'sellers' => array_map(function ($s) {
                return [
                    'id' => (int)$s['id'],
                    'name' => (string)$s['name'],
                    'phone' => (string)$s['phone'],
                    'service_type' => (string)($s['service_type'] ?? ''),
                    'notes' => (string)($s['notes'] ?? ''),
                    'created_at' => (string)($s['created_at'] ?? ''),
                ];
            }, $sellers),
        ]);
        exit;
    }

    if ($method === 'POST') {
        $body = file_get_contents('php://input');
        $data = json_decode(is_string($body) ? $body : '', true) ?: [];
        if (empty($data)) {
            $data = $_POST;
        }

        $subAction = trim((string)($data['action'] ?? $action));

        if ($subAction === 'list') {
            $stmt = db()->prepare("
                SELECT id, admin_id, name, phone, service_type, notes, created_at
                FROM sellers
                ORDER BY name ASC, id DESC
            ");
            $stmt->execute();
            $sellers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            echo json_encode([
                'ok' => true,
                'sellers' => array_map(function ($s) {
                    return [
                        'id' => (int)$s['id'],
                        'name' => (string)$s['name'],
                        'phone' => (string)$s['phone'],
                        'service_type' => (string)($s['service_type'] ?? ''),
                        'notes' => (string)($s['notes'] ?? ''),
                        'created_at' => (string)($s['created_at'] ?? ''),
                    ];
                }, $sellers),
            ]);
            exit;
        }

        if ($subAction === 'delete') {
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) {
                app_ext_api_send_json(400, ['ok' => false, 'error' => 'Valid seller ID required']);
            }
            $stmt = db()->prepare("DELETE FROM sellers WHERE id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['ok' => true]);
            exit;
        }

        // Add or Edit seller
        $name = trim((string)($data['name'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $serviceType = trim((string)($data['service_type'] ?? ''));
        $notes = trim((string)($data['notes'] ?? ''));
        $sellerId = (int)($data['id'] ?? 0);

        if ($name === '' || $phone === '') {
            app_ext_api_send_json(400, ['ok' => false, 'error' => 'সেলার নাম এবং ফোন নম্বর আবশ্যক']);
        }

        if ($sellerId > 0) {
            $stmt = db()->prepare("
                UPDATE sellers
                SET name = :name, phone = :phone, service_type = :st, notes = :notes
                WHERE id = :id
            ");
            $stmt->execute([
                ':name' => $name,
                ':phone' => $phone,
                ':st' => $serviceType,
                ':notes' => $notes,
                ':id' => $sellerId,
            ]);
            echo json_encode([
                'ok' => true,
                'seller' => [
                    'id' => $sellerId,
                    'name' => $name,
                    'phone' => $phone,
                    'service_type' => $serviceType,
                    'notes' => $notes,
                ],
            ]);
            exit;
        }

        // Insert new seller
        $stmt = db()->prepare("
            INSERT INTO sellers (admin_id, name, phone, service_type, notes)
            VALUES (:aid, :name, :phone, :st, :notes)
        ");
        $stmt->execute([
            ':aid' => $aid,
            ':name' => $name,
            ':phone' => $phone,
            ':st' => $serviceType,
            ':notes' => $notes,
        ]);
        $newId = (int)db()->lastInsertId();

        echo json_encode([
            'ok' => true,
            'seller' => [
                'id' => $newId,
                'name' => $name,
                'phone' => $phone,
                'service_type' => $serviceType,
                'notes' => $notes,
            ],
        ]);
        exit;
    }

    app_ext_api_send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

