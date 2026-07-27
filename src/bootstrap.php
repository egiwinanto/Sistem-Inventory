<?php

declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('STOCKBITE_V131_SESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
date_default_timezone_set('Asia/Jakarta');

const APP_NAME = 'StockBite Inventory';
const APP_VERSION = '1.3.1';
const DB_PATH = __DIR__ . '/../storage/stockbite.sqlite';

if (!extension_loaded('pdo_sqlite')) {
    http_response_code(500);
    exit('Ekstensi PDO SQLite belum aktif. Aktifkan extension=pdo_sqlite di php.ini, lalu restart Apache.');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $storage = dirname(DB_PATH);
    if (!is_dir($storage)) {
        mkdir($storage, 0775, true);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    initialize_database($pdo);
    return $pdo;
}

function initialize_database(PDO $pdo): void
{
    $schema = <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'staff',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS suppliers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    phone TEXT,
    email TEXT,
    address TEXT,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    category_id INTEGER,
    unit TEXT NOT NULL,
    min_stock REAL NOT NULL DEFAULT 0,
    current_stock REAL NOT NULL DEFAULT 0,
    average_cost REAL NOT NULL DEFAULT 0,
    location TEXT,
    expiry_tracking INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    transaction_no TEXT NOT NULL UNIQUE,
    type TEXT NOT NULL,
    transaction_date TEXT NOT NULL,
    supplier_id INTEGER,
    reference TEXT,
    notes TEXT,
    user_id INTEGER NOT NULL,
    total_value REAL NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS transaction_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    transaction_id INTEGER NOT NULL,
    item_id INTEGER NOT NULL,
    quantity REAL NOT NULL,
    unit_cost REAL NOT NULL DEFAULT 0,
    batch_no TEXT,
    expiry_date TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS menus (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    selling_price REAL NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

CREATE TABLE IF NOT EXISTS recipes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    menu_id INTEGER NOT NULL,
    item_id INTEGER NOT NULL,
    quantity REAL NOT NULL,
    FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT,
    UNIQUE(menu_id, item_id)
);

CREATE TABLE IF NOT EXISTS sales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_no TEXT NOT NULL UNIQUE,
    sold_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    customer_name TEXT,
    order_type TEXT NOT NULL DEFAULT 'TAKEAWAY',
    payment_method TEXT NOT NULL DEFAULT 'CASH',
    subtotal REAL NOT NULL DEFAULT 0,
    discount REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    amount_paid REAL NOT NULL DEFAULT 0,
    change_amount REAL NOT NULL DEFAULT 0,
    user_id INTEGER NOT NULL,
    transaction_id INTEGER,
    notes TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS sale_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL,
    menu_id INTEGER NOT NULL,
    quantity REAL NOT NULL,
    unit_price REAL NOT NULL,
    line_total REAL NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
);

CREATE INDEX IF NOT EXISTS idx_items_category ON items(category_id);
CREATE INDEX IF NOT EXISTS idx_transactions_date ON transactions(transaction_date);
CREATE INDEX IF NOT EXISTS idx_transactions_type ON transactions(type);
CREATE INDEX IF NOT EXISTS idx_transaction_items_item ON transaction_items(item_id);
CREATE INDEX IF NOT EXISTS idx_transaction_items_expiry ON transaction_items(expiry_date);
CREATE INDEX IF NOT EXISTS idx_sales_sold_at ON sales(sold_at);
CREATE INDEX IF NOT EXISTS idx_sales_user ON sales(user_id);
CREATE INDEX IF NOT EXISTS idx_sale_items_sale ON sale_items(sale_id);
SQL;

    $pdo->exec($schema);

    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount === 0) {
        $stmt = $pdo->prepare('INSERT INTO users(name,email,password,role) VALUES(?,?,?,?)');
        $stmt->execute(['Administrator', 'admin@stockbite.local', password_hash('password', PASSWORD_DEFAULT), 'owner']);
        $stmt->execute(['Staff Kasir', 'staff@stockbite.local', password_hash('password', PASSWORD_DEFAULT), 'staff']);
    }

    $pdo->exec("UPDATE users SET name='Staff Kasir' WHERE email='staff@stockbite.local' AND name='Staff Gudang'");

    $categoryCount = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($categoryCount === 0) {
        foreach (['Bahan Utama', 'Bumbu & Saus', 'Minuman', 'Kemasan', 'Bahan Pendukung'] as $name) {
            $stmt = $pdo->prepare('INSERT INTO categories(name) VALUES(?)');
            $stmt->execute([$name]);
        }
    }

    $supplierCount = (int) $pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn();
    if ($supplierCount === 0) {
        $stmt = $pdo->prepare('INSERT INTO suppliers(name,phone,email,address,notes) VALUES(?,?,?,?,?)');
        $stmt->execute(['PT Sumber Pangan', '0812-1111-2222', 'sales@sumberpangan.test', 'Jakarta', 'Pemasok bahan utama']);
        $stmt->execute(['CV Kemasan Jaya', '0813-3333-4444', 'order@kemasanjaya.test', 'Bandung', 'Pemasok kemasan']);
    }

    $itemCount = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    if ($itemCount === 0) {
        $categories = $pdo->query('SELECT id,name FROM categories')->fetchAll();
        $catMap = [];
        foreach ($categories as $category) {
            $catMap[$category['name']] = (int) $category['id'];
        }
        $items = [
            ['BHN-001', 'Daging Sapi Slice', 'Bahan Utama', 'kg', 10, 35, 115000, 'Freezer A', 1],
            ['BHN-002', 'Tortilla 20 cm', 'Bahan Utama', 'pcs', 50, 180, 2500, 'Rak Kering A', 1],
            ['BHN-003', 'Selada Segar', 'Bahan Utama', 'kg', 3, 7, 26000, 'Chiller A', 1],
            ['BMB-001', 'Saus Sambal', 'Bumbu & Saus', 'liter', 5, 12, 28000, 'Rak Bumbu', 1],
            ['BMB-002', 'Mayonnaise', 'Bumbu & Saus', 'liter', 4, 9, 42000, 'Chiller B', 1],
            ['KMS-001', 'Kertas Bungkus', 'Kemasan', 'pcs', 100, 420, 450, 'Rak Kemasan', 0],
            ['KMS-002', 'Gelas 16 oz', 'Kemasan', 'pcs', 100, 85, 850, 'Rak Kemasan', 0],
            ['MNM-001', 'Sirup Gula', 'Minuman', 'liter', 5, 16, 22000, 'Rak Minuman', 1],
        ];
        $stmt = $pdo->prepare('INSERT INTO items(sku,name,category_id,unit,min_stock,current_stock,average_cost,location,expiry_tracking) VALUES(?,?,?,?,?,?,?,?,?)');
        foreach ($items as $item) {
            $stmt->execute([$item[0], $item[1], $catMap[$item[2]] ?? null, $item[3], $item[4], $item[5], $item[6], $item[7], $item[8]]);
        }
    }

    $menuCount = (int) $pdo->query('SELECT COUNT(*) FROM menus')->fetchColumn();
    if ($menuCount === 0) {
        $itemMap = [];
        foreach ($pdo->query('SELECT id,name FROM items')->fetchAll() as $item) {
            $itemMap[$item['name']] = (int) $item['id'];
        }

        $menuSeeds = [
            ['MNU-001', 'Kebab Daging Original', 22000, [
                ['Daging Sapi Slice', 0.08], ['Tortilla 20 cm', 1], ['Selada Segar', 0.03],
                ['Saus Sambal', 0.02], ['Mayonnaise', 0.02], ['Kertas Bungkus', 1],
            ]],
            ['MNU-002', 'Kebab Daging Pedas', 24000, [
                ['Daging Sapi Slice', 0.08], ['Tortilla 20 cm', 1], ['Selada Segar', 0.03],
                ['Saus Sambal', 0.035], ['Mayonnaise', 0.015], ['Kertas Bungkus', 1],
            ]],
            ['MNU-003', 'Kebab Daging Jumbo', 32000, [
                ['Daging Sapi Slice', 0.13], ['Tortilla 20 cm', 1], ['Selada Segar', 0.05],
                ['Saus Sambal', 0.03], ['Mayonnaise', 0.03], ['Kertas Bungkus', 1],
            ]],
            ['MNU-004', 'Es Sirup Segar', 8000, [
                ['Sirup Gula', 0.05], ['Gelas 16 oz', 1],
            ]],
        ];
        $menuStmt = $pdo->prepare('INSERT INTO menus(code,name,selling_price) VALUES(?,?,?)');
        $recipeStmt = $pdo->prepare('INSERT INTO recipes(menu_id,item_id,quantity) VALUES(?,?,?)');
        foreach ($menuSeeds as [$code, $name, $price, $recipe]) {
            $menuStmt->execute([$code, $name, $price]);
            $menuId = (int) $pdo->lastInsertId();
            foreach ($recipe as [$itemName, $quantity]) {
                if (isset($itemMap[$itemName])) {
                    $recipeStmt->execute([$menuId, $itemMap[$itemName], $quantity]);
                }
            }
        }
    }

    $settings = [
        'business_name' => 'StockBite F&B',
        'currency' => 'Rp',
        'expiry_warning_days' => '30',
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings(key,value) VALUES(?,?)');
    foreach ($settings as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function url(string $page, array $params = []): string
{
    return '?' . http_build_query(array_merge(['page' => $page], $params));
}

function redirect(string $page, array $params = []): never
{
    header('Location: ' . url($page, $params));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['_token'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('Token keamanan tidak valid. Muat ulang halaman lalu coba kembali.');
    }
}

function auth_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_auth(): void
{
    if (!auth_user()) {
        header('Location: ?page=login');
        exit;
    }
}

function is_owner(): bool
{
    return (auth_user()['role'] ?? '') === 'owner';
}

function setting(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function money(float|int|string|null $amount): string
{
    return setting('currency', 'Rp') . ' ' . number_format((float) $amount, 0, ',', '.');
}

function qty(float|int|string|null $value): string
{
    $number = (float) $value;
    return rtrim(rtrim(number_format($number, 3, ',', '.'), '0'), ',');
}

function transaction_label(string $type): string
{
    return match ($type) {
        'IN' => 'Stok Masuk',
        'OUT' => 'Stok Keluar',
        'WASTE' => 'Rusak/Terbuang',
        'ADJUSTMENT_PLUS' => 'Penyesuaian +',
        'ADJUSTMENT_MINUS' => 'Penyesuaian -',
        'PRODUCTION' => 'Produksi',
        'SALE' => 'Penjualan Kasir',
        default => $type,
    };
}

function transaction_sign(string $type): int
{
    return in_array($type, ['IN', 'ADJUSTMENT_PLUS'], true) ? 1 : -1;
}

function next_transaction_no(string $type): string
{
    $prefix = match ($type) {
        'IN' => 'IN',
        'OUT' => 'OUT',
        'WASTE' => 'WST',
        'ADJUSTMENT_PLUS', 'ADJUSTMENT_MINUS' => 'ADJ',
        'PRODUCTION' => 'PRD',
        'SALE' => 'SLS',
        default => 'TRX',
    };
    $date = date('Ymd');
    $stmt = db()->prepare('SELECT COUNT(*) FROM transactions WHERE transaction_no LIKE ?');
    $stmt->execute(["{$prefix}-{$date}-%"]);
    $sequence = ((int) $stmt->fetchColumn()) + 1;
    return sprintf('%s-%s-%04d', $prefix, $date, $sequence);
}

function next_sale_no(): string
{
    $date = date('Ymd');
    $stmt = db()->prepare('SELECT COUNT(*) FROM sales WHERE sale_no LIKE ?');
    $stmt->execute(["INV-{$date}-%"]);
    $sequence = ((int) $stmt->fetchColumn()) + 1;
    return sprintf('INV-%s-%04d', $date, $sequence);
}

function page_title(string $page): string
{
    return match ($page) {
        'dashboard' => 'Dashboard',
        'items' => 'Data Bahan & Stok',
        'suppliers' => 'Supplier',
        'stock' => 'Transaksi Stok',
        'cashier' => 'Kasir',
        'menus' => 'Menu & Resep',
        'alerts' => 'Peringatan Stok',
        'reports' => 'Laporan Persediaan',
        'sales_reports' => 'Laporan Penjualan',
        'settings' => 'Pengaturan',
        default => APP_NAME,
    };
}

db();
