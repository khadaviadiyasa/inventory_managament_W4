
<?php


if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Pengguna harus login terlebih dahulu.
if (
    !isset($_SESSION['user']['id']) ||
    !isset($_SESSION['user']['role'])
) {
    header('Location: ../login.php');
    exit;
}

// Hanya admin yang boleh mengelola kategori.
if ($_SESSION['user']['role'] !== 'admin') {
    http_response_code(403);
    exit(
        '403 Forbidden: Hanya admin yang boleh mengelola kategori.'
    );
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

// Membuat token keamanan formulir
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'success';

// Memproses formulir
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
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim(
                (string) ($_POST['description'] ?? '')
            );

            if ($name === '') {
                throw new RuntimeException(
                    'Nama kategori wajib diisi.'
                );
            }

            $length = function_exists('mb_strlen')
                ? mb_strlen($name, 'UTF-8')
                : strlen($name);

            if ($length > 100) {
                throw new RuntimeException(
                    'Nama kategori maksimal 100 karakter.'
                );
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE categories
                    SET name = :name,
                        description = :description
                    WHERE id = :id
                ");

                $stmt->execute([
                    'name' => $name,
                    'description' => $description,
                    'id' => $id
                ]);

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => 'Kategori berhasil diperbarui.'
                ];
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO categories (name, description)
                    VALUES (:name, :description)
                ");

                $stmt->execute([
                    'name' => $name,
                    'description' => $description
                ]);

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => 'Kategori berhasil ditambahkan.'
                ];
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException(
                    'ID kategori tidak valid.'
                );
            }

            $stmt = $pdo->prepare("
                DELETE FROM categories
                WHERE id = :id
            ");

            $stmt->execute(['id' => $id]);

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Kategori berhasil dihapus.'
            ];
        } else {
            throw new RuntimeException(
                'Aksi yang diminta tidak dikenal.'
            );
        }
    } catch (RuntimeException $ex) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => $ex->getMessage()
        ];
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Nama kategori sudah digunakan, atau kategori masih digunakan oleh produk sehingga tidak dapat dihapus.'
            ];
        } else {
            error_log($ex->getMessage());

            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Operasi database gagal. Periksa konfigurasi dan data.'
            ];
        }
    }

    header('Location: index.php');
    exit;
}

