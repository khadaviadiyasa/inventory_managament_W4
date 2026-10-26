
<?php

// =============================================
// 1. SESSION DAN HAK AKSES
// =============================================

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    !isset($_SESSION['user']['id']) ||
    !isset($_SESSION['user']['role']) ||
    !in_array($_SESSION['user']['role'], ['admin', 'staff'], true)
) {
    header('Location: ../login.php');
    exit;
}

$role = $_SESSION['user']['role'];

require_once __DIR__ . '/../../config/database.php';

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

// =============================================
// 2. VALIDASI FILTER TANGGAL
// =============================================

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

if (!is_string($dateFrom)) {
    $dateFrom = '';
}

if (!is_string($dateTo)) {
    $dateTo = '';
}

$error = '';

function reportValidDate(string $date): bool
{
    if ($date === '') {
        return true;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $parsed = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $date
    );

    return $parsed !== false
        && $parsed->format('Y-m-d') === $date;
}

if (!reportValidDate($dateFrom)) {
    $dateFrom = '';
    $error = 'Tanggal awal tidak valid.';
}

if (!reportValidDate($dateTo)) {
    $dateTo = '';
    $error = 'Tanggal akhir tidak valid.';
}

if (
    $dateFrom !== '' &&
    $dateTo !== '' &&
    $dateFrom > $dateTo
) {
    $dateFrom = '';
    $dateTo = '';

    $error = 'Tanggal awal tidak boleh melewati tanggal akhir.';
}

// =============================================
// 3. RINGKASAN INVENTORY SAAT INI
// =============================================

$summaryStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_products,
        COALESCE(SUM(stock), 0) AS total_stock,
        COALESCE(SUM(price * stock), 0) AS inventory_value,
        COALESCE(
            SUM(
                CASE
                    WHEN stock <= minimum_stock THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS low_stock
    FROM products
");

$summary = $summaryStmt->fetch();

// =============================================
// 4. MEMBUAT FILTER PERIODE TRANSAKSI
// =============================================

$summaryConditions = [];
$summaryParams = [];

if ($dateFrom !== '') {
    $summaryConditions[] = 'created_at >= :summary_from';

    $summaryParams['summary_from'] =
        $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $nextDay = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $dateTo
    )->modify('+1 day')->format('Y-m-d');

    $summaryConditions[] = 'created_at < :summary_to';

    $summaryParams['summary_to'] =
        $nextDay . ' 00:00:00';
}

$summaryWhere = empty($summaryConditions)
    ? ''
    : ' WHERE ' . implode(' AND ', $summaryConditions);

// =============================================
// 5. RINGKASAN BARANG MASUK DAN KELUAR
// =============================================

$movementSummaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_transactions,

        COALESCE(
            SUM(
                CASE
                    WHEN `type` = 'IN' THEN quantity
                    ELSE 0
                END
            ),
            0
        ) AS total_in,

        COALESCE(
            SUM(
                CASE
                    WHEN `type` = 'OUT' THEN quantity
                    ELSE 0
                END
            ),
            0
        ) AS total_out

    FROM stock_movements
    $summaryWhere
");

$movementSummaryStmt->execute($summaryParams);
$movementSummary = $movementSummaryStmt->fetch();

// =============================================
// 6. LAPORAN AGREGAT SETIAP PRODUK
// =============================================

$detailConditions = [];
$detailParams = [];

if ($dateFrom !== '') {
    $detailConditions[] = 'sm.created_at >= :detail_from';

    $detailParams['detail_from'] =
        $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $nextDay = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $dateTo
    )->modify('+1 day')->format('Y-m-d');

    $detailConditions[] = 'sm.created_at < :detail_to';

    $detailParams['detail_to'] =
        $nextDay . ' 00:00:00';
}

$detailDateFilter = '';

if (!empty($detailConditions)) {
    $detailDateFilter = ' AND '
        . implode(' AND ', $detailConditions);
}

