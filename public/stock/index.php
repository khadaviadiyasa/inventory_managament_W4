
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

$userId = (int) $_SESSION['user']['id'];
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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =============================================
// 2. PROSES TRANSAKSI BARANG MASUK/KELUAR
// =============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => 'Permintaan tidak valid. Silakan coba lagi.'
        ];

        header('Location: index.php');
        exit;
    }

    $type = $_POST['type'] ?? '';
    $productInput = $_POST['product_id'] ?? '';
    $quantityInput = $_POST['quantity'] ?? '';
    $notesInput = $_POST['notes'] ?? '';

    try {
        if (!in_array($type, ['IN', 'OUT'], true)) {
            throw new RuntimeException(
                'Jenis transaksi tidak valid.'
            );
        }

        $productId = filter_var(
            $productInput,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $quantity = filter_var(
            $quantityInput,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($productId === false || $productId === null) {
            throw new RuntimeException(
                'Silakan pilih produk yang valid.'
            );
        }

        if ($quantity === false || $quantity === null) {
            throw new RuntimeException(
                'Jumlah barang harus bilangan bulat lebih dari nol.'
            );
        }

        if ($quantity > 2147483647) {
            throw new RuntimeException(
                'Jumlah barang terlalu besar.'
            );
        }

        if (!is_string($notesInput)) {
            throw new RuntimeException(
                'Format catatan transaksi tidak valid.'
            );
        }

        $notes = trim($notesInput);

        if (strlen($notes) > 5000) {
            throw new RuntimeException(
                'Catatan maksimal 5000 karakter.'
            );
        }

        // Mulai transaksi database.
        $pdo->beginTransaction();

        // Mengunci baris agar pemeriksaan stok aman
        // ketika beberapa transaksi terjadi bersamaan.
        $stmt = $pdo->prepare("
            SELECT id, sku, name, stock
            FROM products
            WHERE id = :id
            FOR UPDATE
        ");

        $stmt->execute([
            'id' => $productId
        ]);

        $product = $stmt->fetch();

        if (!$product) {
            throw new RuntimeException(
                'Produk tidak ditemukan.'
            );
        }

        $currentStock = (int) $product['stock'];

        if ($type === 'OUT' && $quantity > $currentStock) {
            throw new RuntimeException(
                'Stok tidak mencukupi. Stok tersedia: '
                . $currentStock . ' unit.'
            );
        }

        if ($type === 'IN') {
            // Menambah stok.
            $update = $pdo->prepare("
                UPDATE products
                SET stock = stock + :quantity
                WHERE id = :id
            ");

            $update->execute([
                'quantity' => $quantity,
                'id' => $productId
            ]);
        } else {
            // Mengurangi stok dengan pengaman tambahan.
            $update = $pdo->prepare("
                UPDATE products
                SET stock = stock - :quantity
                WHERE id = :id
                  AND stock >= :required_quantity
            ");

            $update->execute([
                'quantity' => $quantity,
                'id' => $productId,
                'required_quantity' => $quantity
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'Transaksi dibatalkan karena stok tidak mencukupi.'
                );
            }
        }

        // Simpan histori transaksi.
        $movement = $pdo->prepare("
            INSERT INTO stock_movements (
                product_id,
                user_id,
                `type`,
                quantity,
                notes
            ) VALUES (
                :product_id,
                :user_id,
                :type,
                :quantity,
                :notes
            )
        ");

        $movement->execute([
            'product_id' => $productId,
            'user_id' => $userId,
            'type' => $type,
            'quantity' => $quantity,
            'notes' => $notes === '' ? null : $notes
        ]);

        // Semua perubahan berhasil, simpan permanen.
        $pdo->commit();

        $transactionLabel = $type === 'IN'
            ? 'Barang masuk'
            : 'Barang keluar';

        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => $transactionLabel
                . ' berhasil dicatat untuk '
                . $product['name']
                . '. Jumlah: '
                . $quantity
                . ' unit.'
        ];

    } catch (Throwable $ex) {
        // Jika proses gagal, batalkan semua perubahannya.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($ex instanceof RuntimeException) {
            $errorMessage = $ex->getMessage();
        } else {
            error_log($ex->getMessage());

            $errorMessage =
                'Transaksi gagal. Periksa konfigurasi database '
                . 'dan struktur tabel.';
        }

        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => $errorMessage
        ];
    }

    // Mencegah formulir dikirim ulang ketika halaman di-refresh.
    header('Location: index.php');
    exit;
}

// =============================================
// 3. PESAN HASIL TRANSAKSI
// =============================================

$message = '';
$messageType = 'success';

if (isset($_SESSION['flash'])) {
    $message = $_SESSION['flash']['message'];
    $messageType = $_SESSION['flash']['type'];

    unset($_SESSION['flash']);
}

// =============================================
// 4. MENGAMBIL DATA PRODUK
// =============================================

$productStmt = $pdo->query("
    SELECT
        id,
        sku,
        name,
        stock,
        minimum_stock
    FROM products
    ORDER BY name ASC
");

$products = $productStmt->fetchAll();

// =============================================
// 5. RINGKASAN STOK
// =============================================

$summaryStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_products,
        COALESCE(SUM(stock), 0) AS total_stock,
        SUM(
            CASE
                WHEN stock <= minimum_stock THEN 1
                ELSE 0
            END
        ) AS low_stock
    FROM products
");

$summary = $summaryStmt->fetch();

// =============================================
// 6. HISTORI TRANSAKSI TERBARU
// =============================================

$historyStmt = $pdo->query("
    SELECT
        sm.id,
        sm.`type`,
        sm.quantity,
        sm.notes,
        sm.created_at,
        p.sku,
        p.name AS product_name,
        u.full_name
    FROM stock_movements sm
    INNER JOIN products p
        ON p.id = sm.product_id
    INNER JOIN users u
        ON u.id = sm.user_id
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT 20
");

$history = $historyStmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Transaksi Stok | Inventory Management</title>

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

        /* Sidebar */

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
            text-decoration: none;
        }

        .nav-item:hover {
            background: #c4eafa;
        }

        .nav-item.active {
            background: #7dd3fc;
            color: #083344;
            font-weight: bold;
        }

        /* Main */

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
        }

        .button {
            display: inline-block;
            padding: 10px 14px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            text-decoration: none;
        }

        .primary {
            background: #0284c7;
            color: white;
        }

        .primary:hover {
            background: #0369a1;
        }

        .secondary {
            background: #e5f3f9;
            color: var(--dark);
        }

        /* Pesan */

        .message {
            padding: 13px 15px;
            margin-bottom: 20px;
            border-radius: 9px;
            font-size: 13px;
            line-height: 1.6;
        }

        .message.success {
            background: #dcfce7;
            color: #166534;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Cards */

        .cards {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 24px;
        }

        .card {
            padding: 20px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: white;
            box-shadow: 0 5px 16px rgba(14, 116, 144, 0.04);
        }

        .card-label {
            margin-bottom: 12px;
            color: var(--muted);
            font-size: 12px;
        }

        .card-value {
            color: #164e63;
            font-size: 27px;
            font-weight: bold;
        }

        /* Panel */

        .panel {
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: white;
            box-shadow: 0 5px 16px rgba(14, 116, 144, 0.04);
        }

        .panel h2 {
            margin: 0 0 8px;
            font-size: 17px;
        }

        .panel-description {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.7;
        }

        /* Form */

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #355d70;
            font-size: 12px;
            font-weight: bold;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbdde6;
            border-radius: 8px;
            outline: none;
            background: #fbfeff;
            color: var(--dark);
            font: inherit;
            font-size: 13px;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px #e0f7ff;
        }

        textarea {
            min-height: 90px;
            resize: vertical;
        }

        .hint {
            margin: 7px 0 0;
            color: #8297a5;
            font-size: 11px;
            line-height: 1.6;
        }

        .radio-group {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .radio-option {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 15px;
            margin: 0;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #f8fdff;
            cursor: pointer;
        }

        .radio-option input {
            width: auto;
            accent-color: #0284c7;
        }

        .radio-option strong {
            display: block;
            font-size: 13px;
        }

        .radio-option small {
            display: block;
            margin-top: 4px;
            color: var(--muted);
            font-size: 11px;
            font-weight: normal;
        }

        .form-actions {
            margin-top: 21px;
        }

        /* Table */

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 12px;
        }

        th {
            padding: 14px 12px;
            background: #effaff;
            color: #35748b;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        td {
            padding: 14px 12px;
            border-bottom: 1px solid #edf4f7;
            color: #526b7a;
            vertical-align: top;
        }

        tbody tr:hover {
            background: #f7fcff;
        }

        .product-name {
            color: var(--dark);
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

        .badge-in {
            background: #dcfce7;
            color: #166534;
        }

        .badge-out {
            background: #ffe4e6;
            color: #9f1239;
        }

        .empty {
            padding: 28px;
            text-align: center;
            color: var(--muted);
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

            .form-grid,
            .radio-group {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
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

    <a class="nav-item" href="../categories/index.php">
        ▤ &nbsp; Kategori
    </a>

    <a class="nav-item" href="../products/index.php">
        ▣ &nbsp; Produk
    </a>

    <?php if ($role === 'admin'): ?>
        <a class="nav-item" href="../suppliers/index.php">
            ♧ &nbsp; Supplier
        </a>
    <?php endif; ?>

    <a class="nav-item active" href="index.php">
        ↕ &nbsp; Transaksi Stok
    </a>

    <a class="nav-item" href="../reports/index.php">
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
            <h1>Transaksi Stok</h1>

            <p class="subtitle">
                Catat barang masuk dan keluar serta pantau histori transaksi.
            </p>
        </div>

        <a class="button secondary" href="../dashboard.php">
            ← Dashboard
        </a>
    </header>

    <?php if ($message !== ''): ?>
        <div class="message <?= e($messageType) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <!-- Ringkasan -->

    <section class="cards">

        <div class="card">
            <div class="card-label">Total Produk</div>

            <div class="card-value">
                <?= e($summary['total_products'] ?? 0) ?>
            </div>
        </div>

        <div class="card">
            <div class="card-label">Total Stok Saat Ini</div>

            <div class="card-value">
                <?= e($summary['total_stock'] ?? 0) ?>
            </div>
        </div>

        <div class="card">
            <div class="card-label">Produk Stok Rendah</div>

            <div class="card-value">
                <?= e($summary['low_stock'] ?? 0) ?>
            </div>
        </div>

    </section>

    <!-- Form transaksi -->

    <section class="panel">

        <h2>Catat Transaksi Baru</h2>

        <p class="panel-description">
            Pilih jenis transaksi, tentukan produk, dan masukkan jumlah
            barang. Perubahan stok dan histori akan disimpan bersama
            dalam satu transaksi database.
        </p>

        <?php if (empty($products)): ?>

            <p class="empty">
                Belum ada produk. Tambahkan produk terlebih dahulu.
            </p>

            <a class="button primary" href="../products/index.php">
                Buka Produk
            </a>

        <?php else: ?>

            <form method="POST" action="index.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['csrf_token']) ?>"
                >

                <div class="form-grid">

                    <div class="field full">

                        <label>Jenis Transaksi</label>

                        <div class="radio-group">

                            <label class="radio-option">
                                <input
                                    type="radio"
                                    name="type"
                                    value="IN"
                                    checked
                                    required
                                >

                                <span>
                                    <strong>↓ Barang Masuk</strong>
                                    <small>Menambah stok produk.</small>
                                </span>
                            </label>

                            <label class="radio-option">
                                <input
                                    type="radio"
                                    name="type"
                                    value="OUT"
                                    required
                                >

                                <span>
                                    <strong>↑ Barang Keluar</strong>
                                    <small>Mengurangi stok produk.</small>
                                </span>
                            </label>

                        </div>

                    </div>

                    <div class="field">

                        <label for="product_id">Pilih Produk</label>

                        <select
                            id="product_id"
                            name="product_id"
                            required
                        >

                            <option value="">
                                -- Pilih Produk --
                            </option>

                            <?php foreach ($products as $product): ?>

                                <option value="<?= e($product['id']) ?>">
                                    <?= e($product['sku']) ?>
                                    —
                                    <?= e($product['name']) ?>
                                    (Stok: <?= e($product['stock']) ?>)
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="field">

                        <label for="quantity">Jumlah Barang</label>

                        <input
                            type="number"
                            id="quantity"
                            name="quantity"
                            min="1"
                            max="2147483647"
                            step="1"
                            placeholder="Contoh: 10"
                            required
                        >

                        <p class="hint">
                            Jumlah harus berupa bilangan bulat lebih dari nol.
                        </p>

                    </div>

                    <div class="field full">

                        <label for="notes">Catatan Transaksi</label>

                        <textarea
                            id="notes"
                            name="notes"
                            maxlength="5000"
                            placeholder="Contoh: Pembelian stok dari supplier atau pengeluaran barang..."
                        ></textarea>

                    </div>

                </div>

                <div class="form-actions">

                    <button
                        type="submit"
                        class="button primary"
                    >
                        Simpan Transaksi
                    </button>

                </div>

            </form>

        <?php endif; ?>

    </section>

    <!-- Histori -->

    <section class="panel">

        <h2>Histori Transaksi Terbaru</h2>

        <p class="panel-description">
            Maksimal 20 transaksi terakhir ditampilkan di sini.
        </p>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>SKU</th>
                        <th>Nama Produk</th>
                        <th>Jenis</th>
                        <th>Jumlah</th>
                        <th>Catatan</th>
                        <th>Pengguna</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($history)): ?>

                    <tr>
                        <td colspan="7" class="empty">
                            Belum ada histori transaksi.
                            Silakan catat transaksi pertama.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($history as $movement): ?>

                        <tr>

                            <td>
                                <?= e(date(
                                    'd-m-Y H:i',
                                    strtotime($movement['created_at'])
                                )) ?>
                            </td>

                            <td>
                                <?= e($movement['sku']) ?>
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

                            <td>
                                <?= e($movement['quantity']) ?> unit
                            </td>

                            <td>
                                <?= e($movement['notes'] ?: '-') ?>
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

    <footer class="footer">
        Inventory Management System &copy; <?= e(date('Y')) ?>
    </footer>

</main>

</body>
</html>
