<?php

declare(strict_types=1);

$APP_CONFIG = require __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';

date_default_timezone_set((string)$APP_CONFIG['timezone']);

session_name((string)$APP_CONFIG['session_name']);
session_start([
    'use_strict_mode' => true,
    'cookie_httponly' => true,
    'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'cookie_samesite' => 'Lax',
]);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'format.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'flash.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'csrf.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'auth.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'whatsapp.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'mail.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'rate_limit.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'audit.php';

function app_config(string $key, $default = null)
{
    global $APP_CONFIG;
    return $APP_CONFIG[$key] ?? $default;
}

function app_db_path(): string
{
    $config = require __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
    return (string)$config['db']['path'];
}

function app_db_initialized(): bool
{
    $path = app_db_path();
    return is_file($path) && filesize($path) > 0;
}

function app_migrate_settings_table(): void
{
    if (!app_db_initialized()) {
        return;
    }

    try {
        $pdo = db();
        $cols = $pdo->query("PRAGMA table_info(settings)")->fetchAll(PDO::FETCH_ASSOC);
        if (!$cols) {
            return;
        }

        $pkCols = [];
        foreach ($cols as $c) {
            if ((int)($c['pk'] ?? 0) > 0) {
                $pkCols[] = (string)($c['name'] ?? '');
            }
        }

        if ($pkCols === ['key', 'admin_id'] || $pkCols === ['admin_id', 'key']) {
            return;
        }

        $pdo->beginTransaction();
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings__new (
            key TEXT NOT NULL,
            value TEXT NOT NULL,
            admin_id INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (key, admin_id)
        )");
        $pdo->exec("INSERT OR IGNORE INTO settings__new (key, value, admin_id) SELECT \"key\", value, COALESCE(admin_id, 0) FROM settings");
        $pdo->exec("DROP TABLE settings");
        $pdo->exec("ALTER TABLE settings__new RENAME TO settings");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_settings_admin ON settings(admin_id)");
        $pdo->commit();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

app_migrate_settings_table();

function app_setting(string $key, string $default = ''): string
{
    try {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $stmt = db()->prepare('SELECT value FROM settings WHERE key = :key AND admin_id = :aid LIMIT 1');
        $stmt->execute([':key' => $key, ':aid' => $adminId]);
        $value = $stmt->fetchColumn();
        if ($value !== false) {
            return (string)$value;
        }
        if ($adminId !== 0) {
            $stmt->execute([':key' => $key, ':aid' => 0]);
            $value = $stmt->fetchColumn();
            if ($value !== false) {
                return (string)$value;
            }
        }
        return $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function app_setting_for_admin_id(int $adminId, string $key, string $default = ''): string
{
    try {
        $aid = max(0, $adminId);
        $stmt = db()->prepare('SELECT value FROM settings WHERE key = :key AND admin_id = :aid LIMIT 1');
        $stmt->execute([':key' => $key, ':aid' => $aid]);
        $value = $stmt->fetchColumn();
        if ($value !== false) {
            return (string)$value;
        }
        if ($aid !== 0) {
            $stmt->execute([':key' => $key, ':aid' => 0]);
            $value = $stmt->fetchColumn();
            if ($value !== false) {
                return (string)$value;
            }
        }
        return $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function app_ext_api_send_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function app_ext_api_allow_cors(): void
{
    $allowedOrigins = [
        'https://web.whatsapp.com',
        'http://localhost',
        'http://localhost:8000',
        'http://127.0.0.1:8000',
    ];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    } else {
        header('Access-Control-Allow-Origin: https://web.whatsapp.com');
    }
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-DS-Token');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Max-Age: 600');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function app_ext_api_read_token(): string
{
    $hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($hdr === '' && function_exists('getallheaders')) {
        $all = getallheaders();
        if (is_array($all)) {
            foreach ($all as $k => $v) {
                if (is_string($k) && is_string($v) && strtolower($k) === 'authorization') {
                    $hdr = $v;
                    break;
                }
            }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($hdr), $m)) {
        return trim((string)($m[1] ?? ''));
    }
    $x = (string)($_SERVER['HTTP_X_DS_TOKEN'] ?? '');
    if ($x !== '') {
        return trim($x);
    }
    return '';
}

function app_ext_api_token_admin_id(): int
{
    if (!app_db_initialized()) {
        return 0;
    }
    $token = app_ext_api_read_token();
    if ($token === '') {
        return 0;
    }
    try {
        $stmt = db()->prepare("
            SELECT s.admin_id, s.value AS token_hash
            FROM settings s
            JOIN admins a ON a.id = s.admin_id
            WHERE s.key = 'ext_api_token_hash' AND COALESCE(a.active, 1) = 1
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as $r) {
            $hash = (string)($r['token_hash'] ?? '');
            if ($hash !== '' && password_verify($token, $hash)) {
                return (int)($r['admin_id'] ?? 0);
            }
        }
        return 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function app_ext_api_scope_admin_id(int $tokenAdminId): int
{
    if ($tokenAdminId !== 1) {
        return $tokenAdminId;
    }
    return max(0, (int)($_GET['scope_admin_id'] ?? 0));
}

function app_ext_api_require_token_admin_id(): int
{
    $rl = app_rate_limit_allow(app_rate_limit_key('ext_api_auth'), 60, 300);
    if (!($rl['ok'] ?? true)) {
        app_ext_api_send_json(429, [
            'ok' => false,
            'error' => 'Too many API auth attempts',
            'retry_after' => (int)($rl['retry_after'] ?? 0),
        ]);
    }

    $adminId = app_ext_api_token_admin_id();
    if ($adminId <= 0) {
        app_ext_api_send_json(401, ['ok' => false, 'error' => 'Unauthorized']);
    }
    return $adminId;
}

function app_ext_api_ensure_customer_notes_table(): void
{
    if (!app_db_initialized()) {
        return;
    }
    try {
        db()->exec('CREATE TABLE IF NOT EXISTS customer_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL,
            note TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            admin_id INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        )');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_customer_notes_customer ON customer_notes(customer_id)');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_customer_notes_admin ON customer_notes(admin_id)');
    } catch (Throwable $e) {
        return;
    }
}

function app_ensure_customer_tasks_table(): void
{
    if (!app_db_initialized()) {
        return;
    }
    try {
        db()->exec('CREATE TABLE IF NOT EXISTS customer_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL,
            admin_id INTEGER NOT NULL DEFAULT 0,
            task_name TEXT NOT NULL,
            notes TEXT DEFAULT \'\',
            amount REAL NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT \'pending\',
            sale_id INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            delivered_at TEXT DEFAULT NULL,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        )');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_customer_tasks_customer ON customer_tasks(customer_id)');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_customer_tasks_admin ON customer_tasks(admin_id)');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_customer_tasks_status ON customer_tasks(status)');
    } catch (Throwable $e) {
        return;
    }
}

function app_ensure_customer_office_rates_table(): void
{
    if (!app_db_initialized()) {
        return;
    }
    try {
        db()->exec('CREATE TABLE IF NOT EXISTS customer_office_rates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL,
            admin_id INTEGER NOT NULL DEFAULT 0,
            office_name TEXT NOT NULL,
            rate REAL NOT NULL,
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE(customer_id, office_name, admin_id),
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        )');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_cust_office_rates ON customer_office_rates(customer_id, office_name, admin_id)');
    } catch (Throwable $e) {
        return;
    }
}

function app_extract_office_info(string $address): array
{
    $address = trim($address);
    if ($address === '') {
        return ['name' => 'Default', 'city_corp' => '', 'zone' => '', 'area' => ''];
    }

    $cityCorps = [
        'ঢাকা উত্তর সিটি কর্পোরেশন',
        'ঢাকা দক্ষিণ সিটি কর্পোরেশন',
        'চট্টগ্রাম সিটি কর্পোরেশন',
        'গাজীপুর সিটি কর্পোরেশন',
        'নারায়ণগঞ্জ সিটি কর্পোরেশন',
        'রাজশাহী সিটি কর্পোরেশন',
        'খুলনা সিটি কর্পোরেশন',
        'বরিশাল সিটি কর্পোরেশন',
        'সিলেট সিটি কর্পোরেশন',
        'রংপুর সিটি কর্পোরেশন',
        'কুমিল্লা সিটি কর্পোরেশন',
        'ময়মনসিংহ সিটি কর্পোরেশন',
    ];

    $matchedCc = '';
    foreach ($cityCorps as $cc) {
        if (str_contains($address, $cc)) {
            $matchedCc = $cc;
            break;
        }
    }
    if ($matchedCc === '') {
        if (str_contains($address, 'ঢাকা উত্তর')) {
            $matchedCc = 'ঢাকা উত্তর সিটি কর্পোরেশন';
        } elseif (str_contains($address, 'ঢাকা দক্ষিণ')) {
            $matchedCc = 'ঢাকা দক্ষিণ সিটি কর্পোরেশন';
        }
    }

    $zone = '';
    if (preg_match('/(অঞ্চল\s*[-–:]?\s*[০-৯0-9]+)/u', $address, $zm)) {
        $zone = preg_replace('/\s+/', '', $zm[1]);
    }

    $area = '';
    $areaKeywords = ['বাসাবো', 'বাড্ডা', 'আফতাব নগর', 'সবুজবাগ', 'মিরপুর', 'উত্তরা', 'মোহাম্মদপুর', 'গুলশান', 'ধানমন্ডি', 'যাত্রাবাড়ী', 'মতিঝিল', 'খিলগাঁও', 'রামপুরা', 'বনশ্রী'];
    foreach ($areaKeywords as $ak) {
        if (str_contains($address, $ak)) {
            $area = $ak;
            break;
        }
    }

    if ($matchedCc !== '') {
        $sub = [];
        if ($zone !== '') {
            $sub[] = $zone;
        }
        if ($area !== '') {
            $sub[] = $area;
        }
        $fullName = $matchedCc . ($sub ? ' (' . implode(', ', $sub) . ')' : '');
        return [
            'name' => $fullName,
            'city_corp' => $matchedCc,
            'zone' => $zone,
            'area' => $area,
        ];
    }

    $districts = ['বান্দরবান', 'কক্সবাজার', 'চট্টগ্রাম', 'সিলেট', 'ব্রাহ্মণবাড়িয়া', 'নোয়াখালী', 'কুমিল্লা', 'ময়মনসিংহ', 'রংপুর', 'রাজশাহী', 'খুলনা', 'বরিশাল'];
    foreach ($districts as $d) {
        if (str_contains($address, $d)) {
            return [
                'name' => $d,
                'city_corp' => '',
                'zone' => '',
                'area' => $d,
            ];
        }
    }

    if (str_contains($address, 'পৌরসভা')) {
        return ['name' => 'পৌরসভা', 'city_corp' => '', 'zone' => '', 'area' => ''];
    }
    if (str_contains($address, 'ইউনিয়ন')) {
        return ['name' => 'ইউনিয়ন পরিষদ', 'city_corp' => '', 'zone' => '', 'area' => ''];
    }

    return ['name' => substr($address, 0, 40), 'city_corp' => '', 'zone' => '', 'area' => ''];
}

/**
 * Configurable service catalogue for document-service businesses.
 * These tables intentionally do not alter legacy sales or task records.
 */
function app_ensure_service_catalog_tables(): void
{
    if (!app_db_initialized()) {
        return;
    }

    try {
        $pdo = db();
        $pdo->exec('CREATE TABLE IF NOT EXISTS service_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER NOT NULL DEFAULT 0,
            name TEXT NOT NULL,
            default_price REAL NOT NULL DEFAULT 0,
            document_checklist TEXT NOT NULL DEFAULT \'\',
            estimated_days INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_service_types_admin ON service_types(admin_id)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS workflow_statuses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER NOT NULL DEFAULT 0,
            name TEXT NOT NULL,
            color TEXT NOT NULL DEFAULT \'secondary\',
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_final INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE(admin_id, name)
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_workflow_statuses_admin_sort ON workflow_statuses(admin_id, sort_order)');
    } catch (Throwable $e) {
        return;
    }
}

function app_is_super_admin(): bool
{
    return (int)($_SESSION['admin_id'] ?? 0) === 1;
}

function app_scope_admin_id(): int
{
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    if ($adminId <= 0) {
        return 0;
    }
    if (!app_is_super_admin()) {
        return $adminId;
    }
    return (int)($_SESSION['scope_admin_id'] ?? 0);
}

function app_migrate_add_column(string $table, string $column, string $definition): void
{
    try {
        $pdo = db();
        $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
        if (!$cols) {
            return;
        }
        foreach ($cols as $c) {
            if ((string)($c['name'] ?? '') === $column) {
                return;
            }
        }
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    } catch (Throwable $e) {
        return;
    }
}

function app_migrate_multi_user_schema(): void
{
    if (!app_db_initialized()) {
        return;
    }

    app_migrate_add_column('customers', 'admin_id', "INTEGER NOT NULL DEFAULT 0");
    app_migrate_add_column('customers', 'category', "TEXT DEFAULT 'Regular'");
    app_migrate_add_column('products', 'admin_id', "INTEGER NOT NULL DEFAULT 0");
    app_migrate_add_column('sales', 'admin_id', "INTEGER NOT NULL DEFAULT 0");
    app_migrate_add_column('payments', 'admin_id', "INTEGER NOT NULL DEFAULT 0");
    app_migrate_add_column('expenses', 'admin_id', "INTEGER NOT NULL DEFAULT 0");
    app_migrate_add_column('customer_product_prices', 'admin_id', "INTEGER NOT NULL DEFAULT 0");
}

app_migrate_multi_user_schema();
app_ensure_service_catalog_tables();

function app_migrate_admin_roles(): void
{
    if (!app_db_initialized()) {
        return;
    }

    app_migrate_add_column('admins', 'role', "TEXT NOT NULL DEFAULT 'user'");
    try {
        $pdo = db();
        $pdo->exec("UPDATE admins SET role='user' WHERE role IS NULL OR role=''");
        $pdo->exec("UPDATE admins SET role='super_admin' WHERE id=1");
    } catch (Throwable $e) {
        return;
    }
}

app_migrate_admin_roles();

function app_migrate_admin_active(): void
{
    if (!app_db_initialized()) {
        return;
    }

    app_migrate_add_column('admins', 'active', "INTEGER NOT NULL DEFAULT 1");
    try {
        db()->exec("UPDATE admins SET active = 1 WHERE active IS NULL");
        db()->exec("UPDATE admins SET active = 1 WHERE id = 1");
    } catch (Throwable $e) {
        return;
    }
}

app_migrate_admin_active();

function app_require_user_scope_for_write(): int
{
    $sessionAdminId = (int)($_SESSION['admin_id'] ?? 0);
    if ($sessionAdminId <= 0) {
        header('Location: index.php?page=login');
        exit;
    }

    if (app_is_super_admin()) {
        $scopeId = app_scope_admin_id();
        if ($scopeId <= 0) {
            flash_set('danger', 'Select a user from the top-right menu to manage data.');
            header('Location: index.php?page=dashboard');
            exit;
        }
        return $scopeId;
    }

    return $sessionAdminId;
}

function app_can_write(): bool
{
    if (!auth_is_logged_in()) {
        return false;
    }
    if (!app_is_super_admin()) {
        return true;
    }
    return app_scope_admin_id() > 0;
}
