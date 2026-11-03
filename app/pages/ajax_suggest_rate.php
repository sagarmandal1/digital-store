<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
app_ext_api_allow_cors();

if (!app_db_initialized()) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Database not initialized']);
}

$tokenAdminId = app_ext_api_require_token_admin_id();
$ownerId = app_ext_api_scope_admin_id($tokenAdminId);
$ownerId = max(0, $ownerId);

app_ensure_customer_office_rates_table();
app_ensure_customer_tasks_table();

$customerId = (int)($_GET['customer_id'] ?? 0);
$address = trim((string)($_GET['address'] ?? $_GET['office_address'] ?? ''));

$officeInfo = app_extract_office_info($address);
$fullName = $officeInfo['name'];
$cityCorp = $officeInfo['city_corp'];
$zone = $officeInfo['zone'];
$zoneLabel = $officeInfo['zone_label'];
$area = $officeInfo['area'];
$keys = $officeInfo['keys'] ?? [];

$suggestedRate = 0.0;
$matchType = 'none';
$reason = '';

try {
    // 1. Check customer_office_rates for THIS customer (Zone-first matching)
    if ($customerId > 0 && $ownerId > 0 && !empty($keys)) {
        $inClause = implode(',', array_fill(0, count($keys), '?'));
        $stmt = db()->prepare("
            SELECT rate, office_name FROM customer_office_rates
            WHERE customer_id = ? AND admin_id = ? AND office_name IN ($inClause)
            ORDER BY updated_at DESC
        ");
        $stmt->execute(array_merge([$customerId, $ownerId], $keys));
        $allRows = $stmt->fetchAll() ?: [];
        foreach ($keys as $k) {
            foreach ($allRows as $r) {
                if ($r['office_name'] === $k && to_float($r['rate']) > 0) {
                    $suggestedRate = to_float($r['rate']);
                    $matchType = 'customer_zone';
                    $reason = 'এই কাস্টমারের পূর্বের রেট: ' . $r['office_name'];
                    break 2;
                }
            }
        }
    }

    // 2. Check customer_tasks history for THIS customer (Zone-first)
    if ($suggestedRate <= 0 && $customerId > 0 && !empty($keys)) {
        foreach ($keys as $k) {
            $stmt = db()->prepare('
                SELECT amount FROM customer_tasks
                WHERE customer_id = :cid AND (admin_id = :aid OR :aid = 0)
                  AND notes LIKE :tgt
                ORDER BY id DESC LIMIT 1
            ');
            $stmt->execute([':cid' => $customerId, ':aid' => $ownerId, ':tgt' => '%' . $k . '%']);
            $amt = to_float($stmt->fetchColumn() ?: 0);
            if ($amt > 0) {
                $suggestedRate = $amt;
                $matchType = 'customer_history';
                $reason = 'এই কাস্টমারের পূর্বের কাজের রেট: ' . $k;
                break;
            }
        }
    }

    // 3. Check customer_office_rates for ANY customer in this store (Zone-first)
    if ($suggestedRate <= 0 && $ownerId > 0 && !empty($keys)) {
        $inClause = implode(',', array_fill(0, count($keys), '?'));
        $stmt = db()->prepare("
            SELECT rate, office_name FROM customer_office_rates
            WHERE admin_id = ? AND office_name IN ($inClause)
            ORDER BY updated_at DESC
        ");
        $stmt->execute(array_merge([$ownerId], $keys));
        $allRows = $stmt->fetchAll() ?: [];
        foreach ($keys as $k) {
            foreach ($allRows as $r) {
                if ($r['office_name'] === $k && to_float($r['rate']) > 0) {
                    $suggestedRate = to_float($r['rate']);
                    $matchType = 'store_zone';
                    $reason = 'এই জোনের সাধারণ রেট: ' . $r['office_name'];
                    break 2;
                }
            }
        }
    }

    // 4. Check customer_tasks for ANY customer in this store (Zone-first)
    if ($suggestedRate <= 0 && !empty($keys)) {
        foreach ($keys as $k) {
            $stmt = db()->prepare('
                SELECT amount FROM customer_tasks
                WHERE (admin_id = :aid OR :aid = 0)
                  AND notes LIKE :tgt
                ORDER BY id DESC LIMIT 1
            ');
            $stmt->execute([':aid' => $ownerId, ':tgt' => '%' . $k . '%']);
            $amt = to_float($stmt->fetchColumn() ?: 0);
            if ($amt > 0) {
                $suggestedRate = $amt;
                $matchType = 'store_history';
                $reason = 'এই জোনের পূর্বের কাজের রেট: ' . $k;
                break;
            }
        }
    }

    // 5. Fallback to product default sell_price
    if ($suggestedRate <= 0) {
        $pKeyword = 'New Birth';
        if ($cityCorp !== '' && str_contains($cityCorp, 'চট্টগ্রাম')) {
            $pKeyword = 'CTG';
        }
        $stmt = db()->prepare('SELECT sell_price FROM products WHERE (name LIKE :q OR sku LIKE :q) AND (admin_id = :aid OR :aid = 0) ORDER BY id ASC LIMIT 1');
        $stmt->execute([':q' => '%' . $pKeyword . '%', ':aid' => $ownerId]);
        $pPrice = to_float($stmt->fetchColumn() ?: 0);
        $suggestedRate = $pPrice > 0 ? $pPrice : 650.0;
        $matchType = 'default';
        $reason = 'ডিফল্ট প্রোডাক্ট রেট';
    }

    echo json_encode([
        'ok' => true,
        'suggested_rate' => $suggestedRate,
        'office_name' => $fullName,
        'zone' => $zone,
        'zone_label' => $zoneLabel,
        'city_corp' => $cityCorp,
        'area' => $area,
        'match_type' => $matchType,
        'reason' => $reason,
        'chips' => [500, 600, 650, 700, 750, 800, 1100],
    ]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Suggest rate failed: ' . $e->getMessage()]);
}
