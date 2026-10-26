<?php

// =============================================
// 1. SESSION DAN KEAMANAN LOGIN
// =============================================

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    !isset($_SESSION['user']['id']) ||
    !isset($_SESSION['user']['role']) ||
    !in_array(
        $_SESSION['user']['role'],
        ['admin', 'staff'],
        true
    )
) {
    header('Location: login.php');
    exit;
}

// =============================================
// 2. KONEKSI DATABASE
// =============================================

require_once __DIR__ . '/../config/database.php';

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

date_default_timezone_set('Asia/Jakarta');

$user = $_SESSION['user'];

// =============================================
// 3. MENGAMBIL DATA DASHBOARD
// =============================================

$stmt = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM products)
            AS total_products,

        (SELECT COUNT(*) FROM categories)
            AS total_categories,

        (SELECT COUNT(*) FROM suppliers)
            AS total_suppliers,

        (
            SELECT COALESCE(SUM(price * stock), 0)
            FROM products
        ) AS inventory_value,

        (
            SELECT COUNT(*)
            FROM products
            WHERE stock <= minimum_stock
        ) AS low_stock,

        (
            SELECT COALESCE(SUM(quantity), 0)
            FROM stock_movements
            WHERE `type` = 'IN'
        ) AS total_in,

        (
            SELECT COALESCE(SUM(quantity), 0)
            FROM stock_movements
            WHERE `type` = 'OUT'
        ) AS total_out
");

$summary = $stmt->fetch();

