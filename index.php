<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    header('Location: ?page=login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        switch ($action) {
            case 'login':
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $password = (string) ($_POST['password'] ?? '');
                $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                if (!$user || !password_verify($password, $user['password'])) {
                    flash('danger', 'Email atau password tidak sesuai.');
                    redirect('login');
                }
                unset($user['password']);
                session_regenerate_id(true);
                $_SESSION['user'] = $user;
                flash('success', 'Selamat datang, ' . $user['name'] . '.');
                redirect(is_owner() ? 'dashboard' : 'cashier');

            case 'save_item':
                require_auth();
                $id = (int) ($_POST['id'] ?? 0);
                $sku = strtoupper(trim((string) ($_POST['sku'] ?? '')));
                $name = trim((string) ($_POST['name'] ?? ''));
                $categoryId = (int) ($_POST['category_id'] ?? 0) ?: null;
                $unit = trim((string) ($_POST['unit'] ?? ''));
                $minStock = max(0, (float) ($_POST['min_stock'] ?? 0));
                $averageCost = max(0, (float) ($_POST['average_cost'] ?? 0));
                $location = trim((string) ($_POST['location'] ?? ''));
                $expiryTracking = isset($_POST['expiry_tracking']) ? 1 : 0;
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                if ($sku === '' || $name === '' || $unit === '') {
                    throw new RuntimeException('SKU, nama bahan, dan satuan wajib diisi.');
                }
                if ($id > 0) {
                    $stmt = db()->prepare('UPDATE items SET sku=?,name=?,category_id=?,unit=?,min_stock=?,average_cost=?,location=?,expiry_tracking=?,is_active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
                    $stmt->execute([$sku, $name, $categoryId, $unit, $minStock, $averageCost, $location, $expiryTracking, $isActive, $id]);
                    flash('success', 'Data bahan berhasil diperbarui.');
                } else {
                    $openingStock = max(0, (float) ($_POST['opening_stock'] ?? 0));
                    $stmt = db()->prepare('INSERT INTO items(sku,name,category_id,unit,min_stock,current_stock,average_cost,location,expiry_tracking,is_active) VALUES(?,?,?,?,?,?,?,?,?,?)');
                    $stmt->execute([$sku, $name, $categoryId, $unit, $minStock, $openingStock, $averageCost, $location, $expiryTracking, $isActive]);
                    flash('success', 'Bahan baru berhasil ditambahkan.');
                }
                redirect('items');

            case 'delete_item':
                require_auth();
                if (!is_owner()) throw new RuntimeException('Hanya owner yang dapat menghapus bahan.');
                $id = (int) ($_POST['id'] ?? 0);
                $stmt = db()->prepare('SELECT COUNT(*) FROM transaction_items WHERE item_id = ?');
                $stmt->execute([$id]);
                if ((int) $stmt->fetchColumn() > 0) {
                    throw new RuntimeException('Bahan tidak dapat dihapus karena sudah memiliki riwayat transaksi. Nonaktifkan bahan sebagai gantinya.');
                }
                $stmt = db()->prepare('DELETE FROM items WHERE id = ?');
                $stmt->execute([$id]);
                flash('success', 'Bahan berhasil dihapus.');
                redirect('items');

            case 'save_supplier':
                require_auth();
                $id = (int) ($_POST['id'] ?? 0);
                $name = trim((string) ($_POST['name'] ?? ''));
                if ($name === '') throw new RuntimeException('Nama supplier wajib diisi.');
                $data = [
                    $name,
                    trim((string) ($_POST['phone'] ?? '')),
                    trim((string) ($_POST['email'] ?? '')),
                    trim((string) ($_POST['address'] ?? '')),
                    trim((string) ($_POST['notes'] ?? '')),
                ];
                if ($id > 0) {
                    $stmt = db()->prepare('UPDATE suppliers SET name=?,phone=?,email=?,address=?,notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
                    $stmt->execute([...$data, $id]);
                    flash('success', 'Supplier berhasil diperbarui.');
                } else {
                    $stmt = db()->prepare('INSERT INTO suppliers(name,phone,email,address,notes) VALUES(?,?,?,?,?)');
                    $stmt->execute($data);
                    flash('success', 'Supplier berhasil ditambahkan.');
                }
                redirect('suppliers');

            case 'delete_supplier':
                require_auth();
                if (!is_owner()) throw new RuntimeException('Hanya owner yang dapat menghapus supplier.');
                $id = (int) ($_POST['id'] ?? 0);
                $stmt = db()->prepare('DELETE FROM suppliers WHERE id=?');
                $stmt->execute([$id]);
                flash('success', 'Supplier berhasil dihapus.');
                redirect('suppliers');

            case 'save_stock':
                require_auth();
                $type = (string) ($_POST['type'] ?? '');
                $allowed = ['IN', 'OUT', 'WASTE', 'ADJUSTMENT_PLUS', 'ADJUSTMENT_MINUS'];
                if (!in_array($type, $allowed, true)) throw new RuntimeException('Jenis transaksi tidak valid.');
                $date = (string) ($_POST['transaction_date'] ?? date('Y-m-d'));
                $supplierId = $type === 'IN' ? ((int) ($_POST['supplier_id'] ?? 0) ?: null) : null;
                $reference = trim((string) ($_POST['reference'] ?? ''));
                $notes = trim((string) ($_POST['notes'] ?? ''));
                $itemIds = $_POST['item_id'] ?? [];
                $quantities = $_POST['quantity'] ?? [];
                $costs = $_POST['unit_cost'] ?? [];
                $batches = $_POST['batch_no'] ?? [];
                $expiries = $_POST['expiry_date'] ?? [];
                if (!is_array($itemIds) || count($itemIds) === 0) throw new RuntimeException('Tambahkan minimal satu bahan.');

                $pdo = db();
                $pdo->beginTransaction();
                $transactionNo = next_transaction_no($type);
                $stmt = $pdo->prepare('INSERT INTO transactions(transaction_no,type,transaction_date,supplier_id,reference,notes,user_id,total_value) VALUES(?,?,?,?,?,?,?,0)');
                $stmt->execute([$transactionNo, $type, $date, $supplierId, $reference, $notes, auth_user()['id']]);
                $transactionId = (int) $pdo->lastInsertId();
                $lineStmt = $pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,quantity,unit_cost,batch_no,expiry_date) VALUES(?,?,?,?,?,?)');
                $itemStmt = $pdo->prepare('SELECT * FROM items WHERE id=?');
                $updateStmt = $pdo->prepare('UPDATE items SET current_stock=?,average_cost=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
                $totalValue = 0.0;
                $validLines = 0;

                foreach ($itemIds as $index => $rawItemId) {
                    $itemId = (int) $rawItemId;
                    $quantity = max(0, (float) ($quantities[$index] ?? 0));
                    if ($itemId <= 0 || $quantity <= 0) continue;
                    $itemStmt->execute([$itemId]);
                    $item = $itemStmt->fetch();
                    if (!$item) throw new RuntimeException('Salah satu bahan tidak ditemukan.');
                    $unitCost = max(0, (float) ($costs[$index] ?? $item['average_cost']));
                    if ($unitCost <= 0) $unitCost = (float) $item['average_cost'];
                    $sign = transaction_sign($type);
                    $newStock = (float) $item['current_stock'] + ($sign * $quantity);
                    if ($newStock < -0.00001) {
                        throw new RuntimeException('Stok ' . $item['name'] . ' tidak mencukupi. Tersedia ' . qty($item['current_stock']) . ' ' . $item['unit'] . '.');
                    }
                    $newAverage = (float) $item['average_cost'];
                    if ($sign > 0 && in_array($type, ['IN', 'ADJUSTMENT_PLUS'], true) && $unitCost > 0) {
                        $oldValue = (float) $item['current_stock'] * (float) $item['average_cost'];
                        $newAverage = $newStock > 0 ? (($oldValue + ($quantity * $unitCost)) / $newStock) : $unitCost;
                    }
                    $updateStmt->execute([$newStock, $newAverage, $itemId]);
                    $expiry = trim((string) ($expiries[$index] ?? '')) ?: null;
                    $batch = trim((string) ($batches[$index] ?? '')) ?: null;
                    $lineStmt->execute([$transactionId, $itemId, $quantity, $unitCost, $batch, $expiry]);
                    $totalValue += $quantity * $unitCost;
                    $validLines++;
                }

                if ($validLines === 0) throw new RuntimeException('Tidak ada baris transaksi yang valid.');
                $stmt = $pdo->prepare('UPDATE transactions SET total_value=? WHERE id=?');
                $stmt->execute([$totalValue, $transactionId]);
                $pdo->commit();
                flash('success', transaction_label($type) . ' berhasil disimpan dengan nomor ' . $transactionNo . '.');
                redirect('stock');

            case 'save_menu':
                require_auth();
                $id = (int) ($_POST['id'] ?? 0);
                $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
                $name = trim((string) ($_POST['name'] ?? ''));
                $price = max(0, (float) ($_POST['selling_price'] ?? 0));
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                if ($code === '' || $name === '') throw new RuntimeException('Kode dan nama menu wajib diisi.');
                $itemIds = $_POST['recipe_item_id'] ?? [];
                $quantities = $_POST['recipe_quantity'] ?? [];
                $pdo = db();
                $pdo->beginTransaction();
                if ($id > 0) {
                    $stmt = $pdo->prepare('UPDATE menus SET code=?,name=?,selling_price=?,is_active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
                    $stmt->execute([$code, $name, $price, $isActive, $id]);
                    $pdo->prepare('DELETE FROM recipes WHERE menu_id=?')->execute([$id]);
                    $menuId = $id;
                } else {
                    $stmt = $pdo->prepare('INSERT INTO menus(code,name,selling_price,is_active) VALUES(?,?,?,?)');
                    $stmt->execute([$code, $name, $price, $isActive]);
                    $menuId = (int) $pdo->lastInsertId();
                }
                $recipeStmt = $pdo->prepare('INSERT OR REPLACE INTO recipes(menu_id,item_id,quantity) VALUES(?,?,?)');
                $valid = 0;
                foreach ($itemIds as $index => $rawItemId) {
                    $itemId = (int) $rawItemId;
                    $quantity = max(0, (float) ($quantities[$index] ?? 0));
                    if ($itemId > 0 && $quantity > 0) {
                        $recipeStmt->execute([$menuId, $itemId, $quantity]);
                        $valid++;
                    }
                }
                if ($valid === 0) throw new RuntimeException('Menu harus memiliki minimal satu bahan resep.');
                $pdo->commit();
                flash('success', $id > 0 ? 'Menu dan resep berhasil diperbarui.' : 'Menu dan resep berhasil ditambahkan.');
                redirect('menus');

            case 'delete_menu':
                require_auth();
                if (!is_owner()) throw new RuntimeException('Hanya owner yang dapat menghapus menu.');
                $id = (int) ($_POST['id'] ?? 0);
                db()->prepare('DELETE FROM menus WHERE id=?')->execute([$id]);
                flash('success', 'Menu berhasil dihapus.');
                redirect('menus');

            case 'produce_menu':
                require_auth();
                $menuId = (int) ($_POST['menu_id'] ?? 0);
                $portions = max(0, (float) ($_POST['portions'] ?? 0));
                if ($menuId <= 0 || $portions <= 0) throw new RuntimeException('Menu dan jumlah porsi wajib diisi.');
                $pdo = db();
                $stmt = $pdo->prepare('SELECT * FROM menus WHERE id=? AND is_active=1');
                $stmt->execute([$menuId]);
                $menu = $stmt->fetch();
                if (!$menu) throw new RuntimeException('Menu tidak ditemukan atau tidak aktif.');
                $stmt = $pdo->prepare('SELECT r.*,i.name,i.unit,i.current_stock,i.average_cost FROM recipes r JOIN items i ON i.id=r.item_id WHERE r.menu_id=?');
                $stmt->execute([$menuId]);
                $recipes = $stmt->fetchAll();
                if (!$recipes) throw new RuntimeException('Resep menu belum tersedia.');
                foreach ($recipes as $recipe) {
                    $needed = (float) $recipe['quantity'] * $portions;
                    if ((float) $recipe['current_stock'] < $needed) {
                        throw new RuntimeException('Stok ' . $recipe['name'] . ' tidak cukup. Dibutuhkan ' . qty($needed) . ' ' . $recipe['unit'] . ', tersedia ' . qty($recipe['current_stock']) . '.');
                    }
                }
                $pdo->beginTransaction();
                $transactionNo = next_transaction_no('PRODUCTION');
                $stmt = $pdo->prepare('INSERT INTO transactions(transaction_no,type,transaction_date,reference,notes,user_id,total_value) VALUES(?,?,?,?,?,?,?)');
                $total = 0.0;
                foreach ($recipes as $recipe) $total += ((float) $recipe['quantity'] * $portions * (float) $recipe['average_cost']);
                $stmt->execute([$transactionNo, 'PRODUCTION', date('Y-m-d'), $menu['code'], 'Produksi ' . qty($portions) . ' porsi ' . $menu['name'], auth_user()['id'], $total]);
                $transactionId = (int) $pdo->lastInsertId();
                $lineStmt = $pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,quantity,unit_cost) VALUES(?,?,?,?)');
                $updateStmt = $pdo->prepare('UPDATE items SET current_stock=current_stock-?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
                foreach ($recipes as $recipe) {
                    $needed = (float) $recipe['quantity'] * $portions;
                    $lineStmt->execute([$transactionId, $recipe['item_id'], $needed, $recipe['average_cost']]);
                    $updateStmt->execute([$needed, $recipe['item_id']]);
                }
                $pdo->commit();
                flash('success', 'Produksi ' . qty($portions) . ' porsi ' . $menu['name'] . ' berhasil dicatat.');
                redirect('menus');


            case 'save_sale':
                require_auth();
                $menuIds = $_POST['menu_id'] ?? [];
                $quantities = $_POST['quantity'] ?? [];
                if (!is_array($menuIds) || !is_array($quantities) || count($menuIds) === 0) {
                    throw new RuntimeException('Keranjang kasir masih kosong.');
                }

                $menuQty = [];
                foreach ($menuIds as $index => $rawMenuId) {
                    $menuId = (int) $rawMenuId;
                    $quantity = min(999, max(0, (float) ($quantities[$index] ?? 0)));
                    if ($menuId > 0 && $quantity > 0) {
                        $menuQty[$menuId] = ($menuQty[$menuId] ?? 0) + $quantity;
                    }
                }
                if (!$menuQty) throw new RuntimeException('Tidak ada menu dengan jumlah yang valid.');

                $orderType = strtoupper(trim((string) ($_POST['order_type'] ?? 'TAKEAWAY')));
                if (!in_array($orderType, ['DINE_IN', 'TAKEAWAY', 'DELIVERY'], true)) $orderType = 'TAKEAWAY';
                $paymentMethod = strtoupper(trim((string) ($_POST['payment_method'] ?? 'CASH')));
                if (!in_array($paymentMethod, ['CASH', 'QRIS', 'TRANSFER', 'DEBIT'], true)) $paymentMethod = 'CASH';
                $customerName = trim((string) ($_POST['customer_name'] ?? ''));
                $notes = trim((string) ($_POST['notes'] ?? ''));

                $pdo = db();
                $menuStmt = $pdo->prepare('SELECT * FROM menus WHERE id=? AND is_active=1');
                $recipeStmt = $pdo->prepare('SELECT r.item_id,r.quantity,i.name,i.unit,i.current_stock,i.average_cost FROM recipes r JOIN items i ON i.id=r.item_id WHERE r.menu_id=?');
                $saleLines = [];
                $requirements = [];
                $subtotal = 0.0;

                foreach ($menuQty as $menuId => $quantity) {
                    $menuStmt->execute([$menuId]);
                    $menu = $menuStmt->fetch();
                    if (!$menu) throw new RuntimeException('Salah satu menu tidak ditemukan atau sedang nonaktif.');
                    $recipeStmt->execute([$menuId]);
                    $recipes = $recipeStmt->fetchAll();
                    if (!$recipes) throw new RuntimeException('Resep untuk menu ' . $menu['name'] . ' belum tersedia.');
                    $lineTotal = (float) $menu['selling_price'] * $quantity;
                    $subtotal += $lineTotal;
                    $saleLines[] = [
                        'menu_id' => $menuId,
                        'name' => $menu['name'],
                        'quantity' => $quantity,
                        'unit_price' => (float) $menu['selling_price'],
                        'line_total' => $lineTotal,
                    ];
                    foreach ($recipes as $recipe) {
                        $itemId = (int) $recipe['item_id'];
                        $needed = (float) $recipe['quantity'] * $quantity;
                        if (!isset($requirements[$itemId])) {
                            $requirements[$itemId] = [
                                'item_id' => $itemId,
                                'name' => $recipe['name'],
                                'unit' => $recipe['unit'],
                                'current_stock' => (float) $recipe['current_stock'],
                                'average_cost' => (float) $recipe['average_cost'],
                                'quantity' => 0.0,
                            ];
                        }
                        $requirements[$itemId]['quantity'] += $needed;
                    }
                }

                foreach ($requirements as $requirement) {
                    if ($requirement['current_stock'] + 0.00001 < $requirement['quantity']) {
                        throw new RuntimeException(
                            'Stok ' . $requirement['name'] . ' tidak cukup. Dibutuhkan ' . qty($requirement['quantity']) . ' ' . $requirement['unit'] .
                            ', tersedia ' . qty($requirement['current_stock']) . ' ' . $requirement['unit'] . '.'
                        );
                    }
                }

                $discount = min($subtotal, max(0, (float) ($_POST['discount'] ?? 0)));
                $total = max(0, $subtotal - $discount);
                $amountPaid = max(0, (float) ($_POST['amount_paid'] ?? 0));
                if ($paymentMethod !== 'CASH') $amountPaid = $total;
                if ($amountPaid + 0.00001 < $total) throw new RuntimeException('Nominal pembayaran tunai masih kurang.');
                $changeAmount = max(0, $amountPaid - $total);

                $pdo->beginTransaction();
                $saleNo = next_sale_no();
                $stockTransactionNo = next_transaction_no('SALE');
                $cogs = 0.0;
                foreach ($requirements as $requirement) {
                    $cogs += $requirement['quantity'] * $requirement['average_cost'];
                }

                $transactionStmt = $pdo->prepare('INSERT INTO transactions(transaction_no,type,transaction_date,reference,notes,user_id,total_value) VALUES(?,?,?,?,?,?,?)');
                $transactionStmt->execute([
                    $stockTransactionNo,
                    'SALE',
                    date('Y-m-d'),
                    $saleNo,
                    'Pemakaian bahan dari penjualan kasir ' . $saleNo,
                    auth_user()['id'],
                    $cogs,
                ]);
                $transactionId = (int) $pdo->lastInsertId();

                $saleStmt = $pdo->prepare('INSERT INTO sales(sale_no,sold_at,customer_name,order_type,payment_method,subtotal,discount,total,amount_paid,change_amount,user_id,transaction_id,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $saleStmt->execute([
                    $saleNo,
                    date('Y-m-d H:i:s'),
                    $customerName ?: null,
                    $orderType,
                    $paymentMethod,
                    $subtotal,
                    $discount,
                    $total,
                    $amountPaid,
                    $changeAmount,
                    auth_user()['id'],
                    $transactionId,
                    $notes ?: null,
                ]);
                $saleId = (int) $pdo->lastInsertId();

                $saleItemStmt = $pdo->prepare('INSERT INTO sale_items(sale_id,menu_id,quantity,unit_price,line_total) VALUES(?,?,?,?,?)');
                foreach ($saleLines as $line) {
                    $saleItemStmt->execute([$saleId, $line['menu_id'], $line['quantity'], $line['unit_price'], $line['line_total']]);
                }

                $stockLineStmt = $pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,quantity,unit_cost) VALUES(?,?,?,?)');
                $updateStockStmt = $pdo->prepare('UPDATE items SET current_stock=current_stock-?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
                foreach ($requirements as $requirement) {
                    $stockLineStmt->execute([$transactionId, $requirement['item_id'], $requirement['quantity'], $requirement['average_cost']]);
                    $updateStockStmt->execute([$requirement['quantity'], $requirement['item_id']]);
                }

                $pdo->commit();
                flash('success', 'Transaksi kasir ' . $saleNo . ' berhasil disimpan.');
                redirect('cashier', ['receipt' => $saleId]);

            case 'save_settings':
                require_auth();
                if (!is_owner()) throw new RuntimeException('Hanya owner yang dapat mengubah pengaturan.');
                $values = [
                    'business_name' => trim((string) ($_POST['business_name'] ?? 'StockBite F&B')),
                    'currency' => trim((string) ($_POST['currency'] ?? 'Rp')),
                    'expiry_warning_days' => (string) max(1, (int) ($_POST['expiry_warning_days'] ?? 30)),
                ];
                $stmt = db()->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
                foreach ($values as $key => $value) $stmt->execute([$key, $value]);
                flash('success', 'Pengaturan aplikasi berhasil disimpan.');
                redirect('settings');
        }
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        $message = $exception instanceof PDOException && str_contains(strtolower($exception->getMessage()), 'unique')
            ? 'Kode/SKU atau email sudah digunakan. Gunakan nilai lain.'
            : $exception->getMessage();
        flash('danger', $message);
        $fallback = (string) ($_POST['return_page'] ?? 'dashboard');
        redirect($fallback);
    }
}

