
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Login wajib. Admin dan staff boleh melihat produk.
if (
    !isset($_SESSION['user']['id']) ||
    !isset($_SESSION['user']['role']) ||
    !in_array($_SESSION['user']['role'], ['admin', 'staff'], true)
) {
    header('Location: ../login.php');
    exit;
}

$role = $_SESSION['user']['role'];

// Staff dilarang mengirim perubahan produk.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role !== 'admin') {
    http_response_code(403);
    exit('403 Forbidden: Hanya admin yang boleh mengubah produk.');
}

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

// Mengambil data kategori dan supplier.
$categories = $pdo->query(
    'SELECT id, name FROM categories ORDER BY name'
)->fetchAll();

$suppliers = $pdo->query(
    'SELECT id, name FROM suppliers ORDER BY name'
)->fetchAll();

$supplierIdsAvailable = array_map(
    'intval',
    array_column($suppliers, 'id')
);

// ==========================================
// TAMBAH, EDIT, DAN HAPUS PRODUK
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => 'Permintaan tidak valid. Silakan muat ulang halaman.'
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
                !in_array((int) $categoryId,
                    array_map('intval', array_column($categories, 'id')),
                    true)
            ) {
                throw new RuntimeException('Silakan pilih kategori yang tersedia.');
            }

            if ($sku === '' || strlen($sku) > 50) {
                throw new RuntimeException('SKU wajib diisi dan maksimal 50 karakter.');
            }

            if ($name === '' || strlen($name) > 150) {
                throw new RuntimeException('Nama produk wajib diisi dan maksimal 150 karakter.');
            }

            if (
                !is_scalar($priceInput) ||
                !is_numeric($priceInput) ||
                !is_finite((float) $priceInput) ||
                (float) $priceInput < 0
            ) {
                throw new RuntimeException('Harga harus berupa angka dan tidak boleh negatif.');
            }

            if ($minimumInput === false || $minimumInput === null) {
                throw new RuntimeException('Stok minimum harus berupa bilangan bulat nol atau lebih.');
            }

            // Stok hanya berubah melalui transaksi barang masuk/keluar.
            $selectedIdsRaw = $_POST['supplier_ids'] ?? [];

            if (!is_array($selectedIdsRaw)) {
                throw new RuntimeException('Pilihan supplier tidak valid.');
            }

            $selectedIds = [];

            foreach ($selectedIdsRaw as $rawId) {
                if (!is_scalar($rawId) || !ctype_digit((string) $rawId)) {
                    throw new RuntimeException('Pilihan supplier tidak valid.');
                }

                $supplierId = (int) $rawId;

                if (!in_array($supplierId, $supplierIdsAvailable, true)) {
                    throw new RuntimeException('Supplier yang dipilih tidak ditemukan.');
                }

                $selectedIds[] = $supplierId;
            }

            $selectedIds = array_values(array_unique($selectedIds));

            if (empty($selectedIds)) {
                throw new RuntimeException('Pilih minimal satu supplier.');
            }

            $supplierPriceInput = $_POST['supplier_price'] ?? [];

            if (!is_array($supplierPriceInput)) {
                throw new RuntimeException('Harga supplier tidak valid.');
            }

            $supplierPrices = [];

            foreach ($selectedIds as $supplierId) {
                $rawPrice = $supplierPriceInput[$supplierId]
                    ?? $supplierPriceInput[(string) $supplierId]
                    ?? null;

                if (
                    !is_scalar($rawPrice) ||
                    !is_numeric($rawPrice) ||
                    !is_finite((float) $rawPrice) ||
                    (float) $rawPrice < 0
                ) {
                    throw new RuntimeException('Harga supplier harus berupa angka nol atau lebih.');
                }

                $supplierPrices[$supplierId] = (float) $rawPrice;
            }

            $pdo->beginTransaction();

            if ((int) $id > 0) {
                $check = $pdo->prepare(
                    'SELECT id FROM products WHERE id = :id'
                );
                $check->execute(['id' => (int) $id]);

                if (!$check->fetch()) {
                    throw new RuntimeException('Produk tidak ditemukan.');
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
                    'price' => (float) $priceInput,
                    'minimum_stock' => (int) $minimumInput,
                    'id' => (int) $id
                ]);

                $productId = (int) $id;
                $successMessage = 'Produk berhasil diperbarui.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO products (
                        category_id, sku, name, description,
                        price, stock, minimum_stock
                    ) VALUES (
                        :category_id, :sku, :name, :description,
                        :price, 0, :minimum_stock
                    )
                ");

                $stmt->execute([
                    'category_id' => (int) $categoryId,
                    'sku' => $sku,
                    'name' => $name,
                    'description' => $description,
                    'price' => (float) $priceInput,
                    'minimum_stock' => (int) $minimumInput
                ]);

                $productId = (int) $pdo->lastInsertId();
                $successMessage = 'Produk berhasil ditambahkan.';
            }

            // Mengatur relasi many-to-many produk dan supplier.
            $deleteRelations = $pdo->prepare(
                'DELETE FROM product_suppliers WHERE product_id = :id'
            );
            $deleteRelations->execute(['id' => $productId]);

            $insertRelation = $pdo->prepare("
                INSERT INTO product_suppliers
                    (product_id, supplier_id, supplier_price)
                VALUES
                    (:product_id, :supplier_id, :supplier_price)
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
                throw new RuntimeException('ID produk tidak valid.');
            }

            $pdo->beginTransaction();

            $check = $pdo->prepare(
                'SELECT id FROM products WHERE id = :id'
            );
            $check->execute(['id' => $id]);

            if (!$check->fetch()) {
                throw new RuntimeException('Produk tidak ditemukan.');
            }

            // Histori transaksi tetap dilindungi oleh foreign key.
            $deleteRelations = $pdo->prepare(
                'DELETE FROM product_suppliers WHERE product_id = :id'
            );
            $deleteRelations->execute(['id' => $id]);

            $deleteProduct = $pdo->prepare(
                'DELETE FROM products WHERE id = :id'
            );
            $deleteProduct->execute(['id' => $id]);

            $pdo->commit();

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Produk berhasil dihapus.'
            ];

        } else {
            throw new RuntimeException('Aksi tidak dikenal.');
        }

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($ex instanceof PDOException && $ex->getCode() === '23000') {
            $errorMessage = 'SKU mungkin sudah digunakan atau produk masih memiliki histori transaksi.';
        } elseif ($ex instanceof RuntimeException) {
            $errorMessage = $ex->getMessage();
        } else {
            error_log($ex->getMessage());
            $errorMessage = 'Operasi gagal. Periksa data dan konfigurasi database.';
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
// PESAN DAN DATA UNTUK EDIT
// ==========================================

if (isset($_SESSION['flash'])) {
    $message = $_SESSION['flash']['message'];
    $messageType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

$editProduct = null;
$editSupplierPrices = [];

if ($role === 'admin' && isset($_GET['edit'])) {
    $editId = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($editId !== false && $editId !== null) {
        $stmt = $pdo->prepare(
            'SELECT * FROM products WHERE id = :id'
        );
        $stmt->execute(['id' => $editId]);
        $editProduct = $stmt->fetch() ?: null;

        if ($editProduct) {
            $stmt = $pdo->prepare("
                SELECT supplier_id, supplier_price
                FROM product_suppliers
                WHERE product_id = :id
            ");
            $stmt->execute(['id' => $editId]);

            foreach ($stmt->fetchAll() as $relation) {
                $editSupplierPrices[(int) $relation['supplier_id']] =
                    $relation['supplier_price'];
            }
        } else {
            $message = 'Produk tidak ditemukan.';
            $messageType = 'error';
        }
    }
}

// ==========================================
// SEARCH, FILTER, SORTING, PAGINATION
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
    $params['search_name'] = '%' . $search . '%';
    $params['search_sku'] = '%' . $search . '%';
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
        $key === 'category_id' ? PDO::PARAM_INT : PDO::PARAM_STR
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
    --dark: #164e63;
    --primary: #0284c7;
    --pale: #f0faff;
    --border: #cceaf5;
    --muted: #64748b;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    font-family: Arial, sans-serif;
    color: var(--dark);
    background: var(--pale);
}
.sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    width: 230px;
    padding: 25px 18px;
    overflow-y: auto;
    background: linear-gradient(180deg, #e0f7ff, #c9edf7);
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
}
.nav-item {
    display: block;
    padding: 12px;
    margin-bottom: 7px;
    border-radius: 9px;
    color: #28667f;
    text-decoration: none;
    font-size: 13px;
}
.nav-item:hover { background: #c4eafa; }
.nav-item.active {
    background: #7dd3fc;
    color: #083344;
    font-weight: bold;
}
main { margin-left: 230px; padding: 30px; }
.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}
h1 { margin: 0 0 7px; font-size: 27px; }
.subtitle { margin: 0; color: var(--muted); font-size: 13px; }
.panel {
    padding: 23px;
    margin-bottom: 23px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: 0 5px 16px rgba(14,116,144,.04);
}
.panel h2 { margin-top: 0; font-size: 17px; }
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 17px;
}
.field.full { grid-column: 1 / -1; }
label {
    display: block;
    margin-bottom: 7px;
    color: #355d70;
    font-size: 12px;
    font-weight: bold;
}
input, select, textarea {
    width: 100%;
    padding: 11px;
    border: 1px solid #cbdde6;
    border-radius: 8px;
    background: #fbfeff;
    color: var(--dark);
    font: inherit;
    font-size: 13px;
}
input:focus, select:focus, textarea:focus {
    outline: 2px solid #bae6fd;
    border-color: #38bdf8;
}
input[type=checkbox] { width: auto; }
textarea { min-height: 80px; resize: vertical; }
.supplier-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 12px;
}
.supplier-card {
    padding: 13px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #f8fdff;
}
.supplier-card .supplier-check {
    display: flex;
    align-items: center;
    gap: 8px;
}
.supplier-card input[type=number] { margin-top: 5px; }
.button {
    display: inline-block;
    padding: 10px 13px;
    border: 0;
    border-radius: 8px;
    cursor: pointer;
    font-size: 12px;
    font-weight: bold;
    text-decoration: none;
}
.primary { background: #0284c7; color: white; }
.secondary { background: #e5f3f9; color: var(--dark); }
.danger { background: #fee2e2; color: #b91c1c; }
.buttons { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 18px; }
.message { padding: 12px; border-radius: 8px; margin-bottom: 18px; font-size: 13px; }
.message.success { background: #dcfce7; color: #166534; }
.message.error { background: #fee2e2; color: #991b1b; }
.hint { color: var(--muted); font-size: 12px; }
.filter-grid {
    display: grid;
    grid-template-columns: 2fr 1.2fr 1fr 1fr auto;
    gap: 10px;
    align-items: end;
}
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 12px; text-align: left; }
th {
    padding: 13px 11px;
    background: #effaff;
    color: #35748b;
    font-size: 10px;
    text-transform: uppercase;
    white-space: nowrap;
}
td { padding: 13px 11px; border-bottom: 1px solid #edf4f7; vertical-align: top; }
tbody tr:hover { background: #f7fcff; }
.sku { color: #0e7490; font-weight: bold; }
.product-name { color: var(--dark); font-weight: bold; }
.badge { padding: 5px 8px; border-radius: 20px; font-size: 10px; font-weight: bold; white-space: nowrap; }
.badge-low { background: #ffedd5; color: #9a3412; }
.badge-ok { background: #dcfce7; color: #166534; }
.actions { display: flex; flex-wrap: wrap; gap: 6px; }
.actions .button { padding: 7px 9px; font-size: 11px; }
.actions form { display: inline; }
.empty { padding: 25px; text-align: center; color: var(--muted); }
.pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 18px;
}
.pagination-info { color: var(--muted); font-size: 12px; }
.pagination-links { display: flex; gap: 5px; flex-wrap: wrap; }
.page-link {
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: white;
    color: #075985;
    text-decoration: none;
    font-size: 12px;
}
.page-link.current { background: #0284c7; color: white; }
@media(max-width:1150px) {
    .filter-grid { grid-template-columns: repeat(2,minmax(0,1fr)); }
}
@media(max-width:760px) {
    .sidebar { position: static; width: 100%; padding: 18px; }
    .brand { margin-bottom: 20px; }
    main { margin-left: 0; padding: 18px; }
    .form-grid, .supplier-grid, .filter-grid { grid-template-columns: 1fr; }
    .field.full { grid-column: auto; }
    h1 { font-size: 23px; }
}
</style>
</head>

<body>

<aside class="sidebar">
    <div class="brand">INVENTORY<small>Management System</small></div>
    <p class="menu-label">MENU UTAMA</p>

    <a class="nav-item" href="../dashboard.php">⌂ &nbsp; Dashboard</a>
    <a class="nav-item" href="../categories/index.php">▤ &nbsp; Kategori</a>
    <a class="nav-item active" href="index.php">▦ &nbsp; Produk</a>

    <?php if ($role === 'admin'): ?>
        <a class="nav-item" href="../suppliers/index.php">♧ &nbsp; Supplier</a>
        <a class="nav-item" href="../users/index.php">♙ &nbsp; Pengguna</a>
    <?php endif; ?>

    <a class="nav-item" href="../stock/index.php">↕ &nbsp; Transaksi Stok</a>
    <a class="nav-item" href="../reports/index.php">▥ &nbsp; Laporan</a>
</aside>

<main>

<header class="topbar">
    <div>
        <h1>Manajemen Produk</h1>
        <p class="subtitle">Pencarian, filter, sorting, dan pengelolaan produk inventory.</p>
    </div>

    <a class="button secondary" href="../dashboard.php">← Dashboard</a>
</header>

<?php if (!empty($message)): ?>
    <div class="message <?= e($messageType) ?>"><?= e($message) ?></div>
<?php endif; ?>

<?php if ($role === 'admin'): ?>
<section class="panel">
    <h2><?= $editProduct ? 'Edit Produk' : 'Tambah Produk Baru' ?></h2>

    <?php if (empty($categories)): ?>
        <p class="hint">Tambahkan kategori terlebih dahulu.</p>
        <a class="button primary" href="../categories/index.php">Buka Kategori</a>

    <?php elseif (empty($suppliers)): ?>
        <p class="hint">Tambahkan supplier terlebih dahulu.</p>
        <a class="button primary" href="../suppliers/index.php">Buka Supplier</a>

    <?php else: ?>
    <form method="POST" action="index.php">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= e($editProduct['id'] ?? 0) ?>">

        <div class="form-grid">
            <div class="field">
                <label for="sku">SKU / Kode Produk</label>
                <input id="sku" name="sku" maxlength="50" required value="<?= e($editProduct['sku'] ?? '') ?>" placeholder="BRG-004">
            </div>

            <div class="field">
                <label for="name">Nama Produk</label>
                <input id="name" name="name" maxlength="150" required value="<?= e($editProduct['name'] ?? '') ?>">
            </div>

            <div class="field">
                <label for="category_id">Kategori</label>
                <select id="category_id" name="category_id" required>
                    <option value="">-- Pilih Kategori --</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e($category['id']) ?>"
                            <?= isset($editProduct['category_id']) &&
                                (int)$editProduct['category_id'] === (int)$category['id']
                                ? 'selected' : '' ?>>
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="price">Harga Jual (Rp)</label>
                <input id="price" type="number" name="price" min="0" step="0.01" required value="<?= e($editProduct['price'] ?? '0') ?>">
            </div>

            <div class="field">
                <label for="minimum_stock">Stok Minimum</label>
                <input id="minimum_stock" type="number" name="minimum_stock" min="0" step="1" required value="<?= e($editProduct['minimum_stock'] ?? '5') ?>">
            </div>

            <div class="field">
                <label>Stok Saat Ini</label>
                <input type="text" disabled value="<?= e($editProduct['stock'] ?? 0) ?> unit">
                <p class="hint">Perubahan stok hanya melalui transaksi stok.</p>
            </div>

            <div class="field full">
                <label for="description">Deskripsi</label>
                <textarea id="description" name="description"><?= e($editProduct['description'] ?? '') ?></textarea>
            </div>

            <div class="field full">
                <label>Supplier Produk</label>
                <p class="hint">Pilih minimal satu supplier dan masukkan harga dari supplier tersebut.</p>

                <div class="supplier-grid">
                <?php foreach ($suppliers as $supplier): ?>
                    <?php
                    $sid = (int)$supplier['id'];
                    $selected = isset($editSupplierPrices[$sid]);
                    $supplierPrice = $editSupplierPrices[$sid] ?? '0';
                    ?>
                    <div class="supplier-card">
                        <label class="supplier-check">
                            <input type="checkbox" name="supplier_ids[]" value="<?= e($sid) ?>" <?= $selected ? 'checked' : '' ?>>
                            <?= e($supplier['name']) ?>
                        </label>

                        <label for="sp<?= e($sid) ?>">Harga Supplier (Rp)</label>
                        <input id="sp<?= e($sid) ?>" type="number" name="supplier_price[<?= e($sid) ?>]" min="0" step="0.01" value="<?= e($supplierPrice) ?>">
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="buttons">
            <button type="submit" class="button primary">
                <?= $editProduct ? 'Simpan Perubahan' : 'Tambah Produk' ?>
            </button>

            <?php if ($editProduct): ?>
                <a href="index.php" class="button secondary">Batal</a>
            <?php endif; ?>
        </div>
    </form>
    <?php endif; ?>
</section>
<?php else: ?>
<section class="panel">
    <h2>Informasi Produk</h2>
    <p class="hint">Kamu login sebagai Staff. Kamu dapat melihat, mencari, memfilter, dan mengurutkan produk, tetapi perubahan data hanya dapat dilakukan oleh Admin.</p>
</section>
<?php endif; ?>

<section class="panel">
    <h2>Daftar Produk</h2>

    <form method="GET" action="index.php">
        <div class="filter-grid">
            <div>
                <label for="q">Cari Nama / SKU</label>
                <input id="q" type="text" name="q" value="<?= e($search) ?>" placeholder="Cari produk...">
            </div>

            <div>
                <label for="category">Kategori</label>
                <select id="category" name="category">
                    <option value="0">Semua Kategori</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e($category['id']) ?>" <?= (int)$categoryFilter === (int)$category['id'] ? 'selected' : '' ?>>
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="sort">Urut Berdasarkan</label>
                <select id="sort" name="sort">
                    <option value="created" <?= $sort === 'created' ? 'selected' : '' ?>>Terbaru</option>
                    <option value="sku" <?= $sort === 'sku' ? 'selected' : '' ?>>SKU</option>
                    <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Nama</option>
                    <option value="category" <?= $sort === 'category' ? 'selected' : '' ?>>Kategori</option>
                    <option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>Harga</option>
                    <option value="stock" <?= $sort === 'stock' ? 'selected' : '' ?>>Stok</option>
                </select>
            </div>

            <div>
                <label for="dir">Urutan</label>
                <select id="dir" name="dir">
                    <option value="ASC" <?= $direction === 'ASC' ? 'selected' : '' ?>>Naik</option>
                    <option value="DESC" <?= $direction === 'DESC' ? 'selected' : '' ?>>Turun</option>
                </select>
            </div>

            <div>
                <button class="button primary" type="submit">Terapkan</button>
            </div>
        </div>

        <div class="buttons">
            <a class="button secondary" href="index.php">Reset Filter</a>
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
                    <th>Harga</th>
                    <th>Stok</th>
                    <th>Status</th>
                    <?php if ($role === 'admin'): ?><th>Aksi</th><?php endif; ?>
                </tr>
            </thead>

            <tbody>
            <?php if (empty($products)): ?>
                <tr>
                    <td colspan="<?= $role === 'admin' ? 8 : 7 ?>" class="empty">
                        Tidak ada produk yang ditemukan.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td class="sku"><?= e($product['sku']) ?></td>
                        <td class="product-name"><?= e($product['name']) ?></td>
                        <td><?= e($product['category_name']) ?></td>
                        <td><?= e($product['supplier_names'] ?: '-') ?></td>
                        <td>Rp <?= e(number_format((float)$product['price'], 0, ',', '.')) ?></td>
                        <td><?= e($product['stock']) ?></td>
                        <td>
                            <?php if ((int)$product['stock'] <= (int)$product['minimum_stock']): ?>
                                <span class="badge badge-low">Stok Rendah</span>
                            <?php else: ?>
                                <span class="badge badge-ok">Tersedia</span>
                            <?php endif; ?>
                        </td>

                        <?php if ($role === 'admin'): ?>
                        <td>
                            <div class="actions">
                                <a class="button secondary" href="<?= e(productPageUrl(['edit' => $product['id']])) ?>">Edit</a>

                                <form method="POST" action="index.php" onsubmit="return confirm('Yakin ingin menghapus produk ini? Produk yang punya histori transaksi tidak dapat dihapus.');">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= e($product['id']) ?>">
                                    <button class="button danger" type="submit">Hapus</button>
                                </form>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination">
        <div class="pagination-info">
            Total <?= e($totalProducts) ?> produk.
            Halaman <?= e($page) ?> dari <?= e($totalPages) ?>.
        </div>

        <div class="pagination-links">
            <?php if ($page > 1): ?>
                <a class="page-link" href="<?= e(productPageUrl(['page' => $page - 1])) ?>">‹ Sebelumnya</a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
                <a class="page-link" href="<?= e(productPageUrl(['page' => $page + 1])) ?>">Berikutnya ›</a>
            <?php endif; ?>
        </div>
    </div>
</section>

</main>
</body>
</html>
