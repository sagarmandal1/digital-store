<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
app_ext_api_allow_cors();

if (!app_db_initialized()) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Database not initialized']);
}

$tokenAdminId = app_ext_api_require_token_admin_id();
$ownerId = app_ext_api_scope_admin_id($tokenAdminId);
if ($ownerId <= 0) {
    app_ext_api_send_json(403, ['ok' => false, 'error' => 'Select a user scope']);
}

$pdo = db();

// Ensure quick_templates table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS quick_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER NOT NULL DEFAULT 0,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        shortcut TEXT DEFAULT '',
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_quick_templates_admin ON quick_templates(admin_id)");
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Table setup error']);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM quick_templates WHERE admin_id = :aid ORDER BY sort_order ASC, id ASC");
        $stmt->execute([':aid' => $ownerId]);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Auto-seed default templates if none exist
        if (empty($templates)) {
            $company = app_setting_for_admin_id($ownerId, 'company_name', (string)app_config('app_name', 'Digital Store'));
            $paymentInfo = trim(app_setting_for_admin_id($ownerId, 'payment_info', "bKash / Nagad (Personal): 01XXXXXXXXX\nটাকা পাঠিয়ে Last 4 digits বা TrxID দিন।"));

            $defaults = [
                [
                    'title' => '💳 Payment Details',
                    'shortcut' => 'pay',
                    'content' => "💳 *পেমেন্ট মেথড:*\n" . $paymentInfo,
                    'sort_order' => 1
                ],
                [
                    'title' => '📦 Account Delivery',
                    'shortcut' => 'del',
                    'content' => "🎉 *আপনার অর্ডার ডেলিভারি সম্পন্ন হয়েছে!*\n\n📦 *প্রোডাক্ট:* {product_name}\n📧 *ইমেইল/ইউজার:* {email}\n🔑 *পাসওয়ার্ড:* {password}\n📅 *মেয়াদ:* {expiry}\n\n⚠️ *নিয়মাবলী:* পাসওয়ার্ড বা প্রোফাইল কোনো কিছু পরিবর্তন করবেন না।\nযেকোনো সমস্যায় আমাদের সাথে যোগাযোগ করুন। ধন্যবাদ!",
                    'sort_order' => 2
                ],
                [
                    'title' => '⏰ Due Reminder',
                    'shortcut' => 'due',
                    'content' => "আসসালামু আলাইকুম {customer_name},\nআপনার পূর্বের অর্ডারের বকেয়া বিল *{due_amount}* টাকা পরিশোধের অনুরোধ জানাচ্ছি।\nবিল পরিশোধ করে অনুগ্রহ করে স্ক্রিনশট বা TrxID দিন। ধন্যবাদ,\n*{$company}*",
                    'sort_order' => 3
                ],
                [
                    'title' => '👋 Welcome & Catalog',
                    'shortcut' => 'hi',
                    'content' => "আসসালামু আলাইকুম! *{$company}*-এ আপনাকে স্বাগতম।\nআমাদের ডিজিটাল প্রোডাক্ট ও সাবস্ক্রিপশনসমূহ:\n• Canva Pro\n• Netflix Premium\n• ChatGPT Plus / Team\n• Office 365\n• Prime Video\nকোন প্রোডাক্টটি প্রয়োজন অনুগ্রহ করে জানান।",
                    'sort_order' => 4
                ],
                [
                    'title' => '✅ Payment Received',
                    'shortcut' => 'thanks',
                    'content' => "ধন্যবাদ {customer_name}!\nআপনার পেমেন্ট সফলভাবে গ্রহণ করা হয়েছে।\nআমাদের সাথে থাকার জন্য ধন্যবাদ। *{$company}*",
                    'sort_order' => 5
                ]
            ];

            $insertStmt = $pdo->prepare("INSERT INTO quick_templates (admin_id, title, content, shortcut, sort_order) VALUES (:aid, :title, :content, :shortcut, :sort_order)");
            foreach ($defaults as $d) {
                $insertStmt->execute([
                    ':aid' => $ownerId,
                    ':title' => $d['title'],
                    ':content' => $d['content'],
                    ':shortcut' => $d['shortcut'],
                    ':sort_order' => $d['sort_order'],
                ]);
            }

            $stmt->execute([':aid' => $ownerId]);
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        app_ext_api_send_json(200, ['ok' => true, 'templates' => $templates]);
    } catch (Throwable $e) {
        app_ext_api_send_json(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($method === 'POST') {
    $body = file_get_contents('php://input');
    $data = json_decode(is_string($body) ? $body : '', true) ?: [];
    $action = (string)($data['action'] ?? 'save');
    $id = (int)($data['id'] ?? 0);

    if ($action === 'delete') {
        if ($id <= 0) {
            app_ext_api_send_json(400, ['ok' => false, 'error' => 'id required']);
        }
        $stmt = $pdo->prepare("DELETE FROM quick_templates WHERE id = :id AND admin_id = :aid");
        $stmt->execute([':id' => $id, ':aid' => $ownerId]);
        app_ext_api_send_json(200, ['ok' => true, 'message' => 'Deleted']);
    }

    $title = trim((string)($data['title'] ?? ''));
    $content = trim((string)($data['content'] ?? ''));
    $shortcut = trim((string)($data['shortcut'] ?? ''));
    $sortOrder = (int)($data['sort_order'] ?? 0);

    if ($title === '' || $content === '') {
        app_ext_api_send_json(400, ['ok' => false, 'error' => 'Title and content required']);
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE quick_templates SET title = :title, content = :content, shortcut = :shortcut, sort_order = :so WHERE id = :id AND admin_id = :aid");
            $stmt->execute([
                ':title' => $title,
                ':content' => $content,
                ':shortcut' => $shortcut,
                ':so' => $sortOrder,
                ':id' => $id,
                ':aid' => $ownerId
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO quick_templates (admin_id, title, content, shortcut, sort_order) VALUES (:aid, :title, :content, :shortcut, :so)");
            $stmt->execute([
                ':aid' => $ownerId,
                ':title' => $title,
                ':content' => $content,
                ':shortcut' => $shortcut,
                ':so' => $sortOrder
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        app_ext_api_send_json(200, ['ok' => true, 'id' => $id, 'message' => 'Saved']);
    } catch (Throwable $e) {
        app_ext_api_send_json(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

app_ext_api_send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