// Mengambil pesan hasil operasi
if (isset($_SESSION['flash'])) {
    $message = $_SESSION['flash']['message'];
    $messageType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

// Mencari kategori untuk diedit
$editCategory = null;

if (isset($_GET['edit'])) {
    $editId = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($editId !== false && $editId !== null) {
        $stmt = $pdo->prepare("
            SELECT id, name, description
            FROM categories
            WHERE id = :id
        ");

        $stmt->execute(['id' => $editId]);
        $editCategory = $stmt->fetch() ?: null;
    }

    if (!$editCategory) {
        $message = 'Kategori yang dipilih tidak ditemukan.';
        $messageType = 'error';
    }
}

// Pencarian kategori
$search = trim((string) ($_GET['q'] ?? ''));

if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT id, name, description, created_at
        FROM categories
        WHERE name LIKE :search_name
           OR description LIKE :search_description
        ORDER BY id DESC
    ");

    $keyword = '%' . $search . '%';

    $stmt->execute([
        'search_name' => $keyword,
        'search_description' => $keyword
    ]);

    $categories = $stmt->fetchAll();
} else {
    $stmt = $pdo->query("
        SELECT id, name, description, created_at
        FROM categories
        ORDER BY id DESC
    ");

    $categories = $stmt->fetchAll();
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

    <title>Kategori | Inventory Management</title>

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
            padding: 26px 18px;
            background: linear-gradient(
                180deg,
                #e0f7ff,
                #c9edf7
            );
            border-right: 1px solid var(--ocean-border);
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
            padding: 32px;
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

        .back-link {
            display: inline-block;
            padding: 10px 15px;
            border: 1px solid var(--ocean-border);
            border-radius: 9px;
            background: white;
            color: #075985;
            font-size: 13px;
            text-decoration: none;
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
        textarea {
            width: 100%;
            padding: 12px;
            margin-bottom: 17px;
            border: 1px solid #cbdde6;
            border-radius: 8px;
            outline: none;
            background: #fbfeff;
            color: #164e63;
            font: inherit;
            font-size: 13px;
        }

        input:focus,
        textarea:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px #e0f7ff;
        }

        textarea {
            min-height: 90px;
            resize: vertical;
        }

        .button {
            display: inline-block;
            padding: 11px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            text-decoration: none;
        }

        .button-primary {
            background: #0284c7;
            color: white;
        }

        .button-primary:hover {
            background: #0369a1;
        }

        .button-secondary {
            background: #e5f3f9;
            color: #164e63;
        }

        .button-danger {
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
            margin: 0;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            text-align: left;
        }

        th {
            padding: 14px 12px;
            background: #effaff;
            color: #35748b;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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

        .category-name {
            color: #164e63;
            font-weight: bold;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .actions .button {
            padding: 8px 10px;
            font-size: 11px;
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

            .search-form {
                align-items: stretch;
                flex-direction: column;
            }

            .search-form .button {
                text-align: center;
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

    <a class="nav-item active" href="index.php">
        ▤ &nbsp; Kategori
    </a>

    <a class="nav-item" href="../products/index.php">
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
            <h1>Manajemen Kategori</h1>

            <p class="subtitle">
                Tambahkan dan kelola kategori produk inventory.
            </p>
        </div>

        <a class="back-link" href="../dashboard.php">
            ← Kembali ke Dashboard
        </a>
    </header>

    <?php if ($message !== ''): ?>
        <div class="message <?= e($messageType) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2>
            <?= $editCategory
                ? 'Edit Kategori'
                : 'Tambah Kategori Baru' ?>
        </h2>

        <form action="index.php" method="POST">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>"
            >

            <input type="hidden" name="action" value="save">

            <input
                type="hidden"
                name="id"
                value="<?= e($editCategory['id'] ?? 0) ?>"
            >

            <label for="name">Nama Kategori</label>

            <input
                type="text"
                id="name"
                name="name"
                maxlength="100"
                placeholder="Contoh: Elektronik"
                value="<?= e($editCategory['name'] ?? '') ?>"
                required
            >

            <label for="description">Deskripsi</label>

            <textarea
                id="description"
                name="description"
                placeholder="Masukkan deskripsi kategori..."
            ><?= e($editCategory['description'] ?? '') ?></textarea>

            <button
                type="submit"
                class="button button-primary"
            >
                <?= $editCategory
                    ? 'Simpan Perubahan'
                    : 'Tambah Kategori' ?>
            </button>

            <?php if ($editCategory): ?>
                <a
                    href="index.php"
                    class="button button-secondary"
                >
                    Batal
                </a>
            <?php endif; ?>
        </form>
    </section>

    <section class="panel">
        <h2>Daftar Kategori</h2>

        <form class="search-form" method="GET" action="index.php">
            <input
                type="text"
                name="q"
                placeholder="Cari nama atau deskripsi kategori..."
                value="<?= e($search) ?>"
            >

            <button
                type="submit"
                class="button button-primary"
            >
                Cari
            </button>

            <?php if ($search !== ''): ?>
                <a
                    href="index.php"
                    class="button button-secondary"
                >
                    Reset
                </a>
            <?php endif; ?>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Kategori</th>
                        <th>Deskripsi</th>
                        <th>Tanggal Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (empty($categories)): ?>

                    <tr>
                        <td colspan="5" class="empty">
                            Tidak ada kategori yang ditemukan.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($categories as $index => $category): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>

                            <td class="category-name">
                                <?= e($category['name']) ?>
                            </td>

                            <td>
                                <?= e(
                                    $category['description'] ?: '-'
                                ) ?>
                            </td>

                            <td>
                                <?= e(date(
                                    'd-m-Y H:i',
                                    strtotime($category['created_at'])
                                )) ?>
                            </td>

                            <td>
                                <div class="actions">
                                    <a
                                        href="index.php?edit=<?= e($category['id']) ?>"
                                        class="button button-secondary"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="index.php"
                                        onsubmit="return confirm('Yakin ingin menghapus kategori ini?')"
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
                                            value="<?= e($category['id']) ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="button button-danger"
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
