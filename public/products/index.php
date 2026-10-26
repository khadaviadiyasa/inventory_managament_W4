
<?php
session_start();

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

$message = '';
$messageType = 'success';

// Mengambil kategori dan supplier untuk formulir.
$categories = $pdo->query("
    SELECT id, name
    FROM categories
    ORDER BY name ASC
")->fetchAll();

$suppliers = $pdo->query("
    SELECT id, name
    FROM suppliers
    ORDER BY name ASC
")->fetchAll();

$supplierIdsAvailable = array_map(
    'intval',
    array_column($suppliers, 'id')
);

// ==========================================
// PROSES TAMBAH, EDIT, DAN HAPUS PRODUK
// ==========================================

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

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = filter_var(
                $_POST['id'] ?? 0,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]]
            );

            $categoryId = filter_var(
                $_POST['category_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            $sku = trim((string) ($_POST['sku'] ?? ''));
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim(
                (string) ($_POST['description'] ?? '')
            );

            $priceInput = $_POST['price'] ?? '';
            $minimumInput = filter_var(
                $_POST['minimum_stock'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]]
            );

            if ($id === false || $id === null) {
                throw new RuntimeException('ID produk tidak valid.');
            }

            if (
                $categoryId === false ||
                !in_array((int) $categoryId, array_map(
                    'intval',
                    array_column($categories, 'id')
                ), true)
            ) {
                throw new RuntimeException(
                    'Kategori wajib dipilih dan harus tersedia.'
                );
            }

            if ($sku === '' || strlen($sku) > 50) {
                throw new RuntimeException(
                    'SKU wajib diisi, maksimal 50 karakter.'
                );
            }

            if ($name === '') {
                throw new RuntimeException(
                    'Nama produk wajib diisi.'
                );
            }

            $nameLength = function_exists('mb_strlen')
                ? mb_strlen($name, 'UTF-8')
                : strlen($name);

            if ($nameLength > 150) {
                throw new RuntimeException(
                    'Nama produk maksimal 150 karakter.'
                );
            }

            if (
                !is_scalar($priceInput) ||
                !is_numeric($priceInput) ||
                !is_finite((float) $priceInput) ||
                (float) $priceInput < 0
            ) {
                throw new RuntimeException(
                    'Harga harus berupa angka dan tidak boleh negatif.'
                );
            }

            if (
                $minimumInput === false ||
                $minimumInput === null
            ) {
                throw new RuntimeException(
                    'Stok minimum harus berupa bilangan bulat nol atau lebih.'
                );
            }

            $price = (float) $priceInput;
            $minimumStock = (int) $minimumInput;

            // Stok tidak diedit di sini.
            // Perubahan stok hanya dilakukan melalui transaksi stok.

            $selectedIds = $_POST['supplier_ids'] ?? [];

            if (!is_array($selectedIds)) {
                throw new RuntimeException(
                    'Pilihan supplier tidak valid.'
                );
            }

            $selectedIds = array_unique(
                array_map('intval', $selectedIds)
            );

            foreach ($selectedIds as $supplierId) {
                if (
                    $supplierId <= 0 ||
                    !in_array(
                        $supplierId,
                        $supplierIdsAvailable,
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Supplier yang dipilih tidak valid.'
                    );
                }
            }

            if (empty($selectedIds)) {
                throw new RuntimeException(
                    'Pilih minimal satu supplier untuk produk ini.'
                );
            }

            $supplierPriceInput = $_POST['supplier_price'] ?? [];

            if (!is_array($supplierPriceInput)) {
                throw new RuntimeException(
                    'Data harga supplier tidak valid.'
                );
            }

            $supplierPrices = [];

            foreach ($selectedIds as $supplierId) {
                $supplierPrice = $supplierPriceInput[$supplierId]
                    ?? $supplierPriceInput[(string) $supplierId]
                    ?? null;

                if (
                    !is_scalar($supplierPrice) ||
                    !is_numeric($supplierPrice) ||
                    !is_finite((float) $supplierPrice) ||
                    (float) $supplierPrice < 0
                ) {
                    throw new RuntimeException(
                        'Harga supplier harus berupa angka nol atau lebih.'
                    );
                }

                $supplierPrices[$supplierId] =
                    (float) $supplierPrice;
            }

            $pdo->beginTransaction();

            if ((int) $id > 0) {
                // Memastikan produk yang diedit benar-benar ada.
                $check = $pdo->prepare("
                    SELECT id
                    FROM products
                    WHERE id = :id
                ");
                $check->execute(['id' => (int) $id]);

                if (!$check->fetch()) {
                    throw new RuntimeException(
                        'Produk yang ingin diedit tidak ditemukan.'
                    );
                }

                $stmt = $pdo->prepare("
                    UPDATE products
                    SET category_id = :category_id,
                        sku = :sku,
                        name = :name,
                        description = :description,
                        price = :price,
                        minimum_stock = :minimum_stock
                    WHERE id = :id
                ");

                $stmt->execute([
                    'category_id' => (int) $categoryId,
                    'sku' => $sku,
                    'name' => $name,
                    'description' => $description,
                    'price' => $price,
                    'minimum_stock' => $minimumStock,
                    'id' => (int) $id
                ]);

                $productId = (int) $id;
                $successMessage = 'Produk berhasil diperbarui.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO products (
                        category_id,
                        sku,
                        name,
                        description,
                        price,
                        stock,
                        minimum_stock
                    ) VALUES (
                        :category_id,
                        :sku,
                        :name,
                        :description,
                        :price,
                        0,
                        :minimum_stock
                    )
                ");

                $stmt->execute([
                    'category_id' => (int) $categoryId,
                    'sku' => $sku,
                    'name' => $name,
                    'description' => $description,
                    'price' => $price,
                    'minimum_stock' => $minimumStock
                ]);

                $productId = (int) $pdo->lastInsertId();
                $successMessage = 'Produk berhasil ditambahkan.';
            }

            // Memperbarui relasi many-to-many produk-supplier.
            $deleteRelations = $pdo->prepare("
                DELETE FROM product_suppliers
                WHERE product_id = :product_id
            ");

            $deleteRelations->execute([
                'product_id' => $productId
            ]);

            $insertRelation = $pdo->prepare("
                INSERT INTO product_suppliers (
                    product_id,
                    supplier_id,
                    supplier_price
                ) VALUES (
                    :product_id,
                    :supplier_id,
                    :supplier_price
                )
            ");

            foreach ($supplierPrices as $supplierId => $supplierPrice) {
                $insertRelation->execute([
                    'product_id' => $productId,
                    'supplier_id' => $supplierId,
                    'supplier_price' => $supplierPrice
                ]);
            }

            $pdo->commit();

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => $successMessage
            ];

        } elseif ($action === 'delete') {
            $id = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($id === false || $id === null) {
                throw new RuntimeException(
                    'ID produk tidak valid.'
                );
            }

            $pdo->beginTransaction();

            // Hapus relasi supplier terlebih dahulu.
            $stmt = $pdo->prepare("
                DELETE FROM product_suppliers
                WHERE product_id = :id
            ");
            $stmt->execute(['id' => $id]);

            $stmt = $pdo->prepare("
                DELETE FROM products
                WHERE id = :id
            ");
            $stmt->execute(['id' => $id]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException(
                    'Produk tidak ditemukan.'
                );
            }

            $pdo->commit();

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Produk berhasil dihapus.'
            ];

        } else {
            throw new RuntimeException(
                'Aksi yang diminta tidak dikenal.'
            );
        }

    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => $ex->getMessage()
        ];

    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($ex->getCode() === '23000') {
            $errorMessage = 'SKU mungkin sudah digunakan, atau produk masih memiliki histori transaksi sehingga tidak bisa dihapus.';
        } else {
            error_log($ex->getMessage());
            $errorMessage = 'Operasi database gagal. Silakan periksa data dan konfigurasi.';
        }

        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => $errorMessage
        ];
    }

    header('Location: index.php');
    exit;
}

