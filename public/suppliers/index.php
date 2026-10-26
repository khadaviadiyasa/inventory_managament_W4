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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'success';

// ========================================
// PROSES TAMBAH, EDIT, DAN HAPUS SUPPLIER
// ========================================

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

            $name = trim((string) ($_POST['name'] ?? ''));
            $contactPerson = trim(
                (string) ($_POST['contact_person'] ?? '')
            );
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));

            if ($id === false || $id === null) {
                throw new RuntimeException(
                    'ID supplier tidak valid.'
                );
            }

            if ($name === '') {
                throw new RuntimeException(
                    'Nama supplier wajib diisi.'
                );
            }

            if (strlen($name) > 150) {
                throw new RuntimeException(
                    'Nama supplier maksimal 150 karakter.'
                );
            }

            if (strlen($contactPerson) > 100) {
                throw new RuntimeException(
                    'Nama kontak maksimal 100 karakter.'
                );
            }

            if (strlen($phone) > 30) {
                throw new RuntimeException(
                    'Nomor telepon maksimal 30 karakter.'
                );
            }

            if (strlen($email) > 100) {
                throw new RuntimeException(
                    'Email maksimal 100 karakter.'
                );
            }

            if (
                $email !== '' &&
                !filter_var($email, FILTER_VALIDATE_EMAIL)
            ) {
                throw new RuntimeException(
                    'Format email tidak valid.'
                );
            }

            $contactPerson = $contactPerson === ''
                ? null
                : $contactPerson;

            $phone = $phone === ''
                ? null
                : $phone;

            $email = $email === ''
                ? null
                : $email;

            $address = $address === ''
                ? null
                : $address;

            if ((int) $id > 0) {
                $check = $pdo->prepare(
                    'SELECT id FROM suppliers WHERE id = :id'
                );

                $check->execute(['id' => (int) $id]);

                if (!$check->fetch()) {
                    throw new RuntimeException(
                        'Supplier tidak ditemukan.'
                    );
                }

                $stmt = $pdo->prepare("
                    UPDATE suppliers
                    SET name = :name,
                        contact_person = :contact_person,
                        phone = :phone,
                        email = :email,
                        address = :address
                    WHERE id = :id
                ");

                $stmt->execute([
                    'name' => $name,
                    'contact_person' => $contactPerson,
                    'phone' => $phone,
                    'email' => $email,
                    'address' => $address,
                    'id' => (int) $id
                ]);

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => 'Supplier berhasil diperbarui.'
                ];
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO suppliers (
                        name,
                        contact_person,
                        phone,
                        email,
                        address
                    ) VALUES (
                        :name,
                        :contact_person,
                        :phone,
                        :email,
                        :address
                    )
                ");

                $stmt->execute([
                    'name' => $name,
                    'contact_person' => $contactPerson,
                    'phone' => $phone,
                    'email' => $email,
                    'address' => $address
                ]);

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => 'Supplier berhasil ditambahkan.'
                ];
            }
        } elseif ($action === 'delete') {
            $id = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($id === false || $id === null) {
                throw new RuntimeException(
                    'ID supplier tidak valid.'
                );
            }

            $stmt = $pdo->prepare("
                DELETE FROM suppliers
                WHERE id = :id
            ");

            $stmt->execute(['id' => $id]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException(
                    'Supplier tidak ditemukan.'
                );
            }

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Supplier berhasil dihapus.'
            ];
        } else {
            throw new RuntimeException(
                'Aksi yang diminta tidak dikenal.'
            );
        }
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Supplier tidak dapat dihapus karena masih terhubung dengan produk.'
            ];
        } else {
            error_log($ex->getMessage());

            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Operasi database gagal.'
            ];
        }
    } catch (RuntimeException $ex) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => $ex->getMessage()
        ];
    }

    header('Location: index.php');
    exit;
}

// ========================================
// PESAN HASIL OPERASI
// ========================================

if (isset($_SESSION['flash'])) {
    $message = $_SESSION['flash']['message'];
    $messageType = $_SESSION['flash']['type'];

    unset($_SESSION['flash']);
}

// ========================================
// DATA UNTUK EDIT
// ========================================

$editSupplier = null;

if (isset($_GET['edit'])) {
    $editId = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($editId !== false && $editId !== null) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM suppliers
            WHERE id = :id
        ");

        $stmt->execute(['id' => $editId]);
        $editSupplier = $stmt->fetch() ?: null;

        if (!$editSupplier) {
            $message = 'Supplier tidak ditemukan.';
            $messageType = 'error';
        }
    } else {
        $message = 'ID supplier tidak valid.';
        $messageType = 'error';
    }
}

// ========================================
// PENCARIAN SUPPLIER
// ========================================

$search = isset($_GET['q']) && is_string($_GET['q'])
    ? trim($_GET['q'])
    : '';