$reportStmt = $pdo->prepare("
    SELECT
        p.id,
        p.sku,
        p.name,
        c.name AS category_name,
        p.price,
        p.stock,
        p.minimum_stock,

        COALESCE(
            SUM(
                CASE
                    WHEN sm.`type` = 'IN'
                    THEN sm.quantity
                    ELSE 0
                END
            ),
            0
        ) AS total_in,

        COALESCE(
            SUM(
                CASE
                    WHEN sm.`type` = 'OUT'
                    THEN sm.quantity
                    ELSE 0
                END
            ),
            0
        ) AS total_out,

        COUNT(sm.id) AS total_transactions

    FROM products p

    INNER JOIN categories c
        ON c.id = p.category_id

    LEFT JOIN stock_movements sm
        ON sm.product_id = p.id
        $detailDateFilter

    GROUP BY
        p.id,
        p.sku,
        p.name,
        c.name,
        p.price,
        p.stock,
        p.minimum_stock

    ORDER BY p.name ASC
");

$reportStmt->execute($detailParams);
$reports = $reportStmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Laporan Inventory | Inventory Management</title>

    <style>
        :root {
            --dark: #164e63;
            --primary: #0284c7;
            --pale: #f0faff;
            --border: #cceaf5;
            --muted: #64748b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: var(--pale);
            color: var(--dark);
        }

        a {
            text-decoration: none;
        }

        /* SIDEBAR */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 230px;
            padding: 25px 18px;
            overflow-y: auto;

            background: linear-gradient(
                180deg,
                #e0f7ff,
                #c9edf7
            );

            border-right: 1px solid var(--border);
        }

        .brand {
            margin-bottom: 36px;
            color: #075985;
            font-size: 19px;
            font-weight: bold;
        }

        .brand small {
            display: block;
            margin-top: 5px;
            color: #0e7490;
            font-size: 11px;
            font-weight: normal;
        }

        .menu-label {
            margin-left: 10px;
            color: #52859a;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .nav-item {
            display: block;
            padding: 12px;
            margin-bottom: 7px;
            border-radius: 9px;
            color: #28667f;
            font-size: 13px;
        }

        .nav-item:hover {
            background: #c4eafa;
        }

        .nav-item.active {
            background: #7dd3fc;
            color: #083344;
            font-weight: bold;
        }

        /* MAIN */

        main {
            margin-left: 230px;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        h1 {
            margin: 0 0 7px;
            font-size: 27px;
        }

        .subtitle {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .button {
            display: inline-block;
            padding: 10px 14px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
        }

        .primary {
            background: #0284c7;
            color: white;
        }

        .secondary {
            background: #e5f3f9;
            color: var(--dark);
        }

        /* MESSAGE */

        .message {
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 9px;
            font-size: 13px;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
        }

        /* SUMMARY CARDS */

        .cards {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 24px;
        }

        .card {
            padding: 21px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 13px;
            box-shadow: 0 5px 16px rgba(14, 116, 144, 0.04);
        }

        .card-label {
            margin-bottom: 13px;
            color: var(--muted);
            font-size: 12px;
        }

        .card-value {
            color: var(--dark);
            font-size: 25px;
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .card-description {
            margin-top: 8px;
            color: #8297a5;
            font-size: 11px;
        }

        /* PANEL */

        .panel {
            padding: 23px;
            margin-bottom: 24px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 5px 16px rgba(14, 116, 144, 0.04);
        }

        .panel h2 {
            margin: 0 0 8px;
            font-size: 17px;
        }

        .panel-description {
            margin: 0 0 21px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.7;
        }

        /* FILTER FORM */

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #355d70;
            font-size: 12px;
            font-weight: bold;
        }

        input[type="date"] {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbdde6;
            border-radius: 8px;
            background: #fbfeff;
            color: var(--dark);
            font: inherit;
            font-size: 13px;
        }

        .form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 18px;
        }

        /* TABLE */

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
            padding: 13px 11px;
            background: #effaff;
            color: #35748b;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        td {
            padding: 13px 11px;
            border-bottom: 1px solid #edf4f7;
            color: #526b7a;
            vertical-align: middle;
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
            color: var(--dark);
            font-weight: bold;
        }

        .number {
            white-space: nowrap;
            font-weight: 600;
        }

        .badge {
            display: inline-block;
            padding: 5px 8px;
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

        .empty {
            padding: 25px;
            text-align: center;
            color: var(--muted);
        }

        .table-footer {
            margin-top: 17px;
            color: var(--muted);
            font-size: 12px;
        }

        .footer {
            padding: 10px 0;
            color: #8297a5;
            font-size: 11px;
        }

        @media (max-width: 1000px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .sidebar {
                position: static;
                width: 100%;
                padding: 18px;
            }

            .brand {
                margin-bottom: 20px;
            }

            main {
                margin-left: 0;
                padding: 18px;
            }

            h1 {
                font-size: 23px;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 18px;
            }
        }

        @media (max-width: 450px) {
            .cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<aside class="sidebar">

    <div class="brand">
        INVENTORY
        <small>Management System</small>
    </div>

    <p class="menu-label">MENU UTAMA</p>

    <a class="nav-item" href="../dashboard.php">
        ▦ &nbsp; Dashboard
    </a>

    <a class="nav-item" href="../products/index.php">
        ▣ &nbsp; Produk
    </a>

    <a class="nav-item" href="../categories/index.php">
        ▤ &nbsp; Kategori
    </a>

    <?php if ($role === 'admin'): ?>
        <a class="nav-item" href="../suppliers/index.php">
            ♧ &nbsp; Supplier
        </a>
    <?php endif; ?>

    <a class="nav-item" href="../stock/index.php">
        ↕ &nbsp; Transaksi Stok
    </a>

    <a class="nav-item active" href="index.php">
        ▥ &nbsp; Laporan
    </a>

    <?php if ($role === 'admin'): ?>
        <a class="nav-item" href="../users/index.php">
            ♙ &nbsp; Pengguna
        </a>
    <?php endif; ?>

</aside>

<main>

    <header class="topbar">

        <div>
            <h1>Laporan Inventory</h1>

            <p class="subtitle">
                Ringkasan persediaan dan pergerakan stok produk.
            </p>
        </div>

        <a class="button secondary" href="../dashboard.php">
            ← Dashboard
        </a>

    </header>

    <?php if ($error !== ''): ?>
        <div class="message error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- FILTER PERIODE -->

    <section class="panel">

        <h2>Filter Periode Transaksi</h2>

        <p class="panel-description">
            Pilih tanggal awal dan akhir untuk menghitung barang masuk
            dan keluar pada periode tersebut. Kosongkan tanggal untuk
            melihat seluruh histori.
        </p>

        <form method="GET" action="index.php">

            <div class="filter-grid">

                <div>
                    <label for="date_from">Tanggal Awal</label>

                    <input
                        type="date"
                        id="date_from"
                        name="date_from"
                        value="<?= e($dateFrom) ?>"
                    >
                </div>

                <div>
                    <label for="date_to">Tanggal Akhir</label>

                    <input
                        type="date"
                        id="date_to"
                        name="date_to"
                        value="<?= e($dateTo) ?>"
                    >
                </div>

            </div>

            <div class="form-actions">

                <button type="submit" class="button primary">
                    Tampilkan Laporan
                </button>

                <a href="index.php" class="button secondary">
                    Reset Periode
                </a>

            </div>

        </form>

    </section>

    <!-- RINGKASAN INVENTORY -->

    <div class="cards">

        <div class="card">

            <div class="card-label">
                Total Produk
            </div>

            <div class="card-value">
                <?= e($summary['total_products']) ?>
            </div>

            <div class="card-description">
                Produk yang terdaftar
            </div>

        </div>

        <div class="card">

            <div class="card-label">
                Total Unit Stok
            </div>

            <div class="card-value">
                <?= e($summary['total_stock']) ?>
            </div>

            <div class="card-description">
                Jumlah stok saat ini
            </div>

        </div>

        <div class="card">

            <div class="card-label">
                Nilai Inventory
            </div>

            <div class="card-value" style="font-size:19px;">

                Rp <?= e(number_format(
                    (float) $summary['inventory_value'],
                    0,
                    ',',
                    '.'
                )) ?>

            </div>

            <div class="card-description">
                Total harga dikalikan stok
            </div>

        </div>

        <div class="card">

            <div class="card-label">
                Produk Stok Rendah
            </div>

            <div class="card-value">
                <?= e($summary['low_stock']) ?>
            </div>

            <div class="card-description">
                Stok di bawah atau sama dengan minimum
            </div>

        </div>

        <div class="card">

            <div class="card-label">
                Transaksi pada Periode
            </div>

            <div class="card-value">
                <?= e($movementSummary['total_transactions']) ?>
            </div>

            <div class="card-description">
                Jumlah transaksi yang tercatat
            </div>

        </div>

        <div class="card">

            <div class="card-label">
                Unit Barang Masuk
            </div>

            <div class="card-value">
                <?= e($movementSummary['total_in']) ?>
            </div>

            <div class="card-description">
                Total unit masuk pada periode terpilih
            </div>

        </div>

        <div class="card">

            <div class="card-label">
                Unit Barang Keluar
            </div>

            <div class="card-value">
                <?= e($movementSummary['total_out']) ?>
            </div>

            <div class="card-description">
                Total unit keluar pada periode terpilih
            </div>

        </div>

    </div>

    <!-- TABEL AGREGAT PRODUK -->

    <section class="panel">

        <h2>Laporan per Produk</h2>

        <p class="panel-description">
            Total barang masuk dan keluar dihitung dari histori transaksi
            sesuai periode yang dipilih. Kolom stok menunjukkan jumlah stok
            yang tersedia saat ini.
        </p>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>
                        <th>SKU</th>
                        <th>Nama Produk</th>
                        <th>Kategori</th>
                        <th>Stok Saat Ini</th>
                        <th>Nilai Stok</th>
                        <th>Total Masuk</th>
                        <th>Total Keluar</th>
                        <th>Transaksi</th>
                        <th>Status</th>
                    </tr>

                </thead>

                <tbody>

                <?php if (empty($reports)): ?>

                    <tr>
                        <td colspan="9" class="empty">
                            Belum ada data produk.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($reports as $report): ?>

                        <tr>

                            <td class="sku">
                                <?= e($report['sku']) ?>
                            </td>

                            <td class="product-name">
                                <?= e($report['name']) ?>
                            </td>

                            <td>
                                <?= e($report['category_name']) ?>
                            </td>

                            <td class="number">
                                <?= e($report['stock']) ?>
                            </td>

                            <td class="number">
                                Rp <?= e(number_format(
                                    (float) $report['price']
                                    * (int) $report['stock'],
                                    0,
                                    ',',
                                    '.'
                                )) ?>
                            </td>

                            <td class="number">
                                <?= e($report['total_in']) ?>
                            </td>

                            <td class="number">
                                <?= e($report['total_out']) ?>
                            </td>

                            <td>
                                <?= e($report['total_transactions']) ?>
                            </td>

                            <td>

                                <?php if (
                                    (int) $report['stock']
                                    <= (int) $report['minimum_stock']
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

        <div class="table-footer">
            Total produk pada laporan: <?= e(count($reports)) ?>
        </div>

    </section>

    <footer class="footer">
        Inventory Management System &copy; <?= e(date('Y')) ?>
    </footer>

</main>

</body>
</html>