// ==========================================
// PESAN HASIL OPERASI
// ==========================================

if (isset($_SESSION['flash'])) {
    $message = $_SESSION['flash']['message'];
    $messageType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

// ==========================================
// MENGAMBIL DATA UNTUK FORM EDIT
// ==========================================

$editProduct = null;
$editSupplierPrices = [];

if (isset($_GET['edit'])) {
    $editId = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($editId !== false && $editId !== null) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM products
            WHERE id = :id
        ");
        $stmt->execute(['id' => $editId]);
        $editProduct = $stmt->fetch() ?: null;

        if ($editProduct) {
            $stmt = $pdo->prepare("
                SELECT supplier_id, supplier_price
                FROM product_suppliers
                WHERE product_id = :product_id
            ");

            $stmt->execute([
                'product_id' => (int) $editProduct['id']
            ]);

            foreach ($stmt->fetchAll() as $relation) {
                $editSupplierPrices[(int) $relation['supplier_id']] =
                    $relation['supplier_price'];
            }
        } else {
            $message = 'Produk yang dipilih tidak ditemukan.';
            $messageType = 'error';
        }
    } else {
        $message = 'ID produk tidak valid.';
        $messageType = 'error';
    }
}

// ==========================================
// PENCARIAN, FILTER, SORTING, PAGINATION
// ==========================================

$search = isset($_GET['q']) && is_string($_GET['q'])
    ? trim($_GET['q'])
    : '';

$categoryFilter = filter_var(
    $_GET['category'] ?? 0,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0]]
);