// Pengunjung yang belum memiliki sesi selalu melihat form login terlebih dahulu.
// Setelah login, sesi dipertahankan saat berpindah menu. Sesi hanya dihapus oleh
// action=logout di bagian atas file ini.
$isLandingRequest = $_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['page']) && $action === null;
$page = (string) ($_GET['page'] ?? 'login');
$homePage = is_owner() ? 'dashboard' : 'cashier';

if ($isLandingRequest) {
    if (auth_user()) {
        redirect($homePage);
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    render_login();
    exit;
}

if ($page === 'login') {
    if (auth_user()) {
        redirect($homePage);
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    render_login();
    exit;
}

require_auth();
$allowedPages = ['dashboard', 'cashier', 'items', 'suppliers', 'stock', 'menus', 'alerts', 'reports', 'sales_reports', 'settings'];
if (!in_array($page, $allowedPages, true)) $page = is_owner() ? 'dashboard' : 'cashier';
if ($page === 'settings' && !is_owner()) $page = 'cashier';

if ($page === 'reports' && (($_GET['export'] ?? '') === 'csv')) {
    export_report_csv();
}
if ($page === 'sales_reports' && (($_GET['export'] ?? '') === 'csv')) {
    export_sales_report_csv();
}

render_header($page);
match ($page) {
    'dashboard' => render_dashboard(),
    'cashier' => render_cashier(),
    'items' => render_items(),
    'suppliers' => render_suppliers(),
    'stock' => render_stock(),
    'menus' => render_menus(),
    'alerts' => render_alerts(),
    'reports' => render_reports(),
    'sales_reports' => render_sales_reports(),
    'settings' => render_settings(),
    default => render_dashboard(),
};
render_footer($page);

function render_login(): void
{
    $flashes = get_flashes();
    ?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f766e">
    <title>Masuk · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?= APP_VERSION ?>">
</head>
<body>
<div class="login-page">
    <section class="login-hero">
        <div class="login-logo">
            <div class="brand-mark">SB</div>
            <div><strong>StockBite</strong><span>Smart F&amp;B Inventory</span></div>
        </div>
        <div class="hero-copy">
            <h1>Stok rapi.<br>Operasional lebih pasti.</h1>
            <p>Kelola kasir, bahan baku, supplier, transaksi, resep, produksi, dan laporan dari desktop maupun ponsel.</p>
        </div>
        <div class="hero-features"><span>Kasir Terintegrasi</span><span>Stok Real-time</span><span>Resep &amp; Produksi</span><span>Laporan Penjualan</span></div>
    </section>
    <section class="login-panel">
        <div class="mobile-login-brand" aria-label="StockBite Inventory">
            <div class="brand-mark">SB</div>
            <div><strong>StockBite</strong><span>Inventory &amp; Kasir F&amp;B</span></div>
        </div>
        <form class="login-card" method="post" autocomplete="off">
            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="login">
            <h2>Masuk ke aplikasi</h2>
            <p>Masukkan akun Anda untuk melanjutkan.</p>
            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><span><?= e($flash['message']) ?></span></div>
            <?php endforeach; ?>
            <div class="form-group"><label for="loginEmail">Email</label><input class="form-control" id="loginEmail" type="email" name="email" value="" placeholder="Masukkan email" inputmode="email" autocapitalize="none" spellcheck="false" required autocomplete="off"></div>
            <div class="form-group"><label for="loginPassword">Password</label><div class="password-field"><input class="form-control" id="loginPassword" type="password" name="password" value="" placeholder="Masukkan password" required autocomplete="new-password"><button class="password-toggle" type="button" data-password-toggle aria-label="Tampilkan password">Lihat</button></div></div>
            <button class="btn btn-primary" type="submit">Masuk Sekarang</button>
        </form>
    </section>
</div>
<script src="assets/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
    <?php
}

function render_dashboard(): void
{
    $pdo = db();
    $totalItems = (int) $pdo->query('SELECT COUNT(*) FROM items WHERE is_active=1')->fetchColumn();
    $stockValue = (float) $pdo->query('SELECT COALESCE(SUM(current_stock*average_cost),0) FROM items WHERE is_active=1')->fetchColumn();
    $lowStock = (int) $pdo->query('SELECT COUNT(*) FROM items WHERE is_active=1 AND current_stock<=min_stock')->fetchColumn();
    $todayMovements = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE transaction_date='" . date('Y-m-d') . "'")->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) AS orders,COALESCE(SUM(total),0) AS revenue FROM sales WHERE date(sold_at)=?');
    $stmt->execute([date('Y-m-d')]);
    $todaySales = $stmt->fetch() ?: ['orders' => 0, 'revenue' => 0];
    $recent = $pdo->query('SELECT t.*,u.name AS user_name,s.name AS supplier_name FROM transactions t JOIN users u ON u.id=t.user_id LEFT JOIN suppliers s ON s.id=t.supplier_id ORDER BY t.id DESC LIMIT 7')->fetchAll();
    $critical = $pdo->query('SELECT * FROM items WHERE is_active=1 AND current_stock<=min_stock ORDER BY (current_stock-min_stock) ASC LIMIT 6')->fetchAll();
    $topUsage = $pdo->query("SELECT i.name,i.unit,SUM(ti.quantity) AS total FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id JOIN items i ON i.id=ti.item_id WHERE t.type IN ('OUT','WASTE','PRODUCTION','SALE') AND t.transaction_date>=date('now','-30 day') GROUP BY i.id ORDER BY total DESC LIMIT 5")->fetchAll();
    $maxUsage = max(array_map(fn($row) => (float) $row['total'], $topUsage) ?: [1]);
    ?>
    <div class="page-head">
        <div><h2>Ringkasan operasional</h2><p>Pantau kondisi persediaan dan aktivitas terbaru dalam satu layar.</p></div>
        <div class="actions"><button class="btn btn-secondary" data-fill-transaction="OUT"><?= icon('arrow-up') ?> Stok Keluar</button><button class="btn btn-primary" data-fill-transaction="IN"><?= icon('plus') ?> Stok Masuk</button></div>
    </div>

    <div class="grid grid-4">
        <article class="card metric-card"><div class="metric-copy"><span>Total Bahan Aktif</span><strong><?= $totalItems ?></strong><small>SKU yang dikelola</small></div><div class="metric-icon"><?= icon('package') ?></div></article>
        <article class="card metric-card info"><div class="metric-copy"><span>Nilai Persediaan</span><strong><?= money($stockValue) ?></strong><small>Berdasarkan biaya rata-rata</small></div><div class="metric-icon"><?= icon('wallet') ?></div></article>
        <article class="card metric-card info"><div class="metric-copy"><span>Penjualan Hari Ini</span><strong><?= money($todaySales['revenue']) ?></strong><small><?= (int) $todaySales['orders'] ?> pesanan berhasil</small></div><div class="metric-icon"><?= icon('receipt') ?></div></article>
        <article class="card metric-card <?= $lowStock > 0 ? 'danger' : '' ?>"><div class="metric-copy"><span>Stok Minimum</span><strong><?= $lowStock ?></strong><small><?= $todayMovements ?> mutasi stok hari ini</small></div><div class="metric-icon"><?= icon('alert') ?></div></article>
    </div>

    <div class="grid grid-2" style="margin-top:18px">
        <section class="card">
            <div class="card-header"><div><h3>Aktivitas terbaru</h3><p>7 transaksi terakhir</p></div><a class="btn btn-secondary btn-sm" href="<?= url('stock') ?>">Lihat Semua</a></div>
            <?php if ($recent): ?><div class="table-wrap"><table class="responsive-table"><thead><tr><th>Nomor</th><th>Jenis</th><th>Tanggal</th><th>Nilai</th></tr></thead><tbody>
            <?php foreach ($recent as $row): $badge = in_array($row['type'], ['IN','ADJUSTMENT_PLUS'], true) ? 'success' : ($row['type'] === 'WASTE' ? 'danger' : 'info'); ?>
                <tr><td class="mobile-main"><div class="table-title"><div class="item-dot"><?= e(substr($row['transaction_no'],0,2)) ?></div><div><strong><?= e($row['transaction_no']) ?></strong><span><?= e($row['user_name']) ?></span></div></div></td><td data-label="Jenis"><span class="badge badge-<?= $badge ?>"><?= e(transaction_label($row['type'])) ?></span></td><td data-label="Tanggal"><?= e(date('d M Y', strtotime($row['transaction_date']))) ?></td><td data-label="Nilai" class="fw-bold"><?= money($row['total_value']) ?></td></tr>
            <?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><?= icon('swap') ?><strong>Belum ada transaksi</strong><p>Catat transaksi stok pertama Anda.</p></div><?php endif; ?>
        </section>

        <section class="card">
            <div class="card-header"><div><h3>Bahan paling banyak digunakan</h3><p>Akumulasi 30 hari terakhir</p></div></div>
            <div class="card-body">
                <?php if ($topUsage): ?><div class="chart-list">
                    <?php foreach ($topUsage as $row): $percent = min(100, ((float)$row['total'] / $maxUsage) * 100); ?>
                        <div class="chart-row"><strong><?= e($row['name']) ?></strong><div class="progress"><span style="width:<?= $percent ?>%"></span></div><span><?= qty($row['total']) ?> <?= e($row['unit']) ?></span></div>
                    <?php endforeach; ?>
                </div><?php else: ?><div class="empty-state"><?= icon('chart') ?><strong>Belum ada data pemakaian</strong><p>Grafik akan muncul setelah ada stok keluar atau produksi.</p></div><?php endif; ?>
            </div>
        </section>
    </div>

    <section class="card" style="margin-top:18px">
        <div class="card-header"><div><h3>Stok kritis</h3><p>Bahan yang sudah menyentuh atau berada di bawah stok minimum</p></div><a class="btn btn-secondary btn-sm" href="<?= url('alerts') ?>">Buka Peringatan</a></div>
        <?php if ($critical): ?><div class="table-wrap"><table class="responsive-table"><thead><tr><th>Bahan</th><th>Lokasi</th><th>Stok Saat Ini</th><th>Minimum</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($critical as $item): ?>
            <tr><td class="mobile-main"><div class="table-title"><div class="item-dot"><?= e(substr($item['sku'],0,2)) ?></div><div><strong><?= e($item['name']) ?></strong><span><?= e($item['sku']) ?></span></div></div></td><td data-label="Lokasi"><?= e($item['location'] ?: '-') ?></td><td data-label="Stok"><strong class="text-danger"><?= qty($item['current_stock']) ?> <?= e($item['unit']) ?></strong></td><td data-label="Minimum"><?= qty($item['min_stock']) ?> <?= e($item['unit']) ?></td><td data-label="Status"><span class="badge badge-danger">Segera restock</span></td></tr>
        <?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><?= icon('box') ?><strong>Semua stok aman</strong><p>Tidak ada bahan di bawah batas minimum.</p></div><?php endif; ?>
    </section>

    <?php render_stock_modal(); ?>
    <?php
}


function render_cashier(): void
{
    $pdo = db();
    $menus = $pdo->query('SELECT * FROM menus WHERE is_active=1 ORDER BY name')->fetchAll();
    $recipeStmt = $pdo->prepare('SELECT r.quantity,i.current_stock FROM recipes r JOIN items i ON i.id=r.item_id WHERE r.menu_id=?');
    foreach ($menus as &$menu) {
        $recipeStmt->execute([$menu['id']]);
        $recipes = $recipeStmt->fetchAll();
        $available = null;
        foreach ($recipes as $recipe) {
            if ((float) $recipe['quantity'] <= 0) continue;
            $portions = (int) floor((float) $recipe['current_stock'] / (float) $recipe['quantity']);
            $available = $available === null ? $portions : min($available, $portions);
        }
        $menu['available_portions'] = $recipes ? max(0, (int) ($available ?? 0)) : 0;
        $menu['has_recipe'] = (bool) $recipes;
    }
    unset($menu);

    $today = date('Y-m-d');
    $stmt = $pdo->prepare('SELECT COUNT(*) AS total_orders,COALESCE(SUM(total),0) AS revenue,COALESCE(AVG(total),0) AS average_order FROM sales WHERE date(sold_at)=?');
    $stmt->execute([$today]);
    $summary = $stmt->fetch() ?: ['total_orders' => 0, 'revenue' => 0, 'average_order' => 0];
    $recent = $pdo->query('SELECT s.*,u.name AS cashier_name FROM sales s JOIN users u ON u.id=s.user_id ORDER BY s.id DESC LIMIT 8')->fetchAll();

    $receipt = null;
    $receiptItems = [];
    $receiptId = (int) ($_GET['receipt'] ?? 0);
    if ($receiptId > 0) {
        $stmt = $pdo->prepare('SELECT s.*,u.name AS cashier_name FROM sales s JOIN users u ON u.id=s.user_id WHERE s.id=?');
        $stmt->execute([$receiptId]);
        $receipt = $stmt->fetch() ?: null;
        if ($receipt) {
            $stmt = $pdo->prepare('SELECT si.*,m.code,m.name FROM sale_items si JOIN menus m ON m.id=si.menu_id WHERE si.sale_id=? ORDER BY si.id');
            $stmt->execute([$receiptId]);
            $receiptItems = $stmt->fetchAll();
        }
    }
    ?>
    <div class="page-head no-print">
        <div><h2>Kasir F&amp;B</h2><p>Pilih menu, proses pembayaran, lalu stok bahan akan berkurang otomatis berdasarkan resep.</p></div>
        <div class="actions"><a class="btn btn-secondary" href="<?= url('sales_reports') ?>"><?= icon('chart') ?> Laporan Penjualan</a><a class="btn btn-secondary" href="#riwayat-kasir"><?= icon('receipt') ?> Riwayat Terbaru</a></div>
    </div>

    <?php if ($receipt): ?>
        <section class="card receipt-card" id="receiptPrint">
            <div class="receipt-actions no-print"><a class="btn btn-secondary" href="<?= url('cashier') ?>">Transaksi Baru</a><button class="btn btn-primary" type="button" data-print-receipt><?= icon('printer') ?> Cetak Struk</button></div>
            <div class="receipt-head"><div class="brand-mark">SB</div><div><h3><?= e(setting('business_name', 'StockBite F&B')) ?></h3><p>Struk Penjualan</p></div></div>
            <div class="receipt-meta"><div><span>No. Invoice</span><strong><?= e($receipt['sale_no']) ?></strong></div><div><span>Waktu</span><strong><?= e(date('d M Y H:i', strtotime($receipt['sold_at']))) ?></strong></div><div><span>Kasir</span><strong><?= e($receipt['cashier_name']) ?></strong></div><div><span>Pelanggan</span><strong><?= e($receipt['customer_name'] ?: 'Umum') ?></strong></div></div>
            <div class="receipt-lines">
                <?php foreach ($receiptItems as $item): ?>
                    <div class="receipt-line"><div><strong><?= e($item['name']) ?></strong><span><?= qty($item['quantity']) ?> × <?= money($item['unit_price']) ?></span></div><strong><?= money($item['line_total']) ?></strong></div>
                <?php endforeach; ?>
            </div>
            <div class="receipt-totals"><div><span>Subtotal</span><strong><?= money($receipt['subtotal']) ?></strong></div><div><span>Diskon</span><strong>- <?= money($receipt['discount']) ?></strong></div><div class="grand"><span>Total</span><strong><?= money($receipt['total']) ?></strong></div><div><span>Bayar · <?= e(payment_label($receipt['payment_method'])) ?></span><strong><?= money($receipt['amount_paid']) ?></strong></div><div><span>Kembalian</span><strong><?= money($receipt['change_amount']) ?></strong></div></div>
            <div class="receipt-note"><strong><?= e(order_type_label($receipt['order_type'])) ?></strong><?php if ($receipt['notes']): ?><span><?= e($receipt['notes']) ?></span><?php endif; ?><p>Terima kasih atas kunjungan Anda.</p></div>
        </section>
    <?php endif; ?>

    <div class="grid grid-3 no-print" style="margin-bottom:18px">
        <article class="card metric-card info"><div class="metric-copy"><span>Penjualan Hari Ini</span><strong><?= money($summary['revenue']) ?></strong><small><?= e(date('d M Y')) ?></small></div><div class="metric-icon"><?= icon('wallet') ?></div></article>
        <article class="card metric-card"><div class="metric-copy"><span>Jumlah Pesanan</span><strong><?= (int) $summary['total_orders'] ?></strong><small>Transaksi berhasil</small></div><div class="metric-icon"><?= icon('receipt') ?></div></article>
        <article class="card metric-card warning"><div class="metric-copy"><span>Rata-rata Pesanan</span><strong><?= money($summary['average_order']) ?></strong><small>Nilai per transaksi</small></div><div class="metric-icon"><?= icon('chart') ?></div></article>
    </div>

    <div class="pos-layout no-print" data-pos data-currency="<?= e(setting('currency', 'Rp')) ?>">
        <section class="card pos-products">
            <div class="card-header"><div><h3>Pilih Menu</h3><p><?= count($menus) ?> menu aktif tersedia</p></div></div>
            <div class="pos-toolbar"><div class="search-box"><?= icon('search') ?><input class="form-control" type="search" placeholder="Cari menu..." data-pos-search></div></div>
            <div class="pos-menu-grid" data-pos-menu-grid>
                <?php foreach ($menus as $menu): $available = (int) $menu['available_portions']; ?>
                    <button type="button" class="pos-menu-card" data-pos-menu='<?= e(json_encode(['id'=>(int)$menu['id'],'code'=>$menu['code'],'name'=>$menu['name'],'price'=>(float)$menu['selling_price'],'available'=>$available], JSON_UNESCAPED_UNICODE)) ?>' <?= $available < 1 ? 'disabled' : '' ?>>
                        <div class="menu-card-top"><span class="item-dot"><?= e(substr($menu['code'], 0, 2)) ?></span><span class="badge <?= $available > 5 ? 'badge-success' : ($available > 0 ? 'badge-warning' : 'badge-danger') ?>"><?= $available > 0 ? 'Maks. ' . $available : 'Stok habis' ?></span></div>
                        <strong><?= e($menu['name']) ?></strong><span><?= e($menu['code']) ?></span><b><?= money($menu['selling_price']) ?></b>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php if (!$menus): ?><div class="empty-state"><?= icon('utensils') ?><strong>Belum ada menu aktif</strong><p>Tambahkan menu dan resep terlebih dahulu.</p></div><?php endif; ?>
        </section>

        <form class="card pos-cart" method="post" data-pos-form>
            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save_sale">
            <input type="hidden" name="return_page" value="cashier">
            <div class="card-header"><div><h3>Keranjang</h3><p><span data-pos-count>0</span> item dipilih</p></div><button class="btn btn-secondary btn-sm" type="button" data-pos-clear>Kosongkan</button></div>
            <div class="pos-cart-body">
                <div class="pos-empty" data-pos-empty><?= icon('cart') ?><strong>Keranjang masih kosong</strong><p>Ketuk menu untuk menambah pesanan.</p></div>
                <div class="pos-cart-lines" data-pos-lines></div>
            </div>
            <div class="pos-checkout">
                <div class="form-grid"><div class="form-group"><label>Nama Pelanggan</label><input class="form-control" name="customer_name" placeholder="Opsional"></div><div class="form-group"><label>Jenis Pesanan</label><select class="form-select" name="order_type"><option value="TAKEAWAY">Bawa Pulang</option><option value="DINE_IN">Makan di Tempat</option><option value="DELIVERY">Delivery</option></select></div></div>
                <div class="form-grid"><div class="form-group"><label>Metode Bayar</label><select class="form-select" name="payment_method" data-pos-payment><option value="CASH">Tunai</option><option value="QRIS">QRIS</option><option value="TRANSFER">Transfer</option><option value="DEBIT">Kartu Debit</option></select></div><div class="form-group"><label>Diskon Nominal</label><input class="form-control" type="number" min="0" step="500" name="discount" value="0" data-pos-discount></div></div>
                <div class="form-group" data-pos-paid-group><label>Uang Diterima</label><input class="form-control" type="number" min="0" step="500" name="amount_paid" value="0" data-pos-paid></div>
                <div class="form-group"><label>Catatan</label><textarea class="form-control" name="notes" rows="2" placeholder="Contoh: tanpa saus, meja 4"></textarea></div>
                <div class="pos-summary"><div><span>Subtotal</span><strong data-pos-subtotal><?= money(0) ?></strong></div><div><span>Diskon</span><strong data-pos-discount-text>- <?= money(0) ?></strong></div><div class="grand"><span>Total Bayar</span><strong data-pos-total><?= money(0) ?></strong></div><div><span>Kembalian</span><strong data-pos-change><?= money(0) ?></strong></div></div>
                <button class="btn btn-primary pos-pay-button" type="submit" data-pos-submit disabled><?= icon('check') ?> Proses Pembayaran</button>
            </div>
        </form>
    </div>

    <section class="card" id="riwayat-kasir" style="margin-top:18px">
        <div class="card-header"><div><h3>Transaksi Kasir Terbaru</h3><p>8 penjualan terakhir</p></div></div>
        <?php if ($recent): ?><div class="table-wrap"><table class="responsive-table"><thead><tr><th>Invoice</th><th>Waktu</th><th>Kasir</th><th>Pembayaran</th><th>Total</th><th>Aksi</th></tr></thead><tbody><?php foreach ($recent as $sale): ?><tr><td class="mobile-main"><strong><?= e($sale['sale_no']) ?></strong></td><td data-label="Waktu"><?= e(date('d M Y H:i', strtotime($sale['sold_at']))) ?></td><td data-label="Kasir"><?= e($sale['cashier_name']) ?></td><td data-label="Pembayaran"><span class="badge badge-info"><?= e(payment_label($sale['payment_method'])) ?></span></td><td data-label="Total" class="fw-bold"><?= money($sale['total']) ?></td><td data-label="Aksi"><a class="btn btn-secondary btn-sm" href="<?= url('cashier', ['receipt' => $sale['id']]) ?>">Lihat Struk</a></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><?= icon('receipt') ?><strong>Belum ada transaksi kasir</strong><p>Penjualan pertama akan muncul di sini.</p></div><?php endif; ?>
    </section>
    <?php
}

function payment_label(string $method): string
{
    return match ($method) {
        'CASH' => 'Tunai',
        'QRIS' => 'QRIS',
        'TRANSFER' => 'Transfer',
        'DEBIT' => 'Kartu Debit',
        default => $method,
    };
}

function order_type_label(string $type): string
{
    return match ($type) {
        'DINE_IN' => 'Makan di Tempat',
        'DELIVERY' => 'Delivery',
        default => 'Bawa Pulang',
    };
}

function render_items(): void
{
    $pdo = db();
    $items = $pdo->query('SELECT i.*,c.name AS category_name FROM items i LEFT JOIN categories c ON c.id=i.category_id ORDER BY i.name')->fetchAll();
    $categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
    ?>
    <div class="page-head"><div><h2>Daftar bahan dan persediaan</h2><p>Kelola identitas bahan, batas minimum, lokasi, dan biaya rata-rata.</p></div><div class="actions"><button class="btn btn-primary" data-new-target="itemModal" data-new-title="Tambah Bahan Baru"><?= icon('plus') ?> Tambah Bahan</button></div></div>
    <section class="card">
        <div class="toolbar"><div class="search-box"><?= icon('search') ?><input class="form-control" data-live-search="#itemTable" placeholder="Cari SKU, bahan, kategori, atau lokasi..."></div></div>
        <div class="table-wrap"><table id="itemTable" class="responsive-table"><thead><tr><th>Bahan</th><th>Kategori</th><th>Lokasi</th><th>Stok</th><th>Nilai Stok</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
        <?php foreach ($items as $item): $isLow = (float)$item['current_stock'] <= (float)$item['min_stock']; ?>
            <tr data-search-row>
                <td class="mobile-main"><div class="table-title"><div class="item-dot"><?= e(substr($item['sku'],0,2)) ?></div><div><strong><?= e($item['name']) ?></strong><span><?= e($item['sku']) ?> · <?= e($item['unit']) ?></span></div></div></td>
                <td data-label="Kategori"><?= e($item['category_name'] ?: '-') ?></td>
                <td data-label="Lokasi"><?= e($item['location'] ?: '-') ?></td>
                <td data-label="Stok"><strong class="<?= $isLow ? 'text-danger' : '' ?>"><?= qty($item['current_stock']) ?> <?= e($item['unit']) ?></strong><div class="text-muted" style="font-size:10px">Min. <?= qty($item['min_stock']) ?></div></td>
                <td data-label="Nilai Stok"><?= money((float)$item['current_stock']*(float)$item['average_cost']) ?></td>
                <td data-label="Status"><span class="badge badge-<?= !$item['is_active'] ? 'neutral' : ($isLow ? 'danger' : 'success') ?>"><?= !$item['is_active'] ? 'Nonaktif' : ($isLow ? 'Stok rendah' : 'Aman') ?></span></td>
                <td data-label="Aksi"><div class="inline-actions"><button class="btn btn-secondary btn-icon btn-sm" type="button" title="Edit" data-edit-target="itemModal" data-edit-title="Edit Bahan" data-field-id="<?= $item['id'] ?>" data-field-sku="<?= e($item['sku']) ?>" data-field-name="<?= e($item['name']) ?>" data-field-category_id="<?= e((string)$item['category_id']) ?>" data-field-unit="<?= e($item['unit']) ?>" data-field-min_stock="<?= e((string)$item['min_stock']) ?>" data-field-average_cost="<?= e((string)$item['average_cost']) ?>" data-field-location="<?= e($item['location']) ?>" data-field-expiry_tracking="<?= $item['expiry_tracking'] ?>" data-field-is_active="<?= $item['is_active'] ?>"><?= icon('edit') ?></button>
                <?php if (is_owner()): ?><form method="post" data-confirm="Hapus bahan ini secara permanen?"><input type="hidden" name="_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete_item"><input type="hidden" name="return_page" value="items"><input type="hidden" name="id" value="<?= $item['id'] ?>"><button class="btn btn-danger btn-icon btn-sm" title="Hapus"><?= icon('trash') ?></button></form><?php endif; ?></div></td>
            </tr>
        <?php endforeach; ?></tbody></table></div>
    </section>

    <div id="itemModal" class="modal-backdrop"><div class="modal"><div class="modal-header"><h3 data-modal-title>Tambah Bahan Baru</h3><button class="modal-close" data-modal-close type="button">&times;</button></div><form method="post"><input type="hidden" name="_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="save_item"><input type="hidden" name="return_page" value="items"><input type="hidden" name="id" value="">
        <div class="modal-body"><div class="form-grid">
            <div class="form-group"><label>SKU *</label><input class="form-control" name="sku" placeholder="BHN-001" required></div>
            <div class="form-group"><label>Nama Bahan *</label><input class="form-control" name="name" placeholder="Contoh: Daging Sapi" required></div>
            <div class="form-group"><label>Kategori</label><select class="form-select" name="category_id"><option value="">Pilih kategori</option><?php foreach ($categories as $category): ?><option value="<?= $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Satuan *</label><input class="form-control" name="unit" placeholder="kg, liter, pcs" required></div>
            <div class="form-group"><label>Stok Awal</label><input class="form-control" type="number" min="0" step="0.001" name="opening_stock" value="0"><div class="form-hint">Hanya digunakan saat menambah bahan baru.</div></div>
            <div class="form-group"><label>Stok Minimum</label><input class="form-control" type="number" min="0" step="0.001" name="min_stock" value="0"></div>
            <div class="form-group"><label>Biaya Rata-rata</label><input class="form-control" type="number" min="0" step="1" name="average_cost" value="0"></div>
            <div class="form-group"><label>Lokasi Penyimpanan</label><input class="form-control" name="location" placeholder="Freezer A / Rak 1"></div>
            <div class="form-group"><div class="check-row"><input type="checkbox" id="expiry_tracking" name="expiry_tracking" value="1"><label for="expiry_tracking">Pantau tanggal kedaluwarsa</label></div></div>
            <div class="form-group"><div class="check-row"><input type="checkbox" id="is_active" name="is_active" value="1" checked><label for="is_active">Bahan aktif</label></div></div>
        </div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-modal-close>Batal</button><button class="btn btn-primary" type="submit">Simpan Bahan</button></div>
    </form></div></div>
    <?php
}

function render_suppliers(): void
{
    $suppliers = db()->query('SELECT s.*,(SELECT COUNT(*) FROM transactions t WHERE t.supplier_id=s.id) AS transaction_count FROM suppliers s ORDER BY s.name')->fetchAll();
    ?>
    <div class="page-head"><div><h2>Daftar supplier</h2><p>Simpan kontak dan catatan pemasok bahan maupun kemasan.</p></div><div class="actions"><button class="btn btn-primary" data-new-target="supplierModal" data-new-title="Tambah Supplier"><?= icon('plus') ?> Tambah Supplier</button></div></div>
    <section class="card"><div class="toolbar"><div class="search-box"><?= icon('search') ?><input class="form-control" data-live-search="#supplierTable" placeholder="Cari nama, telepon, alamat..."></div></div><div class="table-wrap"><table id="supplierTable" class="responsive-table"><thead><tr><th>Supplier</th><th>Kontak</th><th>Alamat</th><th>Transaksi</th><th>Catatan</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($suppliers as $supplier): ?><tr data-search-row><td class="mobile-main"><div class="table-title"><div class="item-dot"><?= e(strtoupper(substr($supplier['name'],0,2))) ?></div><div><strong><?= e($supplier['name']) ?></strong><span><?= e($supplier['email'] ?: 'Tanpa email') ?></span></div></div></td><td data-label="Kontak"><?= e($supplier['phone'] ?: '-') ?></td><td data-label="Alamat"><?= e($supplier['address'] ?: '-') ?></td><td data-label="Transaksi"><span class="badge badge-info"><?= (int)$supplier['transaction_count'] ?> transaksi</span></td><td data-label="Catatan"><?= e($supplier['notes'] ?: '-') ?></td><td data-label="Aksi"><div class="inline-actions"><button class="btn btn-secondary btn-icon btn-sm" data-edit-target="supplierModal" data-edit-title="Edit Supplier" data-field-id="<?= $supplier['id'] ?>" data-field-name="<?= e($supplier['name']) ?>" data-field-phone="<?= e($supplier['phone']) ?>" data-field-email="<?= e($supplier['email']) ?>" data-field-address="<?= e($supplier['address']) ?>" data-field-notes="<?= e($supplier['notes']) ?>"><?= icon('edit') ?></button><?php if (is_owner()): ?><form method="post" data-confirm="Hapus supplier ini?"><input type="hidden" name="_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete_supplier"><input type="hidden" name="return_page" value="suppliers"><input type="hidden" name="id" value="<?= $supplier['id'] ?>"><button class="btn btn-danger btn-icon btn-sm"><?= icon('trash') ?></button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
    </tbody></table></div></section>

    <div id="supplierModal" class="modal-backdrop"><div class="modal"><div class="modal-header"><h3 data-modal-title>Tambah Supplier</h3><button class="modal-close" data-modal-close type="button">&times;</button></div><form method="post"><input type="hidden" name="_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="save_supplier"><input type="hidden" name="return_page" value="suppliers"><input type="hidden" name="id"><div class="modal-body"><div class="form-grid">
        <div class="form-group full"><label>Nama Supplier *</label><input class="form-control" name="name" required></div><div class="form-group"><label>Telepon</label><input class="form-control" name="phone"></div><div class="form-group"><label>Email</label><input class="form-control" type="email" name="email"></div><div class="form-group full"><label>Alamat</label><textarea name="address"></textarea></div><div class="form-group full"><label>Catatan</label><textarea name="notes"></textarea></div>
    </div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-modal-close>Batal</button><button class="btn btn-primary">Simpan Supplier</button></div></form></div></div>
    <?php
}

function render_stock(): void
{
    $transactions = db()->query('SELECT t.*,u.name AS user_name,s.name AS supplier_name,(SELECT COUNT(*) FROM transaction_items ti WHERE ti.transaction_id=t.id) AS line_count FROM transactions t JOIN users u ON u.id=t.user_id LEFT JOIN suppliers s ON s.id=t.supplier_id ORDER BY t.id DESC LIMIT 100')->fetchAll();
    ?>
    <div class="page-head"><div><h2>Transaksi persediaan</h2><p>Catat stok masuk, keluar, bahan rusak, dan penyesuaian.</p></div><div class="actions"><button class="btn btn-secondary" data-fill-transaction="OUT"><?= icon('arrow-up') ?> Stok Keluar</button><button class="btn btn-primary" data-fill-transaction="IN"><?= icon('arrow-down') ?> Stok Masuk</button></div></div>
    <section class="card"><div class="toolbar"><div class="search-box"><?= icon('search') ?><input class="form-control" data-live-search="#transactionTable" placeholder="Cari nomor, jenis, referensi, supplier..."></div></div><div class="table-wrap"><table id="transactionTable" class="responsive-table"><thead><tr><th>Transaksi</th><th>Jenis</th><th>Tanggal</th><th>Supplier/Referensi</th><th>Baris</th><th>Nilai</th><th>Petugas</th></tr></thead><tbody>
    <?php foreach ($transactions as $row): $badge = in_array($row['type'], ['IN','ADJUSTMENT_PLUS'], true) ? 'success' : ($row['type']==='WASTE'?'danger':'info'); ?><tr data-search-row><td class="mobile-main"><div class="table-title"><div class="item-dot"><?= e(substr($row['transaction_no'],0,2)) ?></div><div><strong><?= e($row['transaction_no']) ?></strong><span><?= e($row['notes'] ?: 'Tanpa catatan') ?></span></div></div></td><td data-label="Jenis"><span class="badge badge-<?= $badge ?>"><?= e(transaction_label($row['type'])) ?></span></td><td data-label="Tanggal"><?= e(date('d M Y',strtotime($row['transaction_date']))) ?></td><td data-label="Supplier/Ref"><?= e($row['supplier_name'] ?: ($row['reference'] ?: '-')) ?></td><td data-label="Baris"><?= (int)$row['line_count'] ?></td><td data-label="Nilai" class="fw-bold"><?= money($row['total_value']) ?></td><td data-label="Petugas"><?= e($row['user_name']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
    <?php render_stock_modal(); ?>
    <?php
}

function render_stock_modal(): void
{
    $items = db()->query('SELECT id,sku,name,unit,current_stock,average_cost,expiry_tracking FROM items WHERE is_active=1 ORDER BY name')->fetchAll();
    $suppliers = db()->query('SELECT id,name FROM suppliers ORDER BY name')->fetchAll();
    ?>
    <div id="stockTransactionModal" class="modal-backdrop"><div class="modal modal-lg"><div class="modal-header"><h3>Catat Transaksi Stok</h3><button class="modal-close" data-modal-close type="button">&times;</button></div><form method="post"><input type="hidden" name="_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="save_stock"><input type="hidden" name="return_page" value="stock"><div class="modal-body">
        <div class="form-grid" style="margin-bottom:16px"><div class="form-group"><label>Jenis Transaksi *</label><select class="form-select" name="type" data-transaction-type required><option value="IN">Stok Masuk</option><option value="OUT">Stok Keluar</option><option value="WASTE">Rusak / Terbuang</option><option value="ADJUSTMENT_PLUS">Penyesuaian Tambah</option><option value="ADJUSTMENT_MINUS">Penyesuaian Kurang</option></select></div><div class="form-group"><label>Tanggal *</label><input class="form-control" type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required></div><div class="form-group" data-supplier-group><label>Supplier</label><select class="form-select" name="supplier_id"><option value="">Pilih supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?= $supplier['id'] ?>"><?= e($supplier['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Referensi</label><input class="form-control" name="reference" placeholder="No. nota / PO / departemen"></div><div class="form-group full"><label>Catatan</label><input class="form-control" name="notes" placeholder="Keterangan tambahan"></div></div>
        <div class="page-head" style="margin-bottom:10px"><div><h2 style="font-size:15px">Rincian bahan</h2><p>Tambahkan satu atau beberapa bahan dalam transaksi.</p></div><button class="btn btn-secondary btn-sm" type="button" data-add-stock-line><?= icon('plus') ?> Tambah Baris</button></div>
        <div class="dynamic-lines" data-stock-lines></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-modal-close>Batal</button><button class="btn btn-primary" type="submit">Simpan Transaksi</button></div></form></div></div>
    <template id="stock-line-template"><div class="stock-line" data-line><div class="form-group item-field"><label>Bahan *</label><select class="form-select" name="item_id[]" required><option value="">Pilih bahan</option><?php foreach ($items as $item): ?><option value="<?= $item['id'] ?>"><?= e($item['sku'].' · '.$item['name'].' (stok '.qty($item['current_stock']).' '.$item['unit'].')') ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Jumlah *</label><input class="form-control" type="number" min="0.001" step="0.001" name="quantity[]" required></div><div class="form-group" data-cost-field><label>Biaya/Satuan</label><input class="form-control" type="number" min="0" step="1" name="unit_cost[]"></div><div class="form-group"><label>No. Batch</label><input class="form-control" name="batch_no[]"></div><div class="form-group"><label>Kedaluwarsa</label><input class="form-control" type="date" name="expiry_date[]"></div><button class="remove-line" type="button" data-remove-line aria-label="Hapus baris">&times;</button></div></template>
    <?php
}

function render_menus(): void
{
    $pdo = db();
    $menus = $pdo->query('SELECT m.*,COALESCE(SUM(r.quantity*i.average_cost),0) AS recipe_cost,COUNT(r.id) AS ingredient_count FROM menus m LEFT JOIN recipes r ON r.menu_id=m.id LEFT JOIN items i ON i.id=r.item_id GROUP BY m.id ORDER BY m.name')->fetchAll();
    $items = $pdo->query('SELECT id,sku,name,unit FROM items WHERE is_active=1 ORDER BY name')->fetchAll();
    $recipeRows = $pdo->query('SELECT menu_id,item_id,quantity FROM recipes ORDER BY id')->fetchAll();
    $recipeMap = [];
    foreach ($recipeRows as $recipeRow) {
        $recipeMap[(int) $recipeRow['menu_id']][] = [
            'item_id' => (int) $recipeRow['item_id'],
            'quantity' => (float) $recipeRow['quantity'],
        ];
    }
    ?>
    <div class="page-head"><div><h2>Menu dan resep standar</h2><p>Hubungkan produk jual dengan bahan baku untuk pencatatan produksi otomatis.</p></div><button class="btn btn-primary" data-new-target="menuModal" data-new-title="Tambah Menu & Resep"><?= icon('plus') ?> Tambah Menu</button></div>
    <div class="grid grid-3">
    <?php foreach ($menus as $menu): $margin = (float)$menu['selling_price']-(float)$menu['recipe_cost']; ?>
        <article class="card"><div class="card-body"><div style="display:flex;justify-content:space-between;gap:12px"><div><span class="badge badge-<?= $menu['is_active']?'success':'neutral' ?>"><?= $menu['is_active']?'Aktif':'Nonaktif' ?></span><h3 style="margin:12px 0 3px"><?= e($menu['name']) ?></h3><div class="text-muted" style="font-size:11px"><?= e($menu['code']) ?> · <?= (int)$menu['ingredient_count'] ?> bahan</div></div><div class="metric-icon"><?= icon('utensils') ?></div></div><div class="grid grid-2" style="margin-top:18px;gap:10px"><div><span class="text-muted" style="font-size:10px">HARGA JUAL</span><strong style="display:block"><?= money($menu['selling_price']) ?></strong></div><div><span class="text-muted" style="font-size:10px">HPP RESEP</span><strong style="display:block"><?= money($menu['recipe_cost']) ?></strong></div></div><div style="margin-top:12px;padding:10px;border-radius:10px;background:#f8faf9;font-size:12px">Margin bahan: <strong class="<?= $margin>=0?'text-success':'text-danger' ?>"><?= money($margin) ?></strong></div><div class="actions" style="margin-top:15px"><button class="btn btn-primary btn-sm" style="flex:1" data-produce-menu data-menu-id="<?= $menu['id'] ?>" data-menu-name="<?= e($menu['name']) ?>">Catat Produksi</button><button class="btn btn-secondary btn-icon btn-sm" type="button" title="Edit menu" data-edit-menu='<?= e(json_encode(['id'=>(int)$menu['id'],'code'=>$menu['code'],'name'=>$menu['name'],'selling_price'=>(float)$menu['selling_price'],'is_active'=>(int)$menu['is_active'],'recipes'=>$recipeMap[(int)$menu['id']] ?? []], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'><?= icon('edit') ?></button><?php if(is_owner()): ?><form method="post" data-confirm="Hapus menu dan resep ini?"><input type="hidden" name="_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete_menu"><input type="hidden" name="return_page" value="menus"><input type="hidden" name="id" value="<?= $menu['id'] ?>"><button class="btn btn-danger btn-icon btn-sm"><?= icon('trash') ?></button></form><?php endif; ?></div></div></article>
    <?php endforeach; ?>
    </div>

    <div id="menuModal" class="modal-backdrop"><div class="modal modal-lg"><div class="modal-header"><h3 data-modal-title>Tambah Menu & Resep</h3><button class="modal-close" data-modal-close type="button">&times;</button></div><form method="post"><input type="hidden" name="_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="save_menu"><input type="hidden" name="return_page" value="menus"><input type="hidden" name="id"><div class="modal-body"><div class="form-grid" style="margin-bottom:18px"><div class="form-group"><label>Kode Menu *</label><input class="form-control" name="code" placeholder="MNU-001" required></div><div class="form-group"><label>Nama Menu *</label><input class="form-control" name="name" required></div><div class="form-group"><label>Harga Jual</label><input class="form-control" type="number" min="0" name="selling_price" value="0"></div><div class="form-group"><div class="check-row"><input type="checkbox" id="menu_active" name="is_active" value="1" checked><label for="menu_active">Menu aktif</label></div></div></div><div class="page-head" style="margin-bottom:10px"><div><h2 style="font-size:15px">Komposisi resep per porsi</h2><p>Masukkan jumlah pemakaian sesuai satuan bahan.</p></div><button class="btn btn-secondary btn-sm" type="button" data-add-recipe-line><?= icon('plus') ?> Tambah Bahan</button></div><div class="dynamic-lines" data-recipe-lines></div></div><div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Batal</button><button class="btn btn-primary">Simpan Menu</button></div></form></div></div>
    <template id="recipe-line-template"><div class="recipe-line" data-line><div class="form-group"><label>Bahan *</label><select class="form-select" name="recipe_item_id[]" required><option value="">Pilih bahan</option><?php foreach($items as $item): ?><option value="<?= $item['id'] ?>"><?= e($item['sku'].' · '.$item['name'].' ('.$item['unit'].')') ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Jumlah/Porsi *</label><input class="form-control" type="number" min="0.001" step="0.001" name="recipe_quantity[]" required></div><button class="remove-line" type="button" data-remove-line>&times;</button></div></template>

    <div id="produceMenuModal" class="modal-backdrop"><div class="modal"><div class="modal-header"><h3>Catat Produksi</h3><button class="modal-close" data-modal-close type="button">&times;</button></div><form method="post"><input type="hidden" name="_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="produce_menu"><input type="hidden" name="return_page" value="menus"><input type="hidden" name="menu_id"><div class="modal-body"><div class="alert alert-info"><span>Produksi <strong data-menu-name></strong> akan otomatis mengurangi seluruh bahan sesuai resep.</span></div><div class="form-group"><label>Jumlah Porsi *</label><input class="form-control" type="number" min="1" step="1" name="portions" value="1" required></div></div><div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Batal</button><button class="btn btn-primary">Proses Produksi</button></div></form></div></div>
    <?php
}

function render_alerts(): void
{
    $days = max(1, (int)setting('expiry_warning_days','30'));
    $pdo = db();
    $low = $pdo->query('SELECT i.*,c.name AS category_name FROM items i LEFT JOIN categories c ON c.id=i.category_id WHERE i.is_active=1 AND i.current_stock<=i.min_stock ORDER BY (i.current_stock-i.min_stock) ASC')->fetchAll();
    $stmt = $pdo->prepare("SELECT ti.expiry_date,ti.batch_no,ti.quantity,i.name,i.sku,i.unit,t.transaction_no FROM transaction_items ti JOIN items i ON i.id=ti.item_id JOIN transactions t ON t.id=ti.transaction_id WHERE ti.expiry_date IS NOT NULL AND ti.expiry_date!='' AND date(ti.expiry_date)<=date('now',?) ORDER BY ti.expiry_date");
    $stmt->execute(['+' . $days . ' day']);
    $expiries = $stmt->fetchAll();
    ?>
    <div class="page-head"><div><h2>Pusat peringatan</h2><p>Prioritaskan restock dan pemeriksaan bahan mendekati kedaluwarsa.</p></div><div class="actions"><a class="btn btn-primary" href="<?= url('stock') ?>"><?= icon('plus') ?> Catat Restock</a></div></div>
    <div class="grid grid-2">
        <section class="card"><div class="card-header"><div><h3>Stok rendah</h3><p><?= count($low) ?> bahan membutuhkan perhatian</p></div><span class="badge badge-danger"><?= count($low) ?></span></div><?php if($low): ?><div class="card-body"><div class="list"><?php foreach($low as $item): ?><div class="list-item"><div><strong><?= e($item['name']) ?></strong><span><?= e($item['sku']) ?> · <?= e($item['category_name']?:'-') ?></span></div><div class="text-right"><strong class="text-danger"><?= qty($item['current_stock']) ?> <?= e($item['unit']) ?></strong><span>Minimum <?= qty($item['min_stock']) ?></span></div></div><?php endforeach; ?></div></div><?php else: ?><div class="empty-state"><?= icon('box') ?><strong>Stok dalam kondisi aman</strong><p>Tidak ada bahan di bawah minimum.</p></div><?php endif; ?></section>
        <section class="card"><div class="card-header"><div><h3>Mendekati kedaluwarsa</h3><p>Dalam <?= $days ?> hari ke depan</p></div><span class="badge badge-warning"><?= count($expiries) ?></span></div><?php if($expiries): ?><div class="card-body"><div class="list"><?php foreach($expiries as $row): $expired=strtotime($row['expiry_date'])<strtotime(date('Y-m-d')); ?><div class="list-item"><div><strong><?= e($row['name']) ?></strong><span>Batch <?= e($row['batch_no']?:'-') ?> · <?= e($row['transaction_no']) ?></span></div><div class="text-right"><strong class="<?= $expired?'text-danger':'' ?>"><?= e(date('d M Y',strtotime($row['expiry_date']))) ?></strong><span><?= qty($row['quantity']) ?> <?= e($row['unit']) ?></span></div></div><?php endforeach; ?></div></div><?php else: ?><div class="empty-state"><?= icon('calendar') ?><strong>Tidak ada peringatan kedaluwarsa</strong><p>Data akan muncul dari transaksi masuk yang memiliki tanggal kedaluwarsa.</p></div><?php endif; ?></section>
    </div>
    <?php
}

function export_report_csv(): never
{
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-d');
    $type = $_GET['type'] ?? '';
    $params = [$from, $to];
    $where = 'WHERE t.transaction_date BETWEEN ? AND ?';
    if ($type !== '') {
        $where .= ' AND t.type=?';
        $params[] = $type;
    }
    $stmt = db()->prepare("SELECT t.*,u.name AS user_name,s.name AS supplier_name FROM transactions t JOIN users u ON u.id=t.user_id LEFT JOIN suppliers s ON s.id=t.supplier_id $where ORDER BY t.transaction_date DESC,t.id DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan-stockbite-' . $from . '-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Nomor Transaksi', 'Tanggal', 'Jenis', 'Supplier/Referensi', 'Nilai', 'Petugas', 'Catatan'], ';');
    foreach ($rows as $row) {
        fputcsv($out, [$row['transaction_no'], $row['transaction_date'], transaction_label($row['type']), $row['supplier_name'] ?: $row['reference'], $row['total_value'], $row['user_name'], $row['notes']], ';');
    }
    fclose($out);
    exit;
}

function render_reports(): void
{
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-d');
    $type = $_GET['type'] ?? '';
    $params = [$from,$to];
    $where = 'WHERE t.transaction_date BETWEEN ? AND ?';
    if ($type !== '') { $where .= ' AND t.type=?'; $params[]=$type; }
    $stmt = db()->prepare("SELECT t.*,u.name AS user_name,s.name AS supplier_name FROM transactions t JOIN users u ON u.id=t.user_id LEFT JOIN suppliers s ON s.id=t.supplier_id $where ORDER BY t.transaction_date DESC,t.id DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $incoming = 0.0; $outgoing = 0.0;
    foreach($rows as $row){ if(in_array($row['type'],['IN','ADJUSTMENT_PLUS'],true))$incoming+=(float)$row['total_value']; else $outgoing+=(float)$row['total_value']; }

    ?>
    <div class="page-head"><div><h2>Laporan mutasi persediaan</h2><p>Filter transaksi berdasarkan periode dan jenis, lalu ekspor ke CSV.</p></div><div class="actions no-print"><a class="btn btn-secondary" href="<?= url('reports',['from'=>$from,'to'=>$to,'type'=>$type,'export'=>'csv']) ?>"><?= icon('download') ?> Ekspor CSV</a><button class="btn btn-primary" onclick="window.print()">Cetak</button></div></div>
    <div class="report-tabs no-print"><a href="<?= url('sales_reports') ?>"><?= icon('receipt') ?> Penjualan</a><a href="<?= url('reports',['from'=>$from,'to'=>$to,'type'=>$type]) ?>" class="active"><?= icon('chart') ?> Persediaan</a></div>
    <section class="card no-print"><form class="toolbar" method="get"><input type="hidden" name="page" value="reports"><div class="form-group"><label>Dari</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div><div class="form-group"><label>Sampai</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div><div class="form-group"><label>Jenis</label><select class="form-select" name="type"><option value="">Semua transaksi</option><?php foreach(['IN','OUT','WASTE','ADJUSTMENT_PLUS','ADJUSTMENT_MINUS','PRODUCTION','SALE'] as $option): ?><option value="<?= $option ?>" <?= $type===$option?'selected':'' ?>><?= e(transaction_label($option)) ?></option><?php endforeach; ?></select></div><div class="form-group" style="align-self:end"><button class="btn btn-primary">Terapkan Filter</button></div></form></section>
    <div class="grid grid-3" style="margin:18px 0"><article class="card metric-card"><div class="metric-copy"><span>Total Transaksi</span><strong><?= count($rows) ?></strong><small><?= e(date('d M Y',strtotime($from))) ?> – <?= e(date('d M Y',strtotime($to))) ?></small></div><div class="metric-icon"><?= icon('swap') ?></div></article><article class="card metric-card"><div class="metric-copy"><span>Nilai Masuk</span><strong><?= money($incoming) ?></strong><small>Stok masuk dan penyesuaian tambah</small></div><div class="metric-icon"><?= icon('arrow-down') ?></div></article><article class="card metric-card warning"><div class="metric-copy"><span>Nilai Pemakaian</span><strong><?= money($outgoing) ?></strong><small>Keluar, waste, dan produksi</small></div><div class="metric-icon"><?= icon('arrow-up') ?></div></article></div>
    <section class="card"><div class="card-header"><div><h3>Detail transaksi</h3><p><?= count($rows) ?> data ditemukan</p></div></div><div class="table-wrap"><table class="responsive-table"><thead><tr><th>Nomor</th><th>Tanggal</th><th>Jenis</th><th>Supplier/Ref</th><th>Nilai</th><th>Petugas</th><th>Catatan</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td class="mobile-main"><strong><?= e($row['transaction_no']) ?></strong></td><td data-label="Tanggal"><?= e(date('d M Y',strtotime($row['transaction_date']))) ?></td><td data-label="Jenis"><span class="badge badge-info"><?= e(transaction_label($row['type'])) ?></span></td><td data-label="Supplier/Ref"><?= e($row['supplier_name']?:($row['reference']?:'-')) ?></td><td data-label="Nilai" class="fw-bold"><?= money($row['total_value']) ?></td><td data-label="Petugas"><?= e($row['user_name']) ?></td><td data-label="Catatan"><?= e($row['notes']?:'-') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php
}

function report_date(string $value, string $fallback): string
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
}

function sales_report_filters(): array
{
    $from = report_date((string) ($_GET['from'] ?? date('Y-m-01')), date('Y-m-01'));
    $to = report_date((string) ($_GET['to'] ?? date('Y-m-d')), date('Y-m-d'));
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $payment = strtoupper(trim((string) ($_GET['payment'] ?? '')));
    if (!in_array($payment, ['', 'CASH', 'QRIS', 'TRANSFER', 'DEBIT'], true)) $payment = '';
    $orderType = strtoupper(trim((string) ($_GET['order_type'] ?? '')));
    if (!in_array($orderType, ['', 'DINE_IN', 'TAKEAWAY', 'DELIVERY'], true)) $orderType = '';
    $cashierId = max(0, (int) ($_GET['cashier_id'] ?? 0));
    $search = substr(trim((string) ($_GET['search'] ?? '')), 0, 80);

    $where = 'WHERE s.sold_at BETWEEN ? AND ?';
    $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
    if ($payment !== '') {
        $where .= ' AND s.payment_method=?';
        $params[] = $payment;
    }
    if ($orderType !== '') {
        $where .= ' AND s.order_type=?';
        $params[] = $orderType;
    }
    if ($cashierId > 0) {
        $where .= ' AND s.user_id=?';
        $params[] = $cashierId;
    }
    if ($search !== '') {
        $where .= " AND (s.sale_no LIKE ? OR COALESCE(s.customer_name,'') LIKE ?)";
        $needle = '%' . $search . '%';
        $params[] = $needle;
        $params[] = $needle;
    }

    return compact('from', 'to', 'payment', 'orderType', 'cashierId', 'search', 'where', 'params');
}

function sales_report_rows(array $filters): array
{
    $sql = "SELECT s.*,u.name AS cashier_name,COALESCE(t.total_value,0) AS cogs,
            COALESCE((SELECT SUM(si.quantity) FROM sale_items si WHERE si.sale_id=s.id),0) AS items_sold,
            COALESCE((SELECT GROUP_CONCAT(m.name || ' x' || si.quantity, ', ') FROM sale_items si JOIN menus m ON m.id=si.menu_id WHERE si.sale_id=s.id),'') AS item_names
            FROM sales s
            JOIN users u ON u.id=s.user_id
            LEFT JOIN transactions t ON t.id=s.transaction_id
            {$filters['where']}
            ORDER BY s.sold_at DESC,s.id DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute($filters['params']);
    return $stmt->fetchAll();
}

function export_sales_report_csv(): never
{
    $filters = sales_report_filters();
    $rows = sales_report_rows($filters);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan-penjualan-stockbite-' . $filters['from'] . '-' . $filters['to'] . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Invoice', 'Tanggal', 'Waktu', 'Pelanggan', 'Jenis Pesanan', 'Metode Pembayaran', 'Detail Menu', 'Jumlah Item', 'Subtotal', 'Diskon', 'Total Penjualan', 'HPP', 'Laba Kotor', 'Kasir', 'Catatan'], ';');
    foreach ($rows as $row) {
        $soldAt = strtotime($row['sold_at']);
        fputcsv($out, [
            $row['sale_no'],
            date('Y-m-d', $soldAt),
            date('H:i:s', $soldAt),
            $row['customer_name'] ?: 'Umum',
            order_type_label($row['order_type']),
            payment_label($row['payment_method']),
            $row['item_names'],
            $row['items_sold'],
            $row['subtotal'],
            $row['discount'],
            $row['total'],
            $row['cogs'],
            (float) $row['total'] - (float) $row['cogs'],
            $row['cashier_name'],
            $row['notes'],
        ], ';');
    }
    fclose($out);
    exit;
}

function render_sales_reports(): void
{
    $filters = sales_report_filters();
    $rows = sales_report_rows($filters);
    $cashiers = db()->query("SELECT id,name FROM users WHERE role IN ('owner','staff') ORDER BY name")->fetchAll();

    $summary = [
        'orders' => count($rows),
        'gross' => 0.0,
        'discount' => 0.0,
        'net' => 0.0,
        'cogs' => 0.0,
        'items' => 0.0,
    ];
    $daily = [];
    $payments = [];
    foreach ($rows as $row) {
        $summary['gross'] += (float) $row['subtotal'];
        $summary['discount'] += (float) $row['discount'];
        $summary['net'] += (float) $row['total'];
        $summary['cogs'] += (float) $row['cogs'];
        $summary['items'] += (float) $row['items_sold'];
        $day = date('Y-m-d', strtotime($row['sold_at']));
        if (!isset($daily[$day])) $daily[$day] = ['orders' => 0, 'revenue' => 0.0];
        $daily[$day]['orders']++;
        $daily[$day]['revenue'] += (float) $row['total'];
        $method = $row['payment_method'];
        if (!isset($payments[$method])) $payments[$method] = ['orders' => 0, 'revenue' => 0.0];
        $payments[$method]['orders']++;
        $payments[$method]['revenue'] += (float) $row['total'];
    }
    ksort($daily);
    uasort($payments, fn(array $a, array $b) => $b['revenue'] <=> $a['revenue']);
    $summary['profit'] = $summary['net'] - $summary['cogs'];
    $summary['average'] = $summary['orders'] > 0 ? $summary['net'] / $summary['orders'] : 0.0;
    $maxDaily = max(array_map(fn(array $row): float => (float) $row['revenue'], $daily) ?: [1]);
    $maxPayment = max(array_map(fn(array $row): float => (float) $row['revenue'], $payments) ?: [1]);

    $topSql = "SELECT m.code,m.name,SUM(si.quantity) AS quantity,SUM(si.line_total) AS revenue
               FROM sale_items si
               JOIN sales s ON s.id=si.sale_id
               JOIN menus m ON m.id=si.menu_id
               {$filters['where']}
               GROUP BY m.id,m.code,m.name
               ORDER BY revenue DESC,quantity DESC
               LIMIT 10";
    $stmt = db()->prepare($topSql);
    $stmt->execute($filters['params']);
    $topMenus = $stmt->fetchAll();

    $query = [
        'from' => $filters['from'],
        'to' => $filters['to'],
        'payment' => $filters['payment'],
        'order_type' => $filters['orderType'],
        'cashier_id' => $filters['cashierId'],
        'search' => $filters['search'],
    ];
    ?>
    <div class="page-head">
        <div><h2>Laporan penjualan</h2><p>Analisis omzet, transaksi, metode pembayaran, produk terlaris, HPP, dan laba kotor.</p></div>
        <div class="actions no-print"><a class="btn btn-secondary" href="<?= url('sales_reports', array_merge($query, ['export' => 'csv'])) ?>"><?= icon('download') ?> Ekspor CSV</a><button class="btn btn-secondary" type="button" onclick="window.print()"><?= icon('printer') ?> Cetak</button><a class="btn btn-primary" href="<?= url('cashier') ?>"><?= icon('cart') ?> Buka Kasir</a></div>
    </div>

    <div class="report-tabs no-print"><a href="<?= url('sales_reports', $query) ?>" class="active"><?= icon('receipt') ?> Penjualan</a><a href="<?= url('reports') ?>"><?= icon('chart') ?> Persediaan</a></div>

    <section class="card no-print">
        <form class="toolbar sales-filter" method="get">
            <input type="hidden" name="page" value="sales_reports">
            <div class="form-group"><label>Dari</label><input class="form-control" type="date" name="from" value="<?= e($filters['from']) ?>"></div>
            <div class="form-group"><label>Sampai</label><input class="form-control" type="date" name="to" value="<?= e($filters['to']) ?>"></div>
            <div class="form-group"><label>Pembayaran</label><select class="form-select" name="payment"><option value="">Semua metode</option><?php foreach (['CASH','QRIS','TRANSFER','DEBIT'] as $method): ?><option value="<?= $method ?>" <?= $filters['payment'] === $method ? 'selected' : '' ?>><?= e(payment_label($method)) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Jenis Pesanan</label><select class="form-select" name="order_type"><option value="">Semua jenis</option><?php foreach (['DINE_IN','TAKEAWAY','DELIVERY'] as $type): ?><option value="<?= $type ?>" <?= $filters['orderType'] === $type ? 'selected' : '' ?>><?= e(order_type_label($type)) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Kasir</label><select class="form-select" name="cashier_id"><option value="0">Semua kasir</option><?php foreach ($cashiers as $cashier): ?><option value="<?= (int) $cashier['id'] ?>" <?= $filters['cashierId'] === (int) $cashier['id'] ? 'selected' : '' ?>><?= e($cashier['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group sales-search"><label>Invoice/Pelanggan</label><input class="form-control" name="search" value="<?= e($filters['search']) ?>" placeholder="Cari invoice atau pelanggan"></div>
            <div class="form-group filter-action"><button class="btn btn-primary">Terapkan</button><a class="btn btn-secondary" href="<?= url('sales_reports') ?>">Reset</a></div>
        </form>
    </section>

    <div class="grid grid-4 report-metrics" style="margin:18px 0">
        <article class="card metric-card info"><div class="metric-copy"><span>Omzet Bersih</span><strong><?= money($summary['net']) ?></strong><small>Setelah diskon <?= money($summary['discount']) ?></small></div><div class="metric-icon"><?= icon('wallet') ?></div></article>
        <article class="card metric-card"><div class="metric-copy"><span>Total Transaksi</span><strong><?= $summary['orders'] ?></strong><small>Rata-rata <?= money($summary['average']) ?></small></div><div class="metric-icon"><?= icon('receipt') ?></div></article>
        <article class="card metric-card"><div class="metric-copy"><span>Item Terjual</span><strong><?= qty($summary['items']) ?></strong><small>Akumulasi semua menu</small></div><div class="metric-icon"><?= icon('cart') ?></div></article>
        <article class="card metric-card <?= $summary['profit'] < 0 ? 'danger' : 'warning' ?>"><div class="metric-copy"><span>Estimasi Laba Kotor</span><strong><?= money($summary['profit']) ?></strong><small>HPP <?= money($summary['cogs']) ?></small></div><div class="metric-icon"><?= icon('chart') ?></div></article>
    </div>

    <div class="print-report-heading"><h2><?= e(setting('business_name', 'StockBite F&B')) ?></h2><p>Laporan Penjualan · <?= e(date('d M Y', strtotime($filters['from']))) ?> – <?= e(date('d M Y', strtotime($filters['to']))) ?></p></div>

    <div class="grid grid-2 report-panels">
        <section class="card">
            <div class="card-header"><div><h3>Tren penjualan harian</h3><p>Omzet bersih per hari pada periode terpilih</p></div></div>
            <div class="card-body">
                <?php if ($daily): ?><div class="sales-chart">
                    <?php foreach ($daily as $day => $value): $percent = max(3, min(100, ($value['revenue'] / $maxDaily) * 100)); ?>
                        <div class="sales-chart-row"><span><?= e(date('d M', strtotime($day))) ?></span><div class="sales-chart-track"><i style="width:<?= $percent ?>%"></i></div><strong><?= money($value['revenue']) ?><small><?= $value['orders'] ?> trx</small></strong></div>
                    <?php endforeach; ?>
                </div><?php else: ?><div class="empty-state"><?= icon('chart') ?><strong>Belum ada penjualan</strong><p>Transaksi kasir pada periode ini akan tampil sebagai tren harian.</p></div><?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-header"><div><h3>Metode pembayaran</h3><p>Kontribusi omzet berdasarkan cara bayar</p></div></div>
            <div class="card-body">
                <?php if ($payments): ?><div class="payment-breakdown">
                    <?php foreach ($payments as $method => $value): $percent = max(3, min(100, ($value['revenue'] / $maxPayment) * 100)); ?>
                        <div class="payment-row"><div><strong><?= e(payment_label($method)) ?></strong><span><?= $value['orders'] ?> transaksi</span></div><div class="progress"><span style="width:<?= $percent ?>%"></span></div><strong><?= money($value['revenue']) ?></strong></div>
                    <?php endforeach; ?>
                </div><?php else: ?><div class="empty-state"><?= icon('wallet') ?><strong>Belum ada pembayaran</strong><p>Ringkasan metode pembayaran akan tampil setelah transaksi kasir.</p></div><?php endif; ?>
            </div>
        </section>
    </div>

    <section class="card" style="margin-top:18px">
        <div class="card-header"><div><h3>Menu terlaris</h3><p>Maksimal 10 menu berdasarkan omzet</p></div></div>
        <?php if ($topMenus): ?><div class="table-wrap"><table class="responsive-table"><thead><tr><th>Peringkat</th><th>Menu</th><th>Kode</th><th>Jumlah Terjual</th><th>Omzet</th></tr></thead><tbody><?php foreach ($topMenus as $index => $menu): ?><tr><td data-label="Peringkat"><span class="rank-badge"><?= $index + 1 ?></span></td><td class="mobile-main"><strong><?= e($menu['name']) ?></strong></td><td data-label="Kode"><?= e($menu['code']) ?></td><td data-label="Jumlah Terjual"><?= qty($menu['quantity']) ?> porsi</td><td data-label="Omzet" class="fw-bold"><?= money($menu['revenue']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><?= icon('utensils') ?><strong>Belum ada menu terjual</strong><p>Produk terlaris akan dihitung otomatis dari transaksi kasir.</p></div><?php endif; ?>
    </section>

    <section class="card" style="margin-top:18px">
        <div class="card-header"><div><h3>Detail transaksi penjualan</h3><p><?= count($rows) ?> transaksi ditemukan</p></div><div class="report-total"><span>Penjualan Kotor</span><strong><?= money($summary['gross']) ?></strong></div></div>
        <?php if ($rows): ?><div class="table-wrap"><table class="responsive-table sales-report-table"><thead><tr><th>Invoice</th><th>Waktu</th><th>Menu</th><th>Pelanggan</th><th>Pesanan</th><th>Pembayaran</th><th>Subtotal</th><th>Diskon</th><th>Total</th><th>Kasir</th><th class="no-print">Aksi</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?>
            <tr><td class="mobile-main"><strong><?= e($row['sale_no']) ?></strong><span class="mobile-sub"><?= e(date('d M Y H:i', strtotime($row['sold_at']))) ?></span></td><td data-label="Waktu"><?= e(date('d M Y H:i', strtotime($row['sold_at']))) ?></td><td data-label="Menu" class="sales-item-list"><?= e($row['item_names'] ?: '-') ?></td><td data-label="Pelanggan"><?= e($row['customer_name'] ?: 'Umum') ?></td><td data-label="Pesanan"><?= e(order_type_label($row['order_type'])) ?></td><td data-label="Pembayaran"><span class="badge badge-info"><?= e(payment_label($row['payment_method'])) ?></span></td><td data-label="Subtotal"><?= money($row['subtotal']) ?></td><td data-label="Diskon"><?= money($row['discount']) ?></td><td data-label="Total" class="fw-bold"><?= money($row['total']) ?></td><td data-label="Kasir"><?= e($row['cashier_name']) ?></td><td data-label="Aksi" class="no-print"><a class="btn btn-secondary btn-sm" href="<?= url('cashier', ['receipt' => $row['id']]) ?>">Struk</a></td></tr>
        <?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><?= icon('receipt') ?><strong>Tidak ada transaksi sesuai filter</strong><p>Ubah periode atau filter, atau lakukan transaksi melalui menu kasir.</p><a class="btn btn-primary" href="<?= url('cashier') ?>">Buka Kasir</a></div><?php endif; ?>
    </section>
    <?php
}

function render_settings(): void
{
    ?>
    <div class="page-head"><div><h2>Pengaturan aplikasi</h2><p>Sesuaikan identitas usaha dan parameter peringatan.</p></div></div>
    <div class="grid grid-2"><section class="card"><div class="card-header"><div><h3>Profil usaha</h3><p>Informasi yang ditampilkan pada aplikasi.</p></div></div><form method="post"><input type="hidden" name="_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="save_settings"><input type="hidden" name="return_page" value="settings"><div class="card-body"><div class="form-group" style="margin-bottom:14px"><label>Nama Usaha</label><input class="form-control" name="business_name" value="<?= e(setting('business_name')) ?>" required></div><div class="form-grid"><div class="form-group"><label>Simbol Mata Uang</label><input class="form-control" name="currency" value="<?= e(setting('currency')) ?>" required></div><div class="form-group"><label>Peringatan Kedaluwarsa</label><input class="form-control" type="number" min="1" name="expiry_warning_days" value="<?= e(setting('expiry_warning_days')) ?>"><div class="form-hint">Jumlah hari sebelum tanggal kedaluwarsa.</div></div></div></div><div class="modal-footer"><button class="btn btn-primary">Simpan Pengaturan</button></div></form></section><section class="card"><div class="card-header"><div><h3>Informasi sistem</h3><p>Konfigurasi instalasi saat ini.</p></div></div><div class="card-body"><div class="list"><div class="list-item"><div><strong>Versi Aplikasi</strong><span>StockBite Inventory</span></div><strong><?= APP_VERSION ?></strong></div><div class="list-item"><div><strong>Database</strong><span>Penyimpanan lokal tanpa konfigurasi</span></div><span class="badge badge-success">SQLite</span></div><div class="list-item"><div><strong>Zona Waktu</strong><span>Waktu operasional</span></div><strong>Asia/Jakarta</strong></div><div class="list-item"><div><strong>Lokasi Database</strong><span>Pastikan folder storage dapat ditulis</span></div><strong style="font-size:11px">storage/stockbite.sqlite</strong></div></div></div></section></div>
    <?php
}
