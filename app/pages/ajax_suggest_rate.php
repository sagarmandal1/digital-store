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
$area = $officeInfo['area'];

$suggestedRate = 0.0;
$matchType = 'none';
$reason = '';

try {
    // 1. Check customer_office_rates for THIS customer
    if ($customerId > 0 && $ownerId > 0) {
        $stmt = db()->prepare('
            SELECT rate, office_name FROM customer_office_rates
            WHERE customer_id = :cid AND admin_id = :aid
              AND (office_name = :full OR office_name = :cc OR office_name = :area)
            ORDER BY (CASE WHEN office_name = :full THEN 1 WHEN office_name = :cc THEN 2 ELSE 3 END), updated_at DESC
            LIMIT 1
        ');
        $stmt->execute([
            ':cid' => $customerId,
            ':aid' => $ownerId,
            ':full' => $fullName,
            ':cc' => $cityCorp,
            ':area' => $area,
        ]);
        $row = $stmt->fetch();
        if ($row && to_float($row['rate']) > 0) {
            $suggestedRate = to_float($row['rate']);
            $matchType = 'customer_office';
            $reason = 'এই কাস্টমারের পূর্বের রেট: ' . $row['office_name'];
        }
    }

    // 2. Check customer_tasks for THIS customer
    if ($suggestedRate <= 0 && $customerId > 0) {
        $targets = array_unique(array_filter([$cityCorp, $area, $fullName]));
        foreach ($targets as $tgt) {
            if ($tgt === '') {
                continue;
            }
            $stmt = db()->prepare('
                SELECT amount FROM customer_tasks
                WHERE customer_id = :cid AND (admin_id = :aid OR :aid = 0)
                  AND notes LIKE :tgt
                ORDER BY id DESC LIMIT 1
            ');
            $stmt->execute([':cid' => $customerId, ':aid' => $ownerId, ':tgt' => '%' . $tgt . '%']);
            $amt = to_float($stmt->fetchColumn() ?: 0);
            if ($amt > 0) {
                $suggestedRate = $amt;
                $matchType = 'customer_history';
                $reason = 'এই কাস্টমারের পূর্বের কাজের রেট: ' . $tgt;
                break;
            }
        }
    }

    // 3. Check customer_office_rates for ANY customer in this store
    if ($suggestedRate <= 0 && $ownerId > 0) {
        $stmt = db()->prepare('
            SELECT rate, office_name FROM customer_office_rates
            WHERE admin_id = :aid
              AND (office_name = :full OR office_name = :cc OR office_name = :area)
            ORDER BY (CASE WHEN office_name = :full THEN 1 WHEN office_name = :cc THEN 2 ELSE 3 END), updated_at DESC
            LIMIT 1
        ');
        $stmt->execute([
            ':aid' => $ownerId,
            ':full' => $fullName,
            ':cc' => $cityCorp,
            ':area' => $area,
        ]);
        $row = $stmt->fetch();
        if ($row && to_float($row['rate']) > 0) {
            $suggestedRate = to_float($row['rate']);
            $matchType = 'store_office';
            $reason = 'অফিসের সাধারণ রেট: ' . $row['office_name'];
        }
    }

    // 4. Check customer_tasks for ANY customer in this store
    if ($suggestedRate <= 0) {
        $targets = array_unique(array_filter([$cityCorp, $area]));
        foreach ($targets as $tgt) {
            if ($tgt === '') {
                continue;
            }
            $stmt = db()->prepare('
                SELECT amount FROM customer_tasks
                WHERE (admin_id = :aid OR :aid = 0)
                  AND notes LIKE :tgt
                ORDER BY id DESC LIMIT 1
            ');
            $stmt->execute([':aid' => $ownerId, ':tgt' => '%' . $tgt . '%']);
            $amt = to_float($stmt->fetchColumn() ?: 0);
            if ($amt > 0) {
                $suggestedRate = $amt;
                $matchType = 'store_history';
                $reason = 'এই অফিসের সর্বশেষ রেট: ' . $tgt;
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
        'city_corp' => $cityCorp,
        'area' => $area,
        'match_type' => $matchType,
        'reason' => $reason,
        'chips' => [500, 600, 650, 700, 750, 800, 1100],
    ]);
} catch (Throwable $e) {
    app_ext_api_send_json(500, ['ok' => false, 'error' => 'Suggest rate failed: ' . $e->getMessage()]);
}