if ($categoryFilter === false || $categoryFilter === null) {
    $categoryFilter = 0;
}

$sortOptions = [
    'sku' => 'p.sku',
    'name' => 'p.name',
    'category' => 'c.name',
    'price' => 'p.price',
    'stock' => 'p.stock',
    'created' => 'p.id'
];

$sort = isset($_GET['sort']) && is_string($_GET['sort'])
    ? $_GET['sort']
    : 'created';

if (!isset($sortOptions[$sort])) {
    $sort = 'created';
}

$direction = isset($_GET['dir']) && is_string($_GET['dir'])
    ? strtoupper($_GET['dir'])
    : 'DESC';

if (!in_array($direction, ['ASC', 'DESC'], true)) {
    $direction = 'DESC';
}

$perPage = 10;

$page = filter_var(
    $_GET['page'] ?? 1,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$page = ($page === false || $page === null) ? 1 : $page;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(p.name LIKE :search_name OR p.sku LIKE :search_sku)';

    $keyword = '%' . $search . '%';

    $params['search_name'] = $keyword;
    $params['search_sku'] = $keyword;
}

if (
    $categoryFilter > 0 &&
    in_array(
        (int) $categoryFilter,
        array_map('intval', array_column($categories, 'id')),
        true
    )
) {
    $where[] = 'p.category_id = :category_id';
    $params['category_id'] = (int) $categoryFilter;
}

$whereSql = empty($where)
    ? ''
    : ' WHERE ' . implode(' AND ', $where);

// Menghitung total produk yang sesuai filter.
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM products p
    INNER JOIN categories c ON c.id = p.category_id
    $whereSql
");

$countStmt->execute($params);
$totalProducts = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalProducts / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Daftar produk juga menampilkan supplier yang terhubung.
$sql = "
    SELECT
        p.id,
        p.sku,
        p.name,
        p.description,
        p.price,
        p.stock,
        p.minimum_stock,
        p.created_at,
        c.name AS category_name,
        (
            SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ')
            FROM product_suppliers ps
            INNER JOIN suppliers s ON s.id = ps.supplier_id
            WHERE ps.product_id = p.id
        ) AS supplier_names
    FROM products p
    INNER JOIN categories c ON c.id = p.category_id
    $whereSql
    ORDER BY {$sortOptions[$sort]} $direction
    LIMIT :limit OFFSET :offset
";

$listStmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    $listStmt->bindValue(
        ':' . $key,
        $value,
        $key === 'category_id'
            ? PDO::PARAM_INT
            : PDO::PARAM_STR
    );
}

$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();

$products = $listStmt->fetchAll();

