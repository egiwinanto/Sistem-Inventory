<?php

declare(strict_types=1);

function render_header(string $page): void
{
    $user = auth_user();
    $title = page_title($page);
    $business = setting('business_name', 'StockBite F&B');
    $flashes = get_flashes();
    $nav = [
        'dashboard' => ['Dashboard', 'grid'],
        'cashier' => ['Kasir', 'cart'],
        'sales_reports' => ['Laporan Penjualan', 'receipt'],
        'items' => ['Bahan & Stok', 'box'],
        'stock' => ['Transaksi', 'swap'],
        'menus' => ['Menu & Resep', 'utensils'],
        'suppliers' => ['Supplier', 'truck'],
        'alerts' => ['Peringatan', 'bell'],
        'reports' => ['Laporan Persediaan', 'chart'],
        'settings' => ['Pengaturan', 'settings'],
    ];
    ?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f766e">
    <title><?= e($title) ?> · <?= e($business) ?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?= APP_VERSION ?>">
</head>
<body>
<div class="app-shell" data-sidebar-shell>
    <aside class="sidebar" data-sidebar>
        <div class="brand">
            <div class="brand-mark">SB</div>
            <div>
                <strong><?= e($business) ?></strong>
                <span>Inventory F&amp;B</span>
            </div>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($nav as $key => [$label, $icon]): ?>
                <?php if ($key === 'settings' && !is_owner()) continue; ?>
                <a class="nav-link <?= $page === $key ? 'active' : '' ?>" href="<?= url($key) ?>">
                    <?= icon($icon) ?>
                    <span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-card">
                <div class="avatar"><?= e(strtoupper(substr($user['name'] ?? 'U', 0, 1))) ?></div>
                <div>
                    <strong><?= e($user['name'] ?? '') ?></strong>
                    <span><?= e(ucfirst($user['role'] ?? '')) ?></span>
                </div>
            </div>
            <a class="logout-link" href="?action=logout" data-confirm-logout><?= icon('logout') ?> Logout</a>
        </div>
    </aside>

    <div class="sidebar-overlay" data-sidebar-overlay></div>

    <main class="main-content">
        <header class="topbar">
            <button class="icon-button menu-button" type="button" data-sidebar-toggle aria-label="Buka menu"><?= icon('menu') ?></button>
            <div>
                <p class="eyebrow"><?= e(date('l, d F Y')) ?></p>
                <h1><?= e($title) ?></h1>
            </div>
            <div class="topbar-actions">
                <a class="icon-button topbar-alert" href="<?= url('alerts') ?>" aria-label="Peringatan" title="Peringatan"><?= icon('bell') ?></a>
                <a class="icon-button topbar-logout" href="?action=logout" data-confirm-logout aria-label="Logout" title="Logout"><?= icon('logout') ?></a>
                <div class="avatar desktop-avatar"><?= e(strtoupper(substr($user['name'] ?? 'U', 0, 1))) ?></div>
            </div>
        </header>

        <section class="content-wrap">
            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>" data-auto-dismiss>
                    <span><?= e($flash['message']) ?></span>
                    <button type="button" aria-label="Tutup" data-dismiss>&times;</button>
                </div>
            <?php endforeach; ?>
    <?php
}

function render_footer(string $page): void
{
    $mobileNav = [
        'dashboard' => ['Beranda', 'grid'],
        'cashier' => ['Kasir', 'cart'],
        'items' => ['Stok', 'box'],
        'sales_reports' => ['Penjualan', 'receipt'],
        'logout' => ['Logout', 'logout'],
    ];
    ?>
        </section>
    </main>

    <nav class="mobile-nav" aria-label="Navigasi mobile">
        <?php foreach ($mobileNav as $key => [$label, $icon]): ?>
            <?php $isLogout = $key === 'logout'; ?>
            <a href="<?= $isLogout ? '?action=logout' : url($key) ?>"
               class="<?= $isLogout ? 'mobile-logout' : ($page === $key ? 'active' : '') ?>"
               <?= $isLogout ? 'data-confirm-logout' : '' ?>>
                <?= icon($icon) ?><span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</div>
<script src="assets/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
    <?php
}

function icon(string $name): string
{
    $paths = [
        'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
        'box' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.29 7 12 12 20.71 7"/><line x1="12" y1="22" x2="12" y2="12"/>',
        'swap' => '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
        'utensils' => '<path d="M3 2v7a3 3 0 0 0 6 0V2"/><line x1="6" y1="2" x2="6" y2="22"/><path d="M21 15V2a5 5 0 0 0-5 5v6a2 2 0 0 0 2 2z"/><line x1="21" y1="15" x2="21" y2="22"/>',
        'truck' => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'chart' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.65 1.65 0 0 0 15 19.4a1.65 1.65 0 0 0-1 .6 1.65 1.65 0 0 0-.4 1.08V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 8.6 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-.6-1 1.65 1.65 0 0 0-1.08-.4H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 8.6a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-.6 1.65 1.65 0 0 0 .4-1.08V3a2 2 0 1 1 4 0v.09A1.65 1.65 0 0 0 15.4 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.14.37.35.7.6 1 .28.3.65.48 1.08.5H21a2 2 0 1 1 0 4h-.09c-.42.02-.8.2-1.08.5-.25.3-.46.63-.6 1z"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'menu' => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'plus-circle' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6m3 0V4h8v2"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'arrow-up' => '<line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/>',
        'arrow-down' => '<line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/>',
        'alert' => '<path d="M10.3 2.9L1.8 17a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 2.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'wallet' => '<rect x="2" y="5" width="20" height="15" rx="2"/><path d="M16 13h6"/><path d="M2 10h20"/>',
        'package' => '<path d="M16.5 9.4L7.55 4.24"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.29 7 12 12 20.71 7"/><line x1="12" y1="22" x2="12" y2="12"/>',
        'cart' => '<circle cx="9" cy="20" r="1"/><circle cx="19" cy="20" r="1"/><path d="M3 4h2l2.7 11.2a2 2 0 0 0 2 1.5h7.7a2 2 0 0 0 2-1.6L21 8H7"/>',
        'receipt' => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"/><line x1="9" y1="7" x2="15" y2="7"/><line x1="9" y1="11" x2="15" y2="11"/><line x1="9" y1="15" x2="13" y2="15"/>',
        'printer' => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'check' => '<polyline points="20 6 9 17 4 12"/>',
    ];
    $path = $paths[$name] ?? $paths['box'];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}