if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT
            s.*,
            (
                SELECT COUNT(*)
                FROM product_suppliers ps
                WHERE ps.supplier_id = s.id
            ) AS total_products
        FROM suppliers s
        WHERE s.name LIKE :name
           OR s.contact_person LIKE :contact
           OR s.email LIKE :email
           OR s.phone LIKE :phone
        ORDER BY s.id DESC
    ");

    $keyword = '%' . $search . '%';

    $stmt->execute([
        'name' => $keyword,
        'contact' => $keyword,
        'email' => $keyword,
        'phone' => $keyword
    ]);

    $suppliers = $stmt->fetchAll();
} else {
    $stmt = $pdo->query("
        SELECT
            s.*,
            (
                SELECT COUNT(*)
                FROM product_suppliers ps
                WHERE ps.supplier_id = s.id
            ) AS total_products
        FROM suppliers s
        ORDER BY s.id DESC
    ");

    $suppliers = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Supplier | Inventory Management</title>

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

        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            width: 230px;
            padding: 25px 18px;
            background: linear-gradient(
                180deg,
                #e0f7ff,
                #c9edf7
            );
            border-right: 1px solid var(--border);
            overflow-y: auto;
        }

        .brand {
            margin-bottom: 38px;
            font-size: 19px;
            font-weight: bold;
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
            flex-wrap: wrap;
            gap: 15px;
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
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 5px 16px rgba(14, 116, 144, 0.04);
        }

        .panel h2 {
            margin: 0 0 20px;
            font-size: 17px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 17px;
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
        textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbdde6;
            border-radius: 8px;
            outline: none;
            background: #fbfeff;
            color: var(--dark);
            font: inherit;
            font-size: 13px;
        }

        input:focus,
        textarea:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px #e0f7ff;
        }

        textarea {
            min-height: 85px;
            resize: vertical;
        }

        .buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            margin-top: 20px;
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
            background: var(--primary);
            color: white;
        }

        .primary:hover {
            background: #0369a1;
        }

        .secondary {
            background: #e5f3f9;
            color: var(--dark);
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

        .search-form {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-form input {
            flex: 1;
            min-width: 0;
        }

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

        .supplier-name {
            color: var(--dark);
            font-weight: bold;
        }

        .supplier-count {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #e0f7ff;
            color: #075985;
            font-size: 11px;
            font-weight: bold;
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
            padding: 28px;
            color: var(--muted);
            text-align: center;
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

            .form-grid {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }

            .search-form {
                align-items: stretch;
                flex-direction: column;
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

    <a class="nav-item active" href="index.php">
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
            <h1>Manajemen Supplier</h1>

            <p class="subtitle">
                Kelola informasi pemasok barang inventory.
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

    <!-- FORM SUPPLIER -->

    <section class="panel">
        <h2>
            <?= $editSupplier
                ? 'Edit Supplier'
                : 'Tambah Supplier Baru' ?>
        </h2>

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
                value="<?= e($editSupplier['id'] ?? 0) ?>"
            >

            <div class="form-grid">

                <div class="field">
                    <label for="name">Nama Supplier *</label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        maxlength="150"
                        placeholder="Contoh: PT Sumber Elektronik"
                        value="<?= e($editSupplier['name'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="contact_person">Nama Kontak</label>

                    <input
                        type="text"
                        id="contact_person"
                        name="contact_person"
                        maxlength="100"
                        placeholder="Nama orang yang dapat dihubungi"
                        value="<?= e(
                            $editSupplier['contact_person'] ?? ''
                        ) ?>"
                    >
                </div>

                <div class="field">
                    <label for="phone">Nomor Telepon</label>

                    <input
                        type="text"
                        id="phone"
                        name="phone"
                        maxlength="30"
                        placeholder="Contoh: 081234567890"
                        value="<?= e($editSupplier['phone'] ?? '') ?>"
                    >
                </div>

                <div class="field">
                    <label for="email">Email Supplier</label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="100"
                        placeholder="supplier@example.com"
                        value="<?= e($editSupplier['email'] ?? '') ?>"
                    >
                </div>

                <div class="field full">
                    <label for="address">Alamat Supplier</label>

                    <textarea
                        id="address"
                        name="address"
                        placeholder="Masukkan alamat supplier..."
                    ><?= e($editSupplier['address'] ?? '') ?></textarea>
                </div>

            </div>

            <div class="buttons">

                <button type="submit" class="button primary">
                    <?= $editSupplier
                        ? 'Simpan Perubahan'
                        : 'Tambah Supplier' ?>
                </button>

                <?php if ($editSupplier): ?>
                    <a href="index.php" class="button secondary">
                        Batal Edit
                    </a>
                <?php endif; ?>

            </div>

        </form>
    </section>

    <!-- DAFTAR SUPPLIER -->

    <section class="panel">
        <h2>Daftar Supplier</h2>

        <form class="search-form" method="GET" action="index.php">
            <input
                type="text"
                name="q"
                placeholder="Cari nama, kontak, email, atau telepon..."
                value="<?= e($search) ?>"
            >

            <button type="submit" class="button primary">
                Cari
            </button>

            <?php if ($search !== ''): ?>
                <a href="index.php" class="button secondary">
                    Reset
                </a>
            <?php endif; ?>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nama Supplier</th>
                        <th>Kontak</th>
                        <th>Telepon</th>
                        <th>Email</th>
                        <th>Jumlah Produk</th>
                        <th>Alamat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($suppliers)): ?>

                    <tr>
                        <td colspan="7" class="empty">
                            Belum ada supplier yang ditemukan.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($suppliers as $supplier): ?>

                        <tr>
                            <td class="supplier-name">
                                <?= e($supplier['name']) ?>
                            </td>

                            <td>
                                <?= e(
                                    $supplier['contact_person'] ?: '-'
                                ) ?>
                            </td>

                            <td>
                                <?= e($supplier['phone'] ?: '-') ?>
                            </td>

                            <td>
                                <?= e($supplier['email'] ?: '-') ?>
                            </td>

                            <td>
                                <span class="supplier-count">
                                    <?= e($supplier['total_products']) ?>
                                    produk
                                </span>
                            </td>

                            <td>
                                <?= e($supplier['address'] ?: '-') ?>
                            </td>

                            <td>
                                <div class="row-actions">

                                    <a
                                        class="button secondary"
                                        href="index.php?edit=<?= e($supplier['id']) ?>"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="index.php"
                                        onsubmit="return confirm('Yakin ingin menghapus supplier ini? Supplier yang masih digunakan produk tidak dapat dihapus.');"
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
                                            value="<?= e($supplier['id']) ?>"
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
    </section>

</main>

</body>
</html>