// Mengambil lima produk terakhir.
$productStmt = $pdo->query("
    SELECT
        p.id,
        p.sku,
        p.name,
        c.name AS category_name,
        p.price,
        p.stock,
        p.minimum_stock
    FROM products p
    INNER JOIN categories c
        ON c.id = p.category_id
    ORDER BY p.id DESC
    LIMIT 5
");

$products = $productStmt->fetchAll();

// Mengambil lima aktivitas stok terakhir.
$movementStmt = $pdo->query("
    SELECT
        sm.id,
        sm.`type`,
        sm.quantity,
        sm.created_at,
        p.name AS product_name,
        u.full_name
    FROM stock_movements sm
    INNER JOIN products p
        ON p.id = sm.product_id
    INNER JOIN users u
        ON u.id = sm.user_id
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT 5
");

$movements = $movementStmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Dashboard | Inventory Management</title>

    <style>
        /* =====================================
           WARNA TEMA SAMUDRA
        ===================================== */

        :root {
            --ocean-dark: #164e63;
            --ocean-text: #075985;
            --ocean-primary: #0284c7;
            --ocean-light: #dff4fc;
            --ocean-pale: #f0faff;
            --ocean-border: #cceaf5;
            --ocean-muted: #64748b;
            --white: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: var(--ocean-pale);
            color: var(--ocean-dark);
            line-height: 1.5;
        }

        a {
            color: inherit;
        }

        /* =====================================
           SIDEBAR
        ===================================== */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 245px;
            padding: 27px 19px;
            overflow-y: auto;

            background: linear-gradient(
                180deg,
                #e0f7ff,
                #d9f2fa,
                #c9edf7
            );

            border-right: 1px solid var(--ocean-border);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 38px;
        }

        .brand-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;

            width: 46px;
            height: 46px;
            border-radius: 14px;

            background: linear-gradient(
                135deg,
                #38bdf8,
                #5eead4
            );

            color: #083344;
            font-size: 24px;
            font-weight: bold;
        }

        .brand-name {
            color: #075985;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: 0.4px;
        }

        .brand-name small {
            display: block;
            margin-top: 3px;

            color: #0e7490;
            font-size: 11px;
            font-weight: normal;
            letter-spacing: 0;
        }

        .menu-label {
            margin: 0 0 13px 10px;

            color: #52859a;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1.1px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;

            padding: 12px 13px;
            margin-bottom: 7px;

            border-radius: 10px;

            color: #28667f;
            font-size: 13px;
            text-decoration: none;

            transition: 0.2s ease;
        }

        .nav-item:hover {
            background: rgba(125, 211, 252, 0.45);
            color: #075985;
            transform: translateX(2px);
        }

        .nav-item.active {
            background: linear-gradient(
                135deg,
                #7dd3fc,
                #99f6e4
            );

            color: #083344;
            font-weight: bold;

            box-shadow: 0 5px 12px rgba(14, 116, 144, 0.08);
        }

        .nav-icon {
            width: 23px;
            flex-shrink: 0;
            text-align: center;
            font-size: 17px;
        }

        .sidebar-bottom {
            margin-top: 28px;
            padding: 15px;

            border: 1px solid #bce5f3;
            border-radius: 12px;

            background: rgba(255, 255, 255, 0.55);
        }

        .sidebar-bottom strong {
            display: block;
            margin-bottom: 6px;
            font-size: 12px;
            color: #075985;
        }

        .sidebar-bottom p {
            margin: 0;
            color: #52859a;
            font-size: 11px;
            line-height: 1.7;
        }

        /* =====================================
           KONTEN UTAMA
        ===================================== */

        main {
            min-height: 100vh;
            margin-left: 245px;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .page-title {
            margin: 0 0 5px;
            color: var(--ocean-dark);
            font-size: 27px;
            font-weight: 800;
        }

        .page-subtitle {
            margin: 0;
            color: var(--ocean-muted);
            font-size: 13px;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .date-box {
            padding: 10px 13px;

            border: 1px solid var(--ocean-border);
            border-radius: 9px;

            background: white;
            color: #0e7490;
            font-size: 12px;
        }

        .logout-button {
            display: inline-block;
            padding: 10px 14px;

            border: 1px solid var(--ocean-border);
            border-radius: 9px;

            background: #dff4fc;
            color: #075985;

            font-size: 12px;
            font-weight: bold;
            text-decoration: none;

            transition: 0.2s;
        }

        .logout-button:hover {
            background: #bae6fd;
        }

        /* =====================================
           BANNER
        ===================================== */

        .welcome {
            position: relative;
            overflow: hidden;

            margin-bottom: 27px;
            padding: 28px;

            border: 1px solid #a5e6ee;
            border-radius: 17px;

            background: linear-gradient(
                120deg,
                #7dd3fc,
                #a5f3fc 55%,
                #99f6e4
            );

            box-shadow: 0 8px 22px rgba(14, 116, 144, 0.06);
        }

        .welcome::after {
            content: "";

            position: absolute;
            top: -90px;
            right: -45px;

            width: 200px;
            height: 200px;

            border: 28px solid rgba(255, 255, 255, 0.22);
            border-radius: 50%;
        }

        .welcome-content {
            position: relative;
            z-index: 1;
            max-width: 650px;
        }

        .welcome-label {
            display: inline-block;
            margin-bottom: 12px;
            padding: 5px 10px;

            border-radius: 20px;
            background: rgba(255, 255, 255, 0.55);

            color: #0e7490;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.6px;
        }

        .welcome h2 {
            margin: 0 0 10px;
            color: #083344;
            font-size: 23px;
        }

        .welcome p {
            margin: 0;
            color: #164e63;
            font-size: 13px;
            line-height: 1.8;
        }

        /* =====================================
           JUDUL BAGIAN
        ===================================== */

        .section-heading {
            margin: 28px 0 15px;
        }

        .section-heading h2 {
            margin: 0;
            font-size: 17px;
            color: #164e63;
        }

        .section-heading p {
            margin: 4px 0 0;
            color: var(--ocean-muted);
            font-size: 12px;
        }

        /* =====================================
           KARTU STATISTIK
        ===================================== */

        .cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .card {
            min-width: 0;
            padding: 19px;

            border: 1px solid var(--ocean-border);
            border-radius: 14px;

            background: white;

            box-shadow: 0 5px 16px rgba(14, 116, 144, 0.035);

            transition: 0.2s ease;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 22px rgba(14, 116, 144, 0.08);
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
        }

        .card-label {
            color: #64748b;
            font-size: 12px;
            font-weight: 600;
        }

        .card-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;

            width: 37px;
            height: 37px;

            border-radius: 11px;

            background: #e0f7ff;
            color: #087eab;
            font-size: 19px;
        }

        .card-value {
            color: var(--ocean-dark);
            font-size: 27px;
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .card-value.currency {
            font-size: 18px;
        }

        .card-description {
            margin-top: 8px;
            color: #8a9ba9;
            font-size: 11px;
        }

        .card-low .card-icon {
            background: #fff0d9;
            color: #b45309;
        }

        .card-in .card-icon {
            background: #dcfce7;
            color: #15803d;
        }

        .card-out .card-icon {
            background: #ffe4e6;
            color: #be123c;
        }

        /* =====================================
           PANEL DAN TABEL
        ===================================== */

        .panel {
            margin-bottom: 24px;
            overflow: hidden;

            border: 1px solid var(--ocean-border);
            border-radius: 14px;

            background: white;

            box-shadow: 0 5px 16px rgba(14, 116, 144, 0.035);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;

            padding: 20px 22px;

            border-bottom: 1px solid #e7f3f8;
        }

        .panel-header h2 {
            margin: 0;
            color: var(--ocean-dark);
            font-size: 16px;
        }

        .panel-header p {
            margin: 4px 0 0;
            color: #8a9ba9;
            font-size: 11px;
        }

        .panel-icon {
            display: flex;
            align-items: center;
            justify-content: center;

            width: 36px;
            height: 36px;

            border-radius: 10px;
            background: #e0f7ff;
            color: #087eab;
            font-size: 18px;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 12px;
        }

        th {
            padding: 13px 17px;

            background: #effaff;
            color: #35748b;

            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            white-space: nowrap;

            border-bottom: 1px solid #dff0f7;
        }

        td {
            padding: 15px 17px;

            color: #526b7a;
            border-bottom: 1px solid #edf4f7;
            vertical-align: middle;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        tbody tr:hover {
            background: #f7fcff;
        }

        .sku {
            color: #0e7490;
            font-weight: bold;
            white-space: nowrap;
        }

        .product-name {
            color: #164e63;
            font-weight: bold;
        }

        .price {
            color: #164e63;
            font-weight: 600;
            white-space: nowrap;
        }

        .stock-number {
            color: #164e63;
            font-weight: bold;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;

            font-size: 10px;
            font-weight: bold;
            white-space: nowrap;
        }

        .badge-low {
            background: #ffedd5;
            color: #9a3412;
        }

        .badge-ok {
            background: #dcfce7;
            color: #166534;
        }

        .badge-in {
            background: #dcfce7;
            color: #166534;
        }

        .badge-out {
            background: #ffe4e6;
            color: #9f1239;
        }

        .empty {
            padding: 28px 18px;
            text-align: center;
            color: #8a9ba9;
        }

        /* =====================================
           FOOTER
        ===================================== */

        .footer {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;

            padding: 12px 3px;

            color: #8a9ba9;
            font-size: 11px;
        }

        .footer strong {
            color: #39819b;
        }

        /* =====================================
           RESPONSIVE
        ===================================== */

        @media (max-width: 1200px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            main {
                padding: 24px;
            }
        }

        @media (max-width: 760px) {
            .sidebar {
                position: relative;
                width: 100%;
                padding: 18px;
            }

            .brand {
                margin-bottom: 22px;
            }

            main {
                margin-left: 0;
                padding: 18px;
            }

            .page-title {
                font-size: 24px;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .welcome {
                padding: 22px;
            }

            .welcome h2 {
                font-size: 20px;
            }

            .card {
                padding: 16px;
            }

            .card-value {
                font-size: 23px;
            }

            .card-value.currency {
                font-size: 16px;
            }
        }

        @media (max-width: 450px) {
            .cards {
                grid-template-columns: 1fr;
            }

            .footer {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<!-- =====================================
     SIDEBAR DAN LINK NAVIGASI
===================================== -->

<aside class="sidebar">

    <div class="brand">
        <div class="brand-icon">I</div>

        <div class="brand-name">
            INVENTORY
            <small>Management System</small>
        </div>
    </div>

    <p class="menu-label">MENU UTAMA</p>

    <a class="nav-item active" href="dashboard.php">
        <span class="nav-icon">⌂</span>
        <span>Dashboard</span>
    </a>

    <a class="nav-item" href="products/index.php">
        <span class="nav-icon">▦</span>
        <span>Produk</span>
    </a>

    <a class="nav-item" href="categories/index.php">
        <span class="nav-icon">▤</span>
        <span>Kategori</span>
    </a>

    <a class="nav-item" href="suppliers/index.php">
        <span class="nav-icon">♧</span>
        <span>Supplier</span>
    </a>

    <a class="nav-item" href="stock/index.php">
        <span class="nav-icon">↕</span>
        <span>Transaksi Stok</span>
    </a>

    <a class="nav-item" href="reports/index.php">
        <span class="nav-icon">▥</span>
        <span>Laporan</span>
    </a>

    <a class="nav-item" href="users/index.php">
        <span class="nav-icon">♙</span>
        <span>Pengguna</span>
    </a>

    <div class="sidebar-bottom">
        <strong>Inventory System</strong>

        <p>
            Kelola produk, kategori, pemasok, dan stok
            melalui satu sistem terintegrasi.
        </p>
    </div>

</aside>

<!-- =====================================
     KONTEN UTAMA DASHBOARD
===================================== -->

<main>

    <header class="topbar">

        <div>
            <h1 class="page-title">Dashboard</h1>

            <p class="page-subtitle">
                Ringkasan persediaan barang dan aktivitas toko.
            </p>
        </div>

        <div class="topbar-actions">

            <div class="date-box">
                ◷ &nbsp; <?= e(date('d F Y')) ?>
            </div>

            <a
                href="logout.php"
                class="logout-button"
            >
                Logout
            </a>

        </div>

    </header>

    <!-- BANNER -->

    <section class="welcome">

        <div class="welcome-content">

            <span class="welcome-label">
                INVENTORY OVERVIEW
            </span>

            <h2>
                Selamat datang, <?= e($user['full_name'] ?? $user['username']) ?>!
            </h2>

            <p>
                Pantau persediaan barang, kategori, pemasok,
                dan transaksi stok dari satu halaman.
                Informasi inventory ditampilkan secara ringkas
                untuk membantu pengelolaan barang.
            </p>

        </div>

    </section>

    <!-- STATISTIK -->

    <div class="section-heading">

        <h2>Ringkasan Inventory</h2>

        <p>Ikhtisar data dari database inventory.</p>

    </div>

    <section class="cards">

        <div class="card">

            <div class="card-top">
                <span class="card-label">Total Produk</span>
                <span class="card-icon">▦</span>
            </div>

            <div class="card-value">
                <?= e($summary['total_products']) ?>
            </div>

            <div class="card-description">
                Jumlah produk terdaftar
            </div>

        </div>

        <div class="card">

            <div class="card-top">
                <span class="card-label">Total Kategori</span>
                <span class="card-icon">▤</span>
            </div>

            <div class="card-value">
                <?= e($summary['total_categories']) ?>
            </div>

            <div class="card-description">
                Kategori produk
            </div>

        </div>

        <div class="card">

            <div class="card-top">
                <span class="card-label">Total Supplier</span>
                <span class="card-icon">♧</span>
            </div>

            <div class="card-value">
                <?= e($summary['total_suppliers']) ?>
            </div>

            <div class="card-description">
                Pemasok terdaftar
            </div>

        </div>

        <div class="card">

            <div class="card-top">
                <span class="card-label">Nilai Inventory</span>
                <span class="card-icon">Rp</span>
            </div>

            <div class="card-value currency">
                Rp <?= e(number_format(
                    (float) $summary['inventory_value'],
                    0,
                    ',',
                    '.'
                )) ?>
            </div>

            <div class="card-description">
                Harga dikalikan jumlah stok
            </div>

        </div>

        <div class="card card-low">

            <div class="card-top">
                <span class="card-label">Stok Rendah</span>
                <span class="card-icon">!</span>
            </div>

            <div class="card-value">
                <?= e($summary['low_stock']) ?>
            </div>

            <div class="card-description">
                Produk di bawah atau pada stok minimum
            </div>

        </div>

        <div class="card card-in">

            <div class="card-top">
                <span class="card-label">Barang Masuk</span>
                <span class="card-icon">↓</span>
            </div>

            <div class="card-value">
                <?= e($summary['total_in']) ?>
            </div>

            <div class="card-description">
                Total unit barang masuk
            </div>

        </div>

        <div class="card card-out">

            <div class="card-top">
                <span class="card-label">Barang Keluar</span>
                <span class="card-icon">↑</span>
            </div>

            <div class="card-value">
                <?= e($summary['total_out']) ?>
            </div>

            <div class="card-description">
                Total unit barang keluar
            </div>

        </div>

    </section>

    <!-- DAFTAR PRODUK -->

    <section class="panel">

        <div class="panel-header">

            <div>
                <h2>Daftar Produk</h2>

                <p>
                    Lima produk terakhir yang terdaftar.
                </p>
            </div>

            <div class="panel-icon">▦</div>

        </div>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Nama Produk</th>
                        <th>Kategori</th>
                        <th>Harga</th>
                        <th>Stok</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($products)): ?>

                    <tr>
                        <td colspan="6" class="empty">
                            Belum ada produk yang terdaftar.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($products as $product): ?>

                        <tr>

                            <td class="sku">
                                <?= e($product['sku']) ?>
                            </td>

                            <td class="product-name">
                                <?= e($product['name']) ?>
                            </td>

                            <td>
                                <?= e($product['category_name']) ?>
                            </td>

                            <td class="price">
                                Rp <?= e(number_format(
                                    (float) $product['price'],
                                    0,
                                    ',',
                                    '.'
                                )) ?>
                            </td>

                            <td class="stock-number">
                                <?= e($product['stock']) ?>
                            </td>

                            <td>

                                <?php if (
                                    (int) $product['stock']
                                    <= (int) $product['minimum_stock']
                                ): ?>

                                    <span class="badge badge-low">
                                        Stok Rendah
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-ok">
                                        Tersedia
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <!-- AKTIVITAS STOK -->

    <section class="panel">

        <div class="panel-header">

            <div>
                <h2>Aktivitas Stok Terbaru</h2>

                <p>
                    Riwayat perubahan stok yang tercatat.
                </p>
            </div>

            <div class="panel-icon">↕</div>

        </div>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Produk</th>
                        <th>Jenis Transaksi</th>
                        <th>Jumlah</th>
                        <th>Pengguna</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($movements)): ?>

                    <tr>
                        <td colspan="5" class="empty">
                            Belum ada transaksi stok.
                            Aktivitas akan ditampilkan setelah transaksi pertama.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($movements as $movement): ?>

                        <tr>

                            <td>
                                <?= e(date(
                                    'd-m-Y H:i',
                                    strtotime($movement['created_at'])
                                )) ?>
                            </td>

                            <td class="product-name">
                                <?= e($movement['product_name']) ?>
                            </td>

                            <td>

                                <?php if ($movement['type'] === 'IN'): ?>

                                    <span class="badge badge-in">
                                        Barang Masuk
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-out">
                                        Barang Keluar
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td class="stock-number">
                                <?= e($movement['quantity']) ?>
                            </td>

                            <td>
                                <?= e($movement['full_name']) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <!-- FOOTER -->

    <footer class="footer">

        <span>
            © <?= e(date('Y')) ?>
            <strong>Inventory Management System</strong>
        </span>

        <span>
            Relational Database + Advanced PHP
        </span>

    </footer>

</main>

</body>
</html>