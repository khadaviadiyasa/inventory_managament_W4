<?php


if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Pastikan pengguna sudah login dan role-nya valid.
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

/*
|--------------------------------------------------------------------------
| Mengambil ringkasan inventory
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM products) AS total_products,
        (SELECT COUNT(*) FROM categories) AS total_categories,
        (SELECT COUNT(*) FROM suppliers) AS total_suppliers,
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

/*
|--------------------------------------------------------------------------
| Mengambil daftar produk
|--------------------------------------------------------------------------
*/

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
    INNER JOIN categories c ON c.id = p.category_id
    ORDER BY p.id DESC
    LIMIT 5
");

$products = $productStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Mengambil aktivitas stok terbaru
|--------------------------------------------------------------------------
*/

$movementStmt = $pdo->query("
    SELECT
        sm.id,
        sm.`type`,
        sm.quantity,
        sm.created_at,
        p.name AS product_name,
        u.full_name
    FROM stock_movements sm
    INNER JOIN products p ON p.id = sm.product_id
    INNER JOIN users u ON u.id = sm.user_id
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
           1. PENGATURAN DASAR
        ===================================== */

        :root {
            --ocean-dark: #164e63;
            --ocean-text: #075985;
            --ocean-primary: #38bdf8;
            --ocean-light: #dff4fc;
            --ocean-pale: #f0faff;
            --ocean-border: #cceaf5;
            --ocean-muted: #64748b;
            --white: #ffffff;
            --success-bg: #dcfce7;
            --success-text: #166534;
            --warning-bg: #ffedd5;
            --warning-text: #9a3412;
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
            text-decoration: none;
            color: inherit;
        }

        /* =====================================
           2. SIDEBAR
        ===================================== */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 245px;
            padding: 28px 20px;
            background: linear-gradient(
                180deg,
                #e0f7ff 0%,
                #d9f2fa 55%,
                #c9edf7 100%
            );
            border-right: 1px solid var(--ocean-border);
            overflow-y: auto;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 42px;
        }

        .brand-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            border-radius: 14px;
            background: linear-gradient(
                135deg,
                #38bdf8,
                #5eead4
            );
            color: #083344;
            font-size: 23px;
            font-weight: bold;
            box-shadow: 0 5px 12px rgba(56, 189, 248, 0.18);
        }

        .brand-name {
            font-size: 17px;
            font-weight: 800;
            color: #075985;
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
            letter-spacing: 1.2px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            margin-bottom: 8px;
            border-radius: 11px;
            color: #28667f;
            font-size: 14px;
            transition:
                background 0.2s ease,
                color 0.2s ease,
                transform 0.2s ease;
        }

        .nav-item:hover {
            background: rgba(125, 211, 252, 0.35);
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
            box-shadow: 0 5px 12px rgba(14, 116, 144, 0.10);
        }

        .nav-icon {
            width: 23px;
            text-align: center;
            font-size: 17px;
        }

        .sidebar-bottom {
            margin-top: 35px;
            padding: 15px;
            border: 1px solid #bce5f3;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.55);
        }

        .sidebar-bottom strong {
            display: block;
            margin-bottom: 5px;
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
           3. KONTEN UTAMA
        ===================================== */

        main {
            margin-left: 245px;
            padding: 32px;
            min-height: 100vh;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 26px;
        }

        .page-title {
            margin: 0 0 5px;
            color: #164e63;
            font-size: 28px;
            font-weight: 800;
        }

        .page-subtitle {
            margin: 0;
            color: var(--ocean-muted);
            font-size: 13px;
        }

        .date-box {
            padding: 10px 15px;
            border: 1px solid var(--ocean-border);
            border-radius: 12px;
            background: white;
            color: #0e7490;
            font-size: 12px;
            white-space: nowrap;
        }

        /* =====================================
           4. BANNER SAMBUTAN
        ===================================== */

        .welcome {
            position: relative;
            overflow: hidden;
            margin-bottom: 25px;
            padding: 28px;
            border: 1px solid #a5e6ee;
            border-radius: 18px;
            background: linear-gradient(
                120deg,
                #7dd3fc 0%,
                #a5f3fc 55%,
                #99f6e4 100%
            );
            box-shadow: 0 8px 22px rgba(14, 116, 144, 0.07);
        }

        .welcome::after {
            content: "";
            position: absolute;
            width: 200px;
            height: 200px;
            right: -55px;
            top: -90px;
            border: 28px solid rgba(255, 255, 255, 0.25);
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
            letter-spacing: 0.7px;
        }

        .welcome h2 {
            margin: 0 0 10px;
            color: #083344;
            font-size: 23px;
        }

        .welcome p {
            margin: 0;
            max-width: 550px;
            color: #164e63;
            font-size: 13px;
            line-height: 1.8;
        }

        /* =====================================
           5. KARTU STATISTIK
        ===================================== */

        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin: 30px 0 16px;
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

        .cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 25px;
        }

        .card {
            position: relative;
            overflow: hidden;
            min-width: 0;
            padding: 21px;
            background: white;
            border: 1px solid var(--ocean-border);
            border-radius: 15px;
            box-shadow: 0 5px 16px rgba(14, 116, 144, 0.035);
            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
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
            margin-bottom: 17px;
        }

        .card-label {
            color: #64748b;
            font-size: 12px;
            font-weight: 600;
        }

        .card-icon {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-shrink: 0;
            width: 39px;
            height: 39px;
            border-radius: 12px;
            background: #e0f7ff;
            color: #087eab;
            font-size: 20px;
        }

        .card-value {
            overflow-wrap: anywhere;
            color: #164e63;
            font-size: 27px;
            font-weight: 800;
            letter-spacing: -0.7px;
        }

        .card-value.currency {
            font-size: 19px;
        }

        .card-description {
            margin-top: 8px;
            color: #8a9ba9;
            font-size: 11px;
        }

        .card.low-card .card-icon {
            background: #fff0d9;
            color: #b45309;
        }

        .card.in-card .card-icon {
            background: #dcfce7;
            color: #15803d;
        }

        .card.out-card .card-icon {
            background: #ffe4e6;
            color: #be123c;
        }

        /* =====================================
           6. PANEL DAN TABEL
        ===================================== */

        .panel {
            margin-bottom: 24px;
            overflow: hidden;
            background: white;
            border: 1px solid var(--ocean-border);
            border-radius: 15px;
            box-shadow: 0 5px 16px rgba(14, 116, 144, 0.035);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 21px 23px;
            border-bottom: 1px solid #e7f3f8;
        }

        .panel-header h2 {
            margin: 0;
            color: #164e63;
            font-size: 16px;
        }

        .panel-header p {
            margin: 5px 0 0;
            color: #8a9ba9;
            font-size: 11px;
        }

        .panel-icon {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 36px;
            height: 36px;
            border-radius: 11px;
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
            white-space: nowrap;
        }

        thead th {
            padding: 14px 20px;
            background: #effaff;
            color: #35748b;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            border-bottom: 1px solid #dff0f7;
        }

        tbody td {
            padding: 16px 20px;
            color: #526b7a;
            border-bottom: 1px solid #edf4f7;
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
        }

        .product-name {
            color: #164e63;
            font-weight: bold;
        }

        .price {
            color: #164e63;
            font-weight: 600;
        }

        .stock-number {
            color: #164e63;
            font-weight: bold;
        }

        .badge {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: bold;
        }

        .badge-low {
            color: var(--warning-text);
            background: var(--warning-bg);
        }

        .badge-ok {
            color: var(--success-text);
            background: var(--success-bg);
        }

        .badge-in {
            color: #166534;
            background: #dcfce7;
        }

        .badge-out {
            color: #9f1239;
            background: #ffe4e6;
        }

        .empty {
            padding: 32px 20px;
            color: #8a9ba9;
            text-align: center;
            white-space: normal;
        }

        .empty-icon {
            display: block;
            margin-bottom: 9px;
            font-size: 25px;
        }

        /* =====================================
           7. BAGIAN BAWAH
        ===================================== */

        .footer {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 3px;
            color: #8a9ba9;
            font-size: 11px;
        }

        .footer strong {
            color: #39819b;
        }

        /* =====================================
           8. RESPONSIVE
        ===================================== */

        @media (max-width: 1200px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            main {
                padding: 25px;
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

            .menu-label {
                margin-top: 12px;
            }

            .sidebar-bottom {
                margin-top: 20px;
            }

            main {
                margin-left: 0;
                padding: 18px;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .page-title {
                font-size: 24px;
            }

            .welcome {
                padding: 22px;
            }

            .welcome h2 {
                font-size: 20px;
            }

            .cards {
                gap: 12px;
            }

            .card {
                padding: 16px;
            }

            .card-value {
                font-size: 23px;
            }

            .card-value.currency {
                font-size: 17px;
            }

            .panel-header {
                padding: 17px;
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
     SIDEBAR
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

    <div class="nav-item active">
        <span class="nav-icon">⌂</span>
        <span>Dashboard</span>
    </div>

    <div class="nav-item">
        <span class="nav-icon">▦</span>
        <span>Produk</span>
    </div>

    <div class="nav-item">
        <span class="nav-icon">▤</span>
        <span>Kategori</span>
    </div>

    <div class="nav-item">
        <span class="nav-icon">♧</span>
        <span>Supplier</span>
    </div>

    <div class="nav-item">
        <span class="nav-icon">↕</span>
        <span>Transaksi Stok</span>
    </div>

    <div class="nav-item">
        <span class="nav-icon">▥</span>
        <span>Laporan</span>
    </div>

    <div class="nav-item">
        <span class="nav-icon">♙</span>
        <span>Pengguna</span>
    </div>

    <div class="sidebar-bottom">
        <strong>Inventory System</strong>
        <p>
            Kelola produk, stok, dan pemasok
            dalam satu sistem terintegrasi.
        </p>
    </div>

</aside>

<!-- =====================================
     KONTEN DASHBOARD
===================================== -->

<main>

    <header class="topbar">
        <div>
            <h1 class="page-title">Dashboard</h1>

            <p class="page-subtitle">
                Ringkasan persediaan barang dan aktivitas toko.
            </p>
        </div>

<div style="display: flex; align-items: center; gap: 10px;">
    <div class="date-box">
        ◷ &nbsp; <?= e(date('d F Y')) ?>
    </div>

    <a
        href="logout.php"
        style="
            display: inline-block;
            padding: 10px 15px;
            border-radius: 9px;
            background: #dff4fc;
            color: #075985;
            border: 1px solid #cceaf5;
            text-decoration: none;
            font-size: 12px;
            font-weight: bold;
        "
    >
        Logout
    </a>
</div>
    </header>

    <!-- Banner sambutan -->

    <section class="welcome">
        <div class="welcome-content">

            <span class="welcome-label">
                INVENTORY OVERVIEW
            </span>

            <h2>Selamat Datang di Inventory System!</h2>

            <p>
                Pantau persediaan barang, informasi pemasok,
                dan aktivitas transaksi stok melalui dashboard
                ini. Semua informasi utama tersedia dalam satu
                halaman agar pengelolaan inventory lebih mudah.
            </p>

        </div>
    </section>

    <!-- Statistik -->

    <div class="section-heading">
        <div>
            <h2>Ringkasan Inventory</h2>
            <p>Ikhtisar data yang tersimpan di database.</p>
        </div>
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
                Total harga dikalikan jumlah stok
            </div>
        </div>

        <div class="card low-card">
            <div class="card-top">
                <span class="card-label">Stok Rendah</span>
                <span class="card-icon">!</span>
            </div>

            <div class="card-value">
                <?= e($summary['low_stock']) ?>
            </div>

            <div class="card-description">
                Produk pada atau di bawah stok minimum
            </div>
        </div>

        <div class="card in-card">
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

        <div class="card out-card">
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

    <!-- Daftar produk -->

    <section class="panel">

        <div class="panel-header">
            <div>
                <h2>Daftar Produk</h2>
                <p>Lima produk terakhir yang terdaftar.</p>
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
                            <span class="empty-icon">▦</span>
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

    <!-- Aktivitas stok -->

    <section class="panel">

        <div class="panel-header">
            <div>
                <h2>Aktivitas Stok Terbaru</h2>
                <p>Riwayat perubahan stok yang tercatat.</p>
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
                            <span class="empty-icon">↕</span>
                            Belum ada transaksi stok.
                            Riwayat akan muncul setelah transaksi pertama.
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

    <!-- Footer -->

    <footer class="footer">
        <span>
            © <?= e(date('Y')) ?>
            <strong>Inventory Management System</strong>
        </span>

        <span>Relational Database + Advanced PHP</span>
    </footer>

</main>

</body>
</html>