<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$config = require $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
$dbPath = (string)$config['db']['path'];

$dir = dirname($dbPath);
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => true,
]);

$pdo->exec('PRAGMA foreign_keys = ON;');
$pdo->exec('PRAGMA journal_mode = WAL;');

$schemaSql = <<<SQL
CREATE TABLE IF NOT EXISTS admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT,
    phone TEXT,
    address TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    category TEXT DEFAULT 'Regular',
    admin_id INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    sku TEXT,
    cost_price REAL NOT NULL DEFAULT 0,
    sell_price REAL NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    admin_id INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS customer_product_prices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    custom_sell_price REAL NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    admin_id INTEGER DEFAULT 0,
    UNIQUE(customer_id, product_id, admin_id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_no TEXT NOT NULL UNIQUE,
    customer_id INTEGER NOT NULL,
    sale_date TEXT NOT NULL,
    items_json TEXT NOT NULL,
    total_cost REAL NOT NULL DEFAULT 0,
    total_sell REAL NOT NULL DEFAULT 0,
    total_profit REAL NOT NULL DEFAULT 0,
    paid_amount REAL NOT NULL DEFAULT 0,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    admin_id INTEGER DEFAULT 0,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL,
    payment_date TEXT NOT NULL,
    amount REAL NOT NULL DEFAULT 0,
    method TEXT,
    note TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    admin_id INTEGER DEFAULT 0,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    expense_date DATE NOT NULL,
    category TEXT NOT NULL,
    amount REAL NOT NULL,
    note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    admin_id INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT NOT NULL,
    value TEXT NOT NULL,
    admin_id INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (key, admin_id)
);

CREATE INDEX IF NOT EXISTS idx_sales_date ON sales(sale_date);
CREATE INDEX IF NOT EXISTS idx_sales_customer ON sales(customer_id);
CREATE INDEX IF NOT EXISTS idx_payments_date ON payments(payment_date);
CREATE INDEX IF NOT EXISTS idx_payments_sale ON payments(sale_id);
CREATE INDEX IF NOT EXISTS idx_customers_admin ON customers(admin_id);
CREATE INDEX IF NOT EXISTS idx_products_admin ON products(admin_id);
CREATE INDEX IF NOT EXISTS idx_sales_admin ON sales(admin_id);
CREATE INDEX IF NOT EXISTS idx_expenses_admin ON expenses(admin_id);
SQL;

$pdo->exec($schemaSql);

$pdo->beginTransaction();
try {
    $adminEmail = 'admin@example.com';
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE email = :email');
    $stmt->execute([':email' => $adminEmail]);
    $exists = (int)$stmt->fetchColumn() > 0;
    if (!$exists) {
        $stmt = $pdo->prepare("INSERT INTO admins (name, email, password_hash, role) VALUES (:name, :email, :password_hash, 'super_admin')");
        $stmt->execute([
            ':name' => 'Administrator',
            ':email' => $adminEmail,
            ':password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
        ]);
    }

    $adminId = (int)$pdo->query("SELECT id FROM admins WHERE email='{$adminEmail}' LIMIT 1")->fetchColumn();
    if ($adminId <= 0) {
        $adminId = 1;
    }

    $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value, admin_id) VALUES (:key, :value, :aid)");
    $stmt->execute([':key' => 'company_name', ':value' => 'My Digital Store', ':aid' => $adminId]);
    $stmt->execute([':key' => 'currency_symbol', ':value' => '$', ':aid' => $adminId]);
    $stmt->execute([':key' => 'whatsapp_base_url', ':value' => 'https://wafastapi.com', ':aid' => $adminId]);
    $stmt->execute([':key' => 'whatsapp_api_key', ':value' => '', ':aid' => $adminId]);
    $stmt->execute([':key' => 'whatsapp_ssl_verify', ':value' => '1', ':aid' => $adminId]);
    $stmt->execute([':key' => 'whatsapp_ca_bundle_path', ':value' => '', ':aid' => $adminId]);

    $stmt->execute([':key' => 'public_registration_enabled', ':value' => '1', ':aid' => 0]);
    $stmt->execute([':key' => 'public_registration_code', ':value' => '', ':aid' => 0]);

    $pdo->exec("INSERT INTO customers (name, email, phone, address, category, admin_id)
        SELECT 'Acme Corp', 'acme@example.com', '123456789', 'Dhaka', 'Regular', {$adminId} WHERE NOT EXISTS (SELECT 1 FROM customers WHERE email='acme@example.com' AND admin_id={$adminId})");
    $pdo->exec("INSERT INTO customers (name, email, phone, address, category, admin_id)
        SELECT 'John Doe', 'john@example.com', '987654321', 'Chattogram', 'Regular', {$adminId} WHERE NOT EXISTS (SELECT 1 FROM customers WHERE email='john@example.com' AND admin_id={$adminId})");

    $pdo->exec("INSERT INTO products (name, sku, cost_price, sell_price, active, admin_id)
        SELECT 'Canva Pro (1 month)', 'CANVA-1M', 5.00, 10.00, 1, {$adminId} WHERE NOT EXISTS (SELECT 1 FROM products WHERE sku='CANVA-1M' AND admin_id={$adminId})");
    $pdo->exec("INSERT INTO products (name, sku, cost_price, sell_price, active, admin_id)
        SELECT 'Netflix Premium (1 month)', 'NFLX-1M', 7.00, 12.00, 1, {$adminId} WHERE NOT EXISTS (SELECT 1 FROM products WHERE sku='NFLX-1M' AND admin_id={$adminId})");
    $pdo->exec("INSERT INTO products (name, sku, cost_price, sell_price, active, admin_id)
        SELECT 'ChatGPT Plus (1 month)', 'GPT-1M', 12.00, 20.00, 1, {$adminId} WHERE NOT EXISTS (SELECT 1 FROM products WHERE sku='GPT-1M' AND admin_id={$adminId})");

    $custId = (int)$pdo->query("SELECT id FROM customers WHERE email='acme@example.com' AND admin_id={$adminId} LIMIT 1")->fetchColumn();
    $prodId = (int)$pdo->query("SELECT id FROM products WHERE sku='CANVA-1M' AND admin_id={$adminId} LIMIT 1")->fetchColumn();
    if ($custId > 0 && $prodId > 0) {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO customer_product_prices (customer_id, product_id, custom_sell_price, admin_id) VALUES (:c, :p, :price, :aid)');
        $stmt->execute([':c' => $custId, ':p' => $prodId, ':price' => 9.00, ':aid' => $adminId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

echo "Initialized SQLite database at: {$dbPath}\n";
echo "Login: admin@example.com / admin123\n";