function productPageUrl(array $changes = []): string
{
    $query = $_GET;

    foreach ($changes as $key => $value) {
        $query[$key] = $value;
    }

    return 'index.php?' . http_build_query($query);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Produk | Inventory Management</title>

    <style>
        :root {
            --ocean-dark: #164e63;
            --ocean-primary: #0284c7;
            --ocean-light: #dff4fc;
            --ocean-pale: #f0faff;
            --ocean-border: #cceaf5;
            --muted: #64748b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: var(--ocean-pale);
            color: var(--ocean-dark);
        }

        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            width: 230px;
            padding: 25px 18px;
            background: linear-gradient(180deg, #e0f7ff, #c9edf7);
            border-right: 1px solid var(--ocean-border);
            overflow-y: auto;
        }

        .brand {
            margin-bottom: 38px;
            font-size: 19px;
            font-weight: 800;
            color: #075985;
        }

        .brand small {
            display: block;
            margin-top: 5px;
            color: #0e7490;
            font-size: 11px;
            font-weight: normal;
        }

        .menu-label {
            margin: 0 0 12px 10px;
            color: #52859a;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .nav-item {
            display: block;
            padding: 12px;
            margin-bottom: 8px;
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

        .panel {
            margin-bottom: 24px;
            padding: 24px;
            background: white;
            border: 1px solid var(--ocean-border);
            border-radius: 14px;
            box-shadow: 0 5px 16px rgba(14, 116, 144, 0.04);
        }

        .panel h2 {
            margin: 0 0 20px;
            font-size: 17px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #355d70;
            font-size: 12px;
            font-weight: bold;
        }

        input[type="text"],
        input[type="number"],
        select,
        textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbdde6;
            border-radius: 8px;
            outline: none;
            background: #fbfeff;
            color: #164e63;
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
            min-height: 85px;
            resize: vertical;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .field {
            min-width: 0;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .field small {
            display: block;
            margin-top: 7px;
            color: #8a9ba9;
            font-size: 11px;
            line-height: 1.5;
        }

        .supplier-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .supplier-card {
            padding: 14px;
            border: 1px solid var(--ocean-border);
            border-radius: 10px;
            background: #f8fdff;
        }

        .supplier-name {
            display: flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 12px;
            color: #164e63;
            font-size: 13px;
            font-weight: bold;
        }

        .supplier-name input {
            width: auto;
        }

        .supplier-card input[type="number"] {
            margin-top: 3px;
        }

        .buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            margin-top: 22px;
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
            color: #164e63;
        }

        .danger {
            background: #fee2e2;
            color: #b91c1c;
        }

        .message {
            margin-bottom: 20px;
            padding: 13px 15px;
            border-radius: 9px;
            font-size: 13px;
        }

        .message.success {
            background: #dcfce7;
            color: #166534;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1.2fr 1fr 1fr auto;
            gap: 10px;
            align-items: end;
        }

        .filter-field label {
            font-size: 11px;
        }

        .filter-field input,
        .filter-field select {
            min-width: 0;
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

        .sku {
            color: #0e7490;
            font-weight: bold;
        }

        .product-name {
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

        .row-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .row-actions .button {
            padding: 7px 9px;
            font-size: 11px;
        }

        .row-actions form {
            display: inline;
        }

        .empty {
            padding: 26px;
            text-align: center;
            color: var(--muted);
        }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .pagination-info {
            color: var(--muted);
            font-size: 12px;
        }

        .pagination-links {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .page-link {
            display: inline-block;
            min-width: 34px;
            padding: 9px;
            border: 1px solid var(--ocean-border);
            border-radius: 7px;
            background: white;
            color: #075985;
            text-align: center;
            text-decoration: none;
            font-size: 12px;
        }

        .page-link.current {
            background: #0284c7;
            color: white;
            border-color: #0284c7;
        }

        .hint {
            color: #64748b;
            font-size: 12px;
            line-height: 1.6;
        }

        @media (max-width: 1150px) {
            .filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filter-field.search-field {
                grid-column: 1 / -1;
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

            .panel {
                padding: 18px;
            }

            .form-grid,
            .supplier-grid,
            .filter-grid {
                grid-template-columns: 1fr;
            }

            .field.full,
            .filter-field.search-field {
                grid-column: auto;
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

    <a class="nav-item active" href="index.php">
        ▣ &nbsp; Produk
    </a>

    <a class="nav-item" href="../suppliers/index.php">
        ♧ &nbsp; Supplier
    </a>

    <a class="nav-item" href="../stock/index.php">
        ↕ &nbsp; Transaksi Stok
    </a>

    <a class="nav-item" href="../reports/index.php">
        ▥ &nbsp; Laporan
    </a>

    <a class="nav-item" href="../users/index.php">
        ♙ &nbsp; Pengguna
    </a>
</aside>

<main>

    <header class="topbar">
        <div>
            <h1>Manajemen Produk</h1>
            <p class="subtitle">
                Kelola data produk, kategori, supplier, dan stok inventory.
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

    <!-- FORM TAMBAH DAN EDIT PRODUK -->

    <section class="panel">

        <h2>
            <?= $editProduct
                ? 'Edit Produk'
                : 'Tambah Produk Baru' ?>
        </h2>

        <?php if (empty($categories)): ?>

            <p class="hint">
                Belum ada kategori. Tambahkan kategori terlebih dahulu.
            </p>

            <a class="button primary" href="../categories/index.php">
                Buka Kategori
            </a>

        <?php elseif (empty($suppliers)): ?>

            <p class="hint">
                Belum ada supplier. Tambahkan supplier terlebih dahulu.
            </p>

            <a class="button primary" href="../suppliers/index.php">
                Buka Supplier
            </a>

        <?php else: ?>

            <form method="POST" action="index.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['csrf_token']) ?>"
                >

                <input type="hidden" name="action" value="save">

                <input
                    type="hidden"
                    name="id"
                    value="<?= e($editProduct['id'] ?? 0) ?>"
                >

                <div class="form-grid">

                    <div class="field">
                        <label for="sku">SKU / Kode Produk</label>

                        <input
                            type="text"
                            id="sku"
                            name="sku"
                            maxlength="50"
                            placeholder="Contoh: BRG-004"
                            value="<?= e($editProduct['sku'] ?? '') ?>"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="name">Nama Produk</label>

                        <input
                            type="text"
                            id="name"
                            name="name"
                            maxlength="150"
                            placeholder="Masukkan nama produk"
                            value="<?= e($editProduct['name'] ?? '') ?>"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="category_id">Kategori</label>

                        <select
                            id="category_id"
                            name="category_id"
                            required
                        >
                            <option value="">-- Pilih Kategori --</option>

                            <?php foreach ($categories as $category): ?>
                                <option
                                    value="<?= e($category['id']) ?>"
                                    <?= isset($editProduct['category_id']) &&
                                        (int) $editProduct['category_id'] ===
                                        (int) $category['id']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($category['name']) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="field">
                        <label for="price">Harga Jual (Rp)</label>

                        <input
                            type="number"
                            id="price"
                            name="price"
                            min="0"
                            step="0.01"
                            value="<?= e($editProduct['price'] ?? '0') ?>"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="minimum_stock">Stok Minimum</label>

                        <input
                            type="number"
                            id="minimum_stock"
                            name="minimum_stock"
                            min="0"
                            step="1"
                            value="<?= e($editProduct['minimum_stock'] ?? '5') ?>"
                            required
                        >
                    </div>

                    <div class="field">
                        <label>Stok Saat Ini</label>

                        <input
                            type="text"
                            value="<?= e($editProduct['stock'] ?? 0) ?> unit"
                            disabled
                        >

                        <small>
                            Stok hanya dapat berubah melalui transaksi barang masuk/keluar.
                        </small>
                    </div>

                    <div class="field full">
                        <label for="description">Deskripsi Produk</label>

                        <textarea
                            id="description"
                            name="description"
                            placeholder="Masukkan deskripsi produk..."
                        ><?= e($editProduct['description'] ?? '') ?></textarea>
                    </div>

                    <div class="field full">
                        <label>Supplier Produk</label>

                        <p class="hint">
                            Pilih minimal satu supplier. Satu produk boleh
                            memiliki beberapa supplier.
                        </p>

                        <div class="supplier-grid">

                            <?php foreach ($suppliers as $supplier): ?>

                                <?php
                                $sid = (int) $supplier['id'];
                                $isSelected = isset($editSupplierPrices[$sid]);
                                $supplierPriceValue =
                                    $editSupplierPrices[$sid] ?? '0';
                                ?>

                                <div class="supplier-card">

                                    <label class="supplier-name">
                                        <input
                                            type="checkbox"
                                            name="supplier_ids[]"
                                            value="<?= e($sid) ?>"
                                            <?= $isSelected ? 'checked' : '' ?>
                                        >

                                        <?= e($supplier['name']) ?>
                                    </label>

                                    <label for="supplier_price_<?= e($sid) ?>">
                                        Harga dari supplier (Rp)
                                    </label>

                                    <input
                                        type="number"
                                        id="supplier_price_<?= e($sid) ?>"
                                        name="supplier_price[<?= e($sid) ?>]"
                                        min="0"
                                        step="0.01"
                                        value="<?= e($supplierPriceValue) ?>"
                                    >

                                </div>

                            <?php endforeach; ?>

                        </div>
                    </div>

                </div>

                <div class="buttons">

                    <button type="submit" class="button primary">
                        <?= $editProduct
                            ? 'Simpan Perubahan'
                            : 'Tambah Produk' ?>
                    </button>

                    <?php if ($editProduct): ?>
                        <a href="index.php" class="button secondary">
                            Batal Edit
                        </a>
                    <?php endif; ?>

                </div>

            </form>

        <?php endif; ?>

    </section>

    <!-- SEARCH, FILTER, SORTING -->

    <section class="panel">

        <h2>Daftar Produk</h2>

        <form method="GET" action="index.php">

            <div class="filter-grid">

                <div class="filter-field search-field">
                    <label for="q">Pencarian</label>

                    <input
                        type="text"
                        id="q"
                        name="q"
                        placeholder="Cari nama produk atau SKU..."
                        value="<?= e($search) ?>"
                    >
                </div>

                <div class="filter-field">
                    <label for="category">Filter Kategori</label>

                    <select id="category" name="category">
                        <option value="0">Semua Kategori</option>

                        <?php foreach ($categories as $category): ?>
                            <option
                                value="<?= e($category['id']) ?>"
                                <?= (int) $categoryFilter ===
                                    (int) $category['id']
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($category['name']) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="filter-field">
                    <label for="sort">Urutkan Berdasarkan</label>

                    <select id="sort" name="sort">
                        <option value="created" <?= $sort === 'created' ? 'selected' : '' ?>>
                            Terbaru
                        </option>

                        <option value="sku" <?= $sort === 'sku' ? 'selected' : '' ?>>
                            SKU
                        </option>

                        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>
                            Nama Produk
                        </option>

                        <option value="category" <?= $sort === 'category' ? 'selected' : '' ?>>
                            Kategori
                        </option>

                        <option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>
                            Harga
                        </option>

                        <option value="stock" <?= $sort === 'stock' ? 'selected' : '' ?>>
                            Stok
                        </option>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="dir">Urutan</label>

                    <select id="dir" name="dir">
                        <option value="ASC" <?= $direction === 'ASC' ? 'selected' : '' ?>>
                            Naik (ASC)
                        </option>

                        <option value="DESC" <?= $direction === 'DESC' ? 'selected' : '' ?>>
                            Turun (DESC)
                        </option>
                    </select>
                </div>

                <div class="filter-field">
                    <button type="submit" class="button primary">
                        Terapkan
                    </button>
                </div>

            </div>

            <div class="buttons">
                <a href="index.php" class="button secondary">
                    Reset Filter
                </a>
            </div>

        </form>

        <div class="table-wrap" style="margin-top: 22px;">

            <table>
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Nama Produk</th>
                        <th>Kategori</th>
                        <th>Supplier</th>
                        <th>Harga Jual</th>
                        <th>Stok</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($products)): ?>

                    <tr>
                        <td colspan="8" class="empty">
                            Tidak ada produk yang sesuai dengan pencarian.
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

                            <td>
                                <?= e($product['supplier_names'] ?: '-') ?>
                            </td>

                            <td>
                                Rp <?= e(number_format(
                                    (float) $product['price'],
                                    0,
                                    ',',
                                    '.'
                                )) ?>
                            </td>

                            <td>
                                <?= e($product['stock']) ?>
                            </td>

                            <td>
                                <?php if (
                                    (int) $product['stock'] <=
                                    (int) $product['minimum_stock']
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

                            <td>
                                <div class="row-actions">

                                    <a
                                        class="button secondary"
                                        href="<?= e(productPageUrl([
                                            'edit' => $product['id'],
                                            'page' => $page
                                        ])) ?>"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="index.php"
                                        onsubmit="return confirm('Yakin ingin menghapus produk ini? Produk dengan histori transaksi tidak dapat dihapus.');"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($_SESSION['csrf_token']) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete"
                                        >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= e($product['id']) ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="button danger"
                                        >
                                            Hapus
                                        </button>
                                    </form>

                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>

        </div>

        <!-- PAGINATION -->

        <div class="pagination">

            <div class="pagination-info">
                Total <?= e($totalProducts) ?> produk.
                Halaman <?= e($page) ?> dari <?= e($totalPages) ?>.
            </div>

            <div class="pagination-links">

                <?php if ($page > 1): ?>
                    <a
                        class="page-link"
                        href="<?= e(productPageUrl(['page' => 1])) ?>"
                    >
                        «
                    </a>

                    <a
                        class="page-link"
                        href="<?= e(productPageUrl(['page' => $page - 1])) ?>"
                    >
                        ‹
                    </a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>

                    <a
                        class="page-link <?= $page === $i ? 'current' : '' ?>"
                        href="<?= e(productPageUrl(['page' => $i])) ?>"
                    >
                        <?= e($i) ?>
                    </a>

                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a
                        class="page-link"
                        href="<?= e(productPageUrl(['page' => $page + 1])) ?>"
                    >
                        ›
                    </a>

                    <a
                        class="page-link"
                        href="<?= e(productPageUrl(['page' => $totalPages])) ?>"
                    >
                        »
                    </a>
                <?php endif; ?>

            </div>

        </div>

    </section>

</main>

</body>
</html>
