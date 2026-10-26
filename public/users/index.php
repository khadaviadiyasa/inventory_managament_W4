
<?php

// =============================================
// 1. SESSION DAN HAK AKSES ADMIN
// =============================================

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    !isset($_SESSION['user']['id']) ||
    !isset($_SESSION['user']['role'])
) {
    header('Location: ../login.php');
    exit;
}

if ($_SESSION['user']['role'] !== 'admin') {
    http_response_code(403);
    exit('403 Forbidden: Hanya Admin yang boleh mengelola pengguna.');
}

$currentUserId = (int) $_SESSION['user']['id'];

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

// =============================================
// 2. PROSES TAMBAH, EDIT, DAN HAPUS USER
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

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = filter_var(
                $_POST['id'] ?? 0,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]]
            );

            $username = trim((string) ($_POST['username'] ?? ''));
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? '';

            if ($id === false || $id === null) {
                throw new RuntimeException('ID pengguna tidak valid.');
            }

            // Validasi username.
            if (
                !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)
            ) {
                throw new RuntimeException(
                    'Username harus 3–50 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.'
                );
            }

            // Validasi nama lengkap.
            $nameLength = function_exists('mb_strlen')
                ? mb_strlen($fullName, 'UTF-8')
                : strlen($fullName);

            if ($fullName === '' || $nameLength > 100) {
                throw new RuntimeException(
                    'Nama lengkap wajib diisi dan maksimal 100 karakter.'
                );
            }

            // Validasi role.
            if (!in_array($role, ['admin', 'staff'], true)) {
                throw new RuntimeException(
                    'Role pengguna tidak valid.'
                );
            }

            // Validasi password jika sedang membuat akun baru
            // atau mengganti password akun lama.
            if (!is_string($password)) {
                throw new RuntimeException(
                    'Format password tidak valid.'
                );
            }

            if (
                ((int) $id === 0 || $password !== '') &&
                strlen($password) < 8
            ) {
                throw new RuntimeException(
                    'Password wajib minimal 8 karakter.'
                );
            }

            // Admin tidak boleh mengubah role akunnya sendiri
            // menjadi staff agar hak akses administrasi tidak hilang.
            if (
                (int) $id === $currentUserId &&
                $role !== 'admin'
            ) {
                throw new RuntimeException(
                    'Role akun Admin yang sedang digunakan tidak dapat diubah menjadi Staff.'
                );
            }

            $pdo->beginTransaction();

            if ((int) $id > 0) {
                // Pastikan pengguna yang diedit tersedia.
                $check = $pdo->prepare("
                    SELECT id, role
                    FROM users
                    WHERE id = :id
                    FOR UPDATE
                ");

                $check->execute(['id' => (int) $id]);
                $existingUser = $check->fetch();

                if (!$existingUser) {
                    throw new RuntimeException(
                        'Pengguna yang ingin diedit tidak ditemukan.'
                    );
                }

                // Jangan sampai tidak ada Admin tersisa.
                if (
                    $existingUser['role'] === 'admin' &&
                    $role !== 'admin'
                ) {
                    $countAdmins = $pdo->query("
                        SELECT COUNT(*)
                        FROM users
                        WHERE role = 'admin'
                    ")->fetchColumn();

                    if ((int) $countAdmins <= 1) {
                        throw new RuntimeException(
                            'Tidak dapat mengubah role karena harus ada minimal satu Admin.'
                        );
                    }
                }

                if ($password !== '') {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET username = :username,
                            full_name = :full_name,
                            password_hash = :password_hash,
                            role = :role
                        WHERE id = :id
                    ");

                    $stmt->execute([
                        'username' => $username,
                        'full_name' => $fullName,
                        'password_hash' => password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        ),
                        'role' => $role,
                        'id' => (int) $id
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET username = :username,
                            full_name = :full_name,
                            role = :role
                        WHERE id = :id
                    ");

                    $stmt->execute([
                        'username' => $username,
                        'full_name' => $fullName,
                        'role' => $role,
                        'id' => (int) $id
                    ]);
                }

                // Perbarui informasi session jika akun sendiri diedit.
                if ((int) $id === $currentUserId) {
                    $_SESSION['user']['username'] = $username;
                    $_SESSION['user']['full_name'] = $fullName;
                    $_SESSION['user']['role'] = $role;
                }

                $successMessage = 'Data pengguna berhasil diperbarui.';

            } else {
                // Akun baru wajib memiliki password.
                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        username,
                        password_hash,
                        full_name,
                        role
                    ) VALUES (
                        :username,
                        :password_hash,
                        :full_name,
                        :role
                    )
                ");

                $stmt->execute([
                    'username' => $username,
                    'password_hash' => password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    ),
                    'full_name' => $fullName,
                    'role' => $role
                ]);

                $successMessage = 'Pengguna baru berhasil ditambahkan.';
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
                    'ID pengguna tidak valid.'
                );
            }

            if ((int) $id === $currentUserId) {
                throw new RuntimeException(
                    'Kamu tidak dapat menghapus akun yang sedang digunakan.'
                );
            }

            $pdo->beginTransaction();

            $check = $pdo->prepare("
                SELECT id, role
                FROM users
                WHERE id = :id
                FOR UPDATE
            ");

            $check->execute(['id' => $id]);
            $targetUser = $check->fetch();

            if (!$targetUser) {
                throw new RuntimeException(
                    'Pengguna tidak ditemukan.'
                );
            }

            // Pastikan minimal satu Admin tetap ada.
            if ($targetUser['role'] === 'admin') {
                $countAdmins = $pdo->query("
                    SELECT COUNT(*)
                    FROM users
                    WHERE role = 'admin'
                ")->fetchColumn();

                if ((int) $countAdmins <= 1) {
                    throw new RuntimeException(
                        'Admin terakhir tidak dapat dihapus.'
                    );
                }
            }

            $delete = $pdo->prepare("
                DELETE FROM users
                WHERE id = :id
            ");

            $delete->execute(['id' => $id]);

            $pdo->commit();

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Pengguna berhasil dihapus.'
            ];

        } else {
            throw new RuntimeException(
                'Aksi yang diminta tidak dikenal.'
            );
        }

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (
            $ex instanceof PDOException &&
            $ex->getCode() === '23000'
        ) {
            $errorMessage = 'Username sudah digunakan atau pengguna masih terhubung dengan histori transaksi.';
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

// =============================================
// 3. PESAN HASIL OPERASI
// =============================================

if (isset($_SESSION['flash'])) {
    $message = $_SESSION['flash']['message'];
    $messageType = $_SESSION['flash']['type'];

    unset($_SESSION['flash']);
}

// =============================================
// 4. DATA PENGGUNA UNTUK EDIT
// =============================================

$editUser = null;

if (isset($_GET['edit'])) {
    $editId = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($editId !== false && $editId !== null) {
        $stmt = $pdo->prepare("
            SELECT id, username, full_name, role
            FROM users
            WHERE id = :id
        ");

        $stmt->execute(['id' => $editId]);
        $editUser = $stmt->fetch() ?: null;

        if (!$editUser) {
            $message = 'Pengguna tidak ditemukan.';
            $messageType = 'error';
        }
    }
}

// =============================================
// 5. PENCARIAN PENGGUNA
// =============================================

$search = isset($_GET['q']) && is_string($_GET['q'])
    ? trim($_GET['q'])
    : '';

if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT id, username, full_name, role, created_at
        FROM users
        WHERE username LIKE :username
           OR full_name LIKE :full_name
           OR role LIKE :role
        ORDER BY id DESC
    ");

    $keyword = '%' . $search . '%';

    $stmt->execute([
        'username' => $keyword,
        'full_name' => $keyword,
        'role' => $keyword
    ]);

    $users = $stmt->fetchAll();

} else {
    $users = $pdo->query("
        SELECT id, username, full_name, role, created_at
        FROM users
        ORDER BY id DESC
    ")->fetchAll();
}

// Ringkasan jumlah akun.
$totalUsers = count($users);
$totalAdmins = 0;
$totalStaff = 0;

foreach ($users as $listedUser) {
    if ($listedUser['role'] === 'admin') {
        $totalAdmins++;
    } else {
        $totalStaff++;
    }
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

    <title>Pengguna | Inventory Management</title>

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

        /* Konten */

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
            line-height: 1.6;
        }

        .button {
            display: inline-block;
            padding: 10px 13px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
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

        /* Statistik */

        .cards {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 24px;
        }

        .card {
            padding: 19px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 13px;
            box-shadow: 0 5px 16px rgba(14, 116, 144, 0.04);
        }

        .card-label {
            margin-bottom: 10px;
            color: var(--muted);
            font-size: 12px;
        }

        .card-value {
            color: var(--dark);
            font-size: 26px;
            font-weight: bold;
        }

        /* Panel */

        .panel {
            padding: 23px;
            margin-bottom: 24px;
            background: white;
            border: 1px solid var(--border);
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

        input,
        select {
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
        select:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px #e0f7ff;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 17px;
        }

        .field {
            min-width: 0;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .hint {
            margin: 7px 0 0;
            color: #8297a5;
            font-size: 11px;
            line-height: 1.6;
        }

        .buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            margin-top: 20px;
        }

        .message {
            padding: 12px 15px;
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

        /* Tabel */

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
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #f7fcff;
        }

        .username {
            color: var(--dark);
            font-weight: bold;
        }

        .role-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: bold;
        }

        .role-admin {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .role-staff {
            background: #dcfce7;
            color: #166534;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .actions .button {
            padding: 7px 9px;
            font-size: 11px;
        }

        .actions form {
            display: inline;
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

    <a class="nav-item" href="../suppliers/index.php">
        ♧ &nbsp; Supplier
    </a>

    <a class="nav-item" href="../stock/index.php">
        ↕ &nbsp; Transaksi Stok
    </a>

    <a class="nav-item" href="../reports/index.php">
        ▥ &nbsp; Laporan
    </a>

    <a class="nav-item active" href="index.php">
        ♙ &nbsp; Pengguna
    </a>

</aside>

<main>

    <header class="topbar">

        <div>
            <h1>Manajemen Pengguna</h1>

            <p class="subtitle">
                Kelola akun Admin dan Staff Inventory Management System.
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

    <!-- Ringkasan pengguna -->

    <section class="cards">

        <div class="card">
            <div class="card-label">Total Pengguna</div>
            <div class="card-value">
                <?= e($totalUsers) ?>
            </div>
        </div>

        <div class="card">
            <div class="card-label">Jumlah Admin</div>
            <div class="card-value">
                <?= e($totalAdmins) ?>
            </div>
        </div>

        <div class="card">
            <div class="card-label">Jumlah Staff</div>
            <div class="card-value">
                <?= e($totalStaff) ?>
            </div>
        </div>

    </section>

    <!-- Form tambah/edit -->

    <section class="panel">

        <h2>
            <?= $editUser
                ? 'Edit Pengguna'
                : 'Tambah Pengguna Baru' ?>
        </h2>

        <p class="subtitle" style="margin-bottom: 20px;">
            Password akan disimpan menggunakan password hashing.
            Saat mengedit, password boleh dikosongkan jika tidak ingin menggantinya.
        </p>

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
                value="<?= e($editUser['id'] ?? 0) ?>"
            >

            <div class="form-grid">

                <div class="field">

                    <label for="username">Username</label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        minlength="3"
                        maxlength="50"
                        pattern="[A-Za-z0-9._-]{3,50}"
                        placeholder="Contoh: staff2"
                        value="<?= e($editUser['username'] ?? '') ?>"
                        required
                    >

                </div>

                <div class="field">

                    <label for="full_name">Nama Lengkap</label>

                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        maxlength="100"
                        placeholder="Masukkan nama lengkap"
                        value="<?= e($editUser['full_name'] ?? '') ?>"
                        required
                    >

                </div>

                <div class="field">

                    <label for="password">
                        Password
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        minlength="8"
                        autocomplete="new-password"
                        <?= $editUser ? '' : 'required' ?>
                        placeholder="<?= $editUser
                            ? 'Kosongkan jika tidak diganti'
                            : 'Minimal 8 karakter' ?>"
                    >

                    <p class="hint">
                        Minimal 8 karakter. Saat edit, kosongkan
                        jika ingin mempertahankan password lama.
                    </p>

                </div>

                <div class="field">

                    <label for="role">Role Pengguna</label>

                    <select id="role" name="role" required>

                        <option
                            value="admin"
                            <?= ($editUser['role'] ?? '') === 'admin'
                                ? 'selected'
                                : '' ?>
                        >
                            Admin
                        </option>

                        <option
                            value="staff"
                            <?= ($editUser['role'] ?? '') === 'staff'
                                ? 'selected'
                                : '' ?>
                        >
                            Staff
                        </option>

                    </select>

                    <p class="hint">
                        Admin mengelola pengguna. Staff mempunyai
                        hak akses terbatas sesuai fungsi aplikasi.
                    </p>

                </div>

            </div>

            <div class="buttons">

                <button type="submit" class="button primary">
                    <?= $editUser
                        ? 'Simpan Perubahan'
                        : 'Tambah Pengguna' ?>
                </button>

                <?php if ($editUser): ?>
                    <a href="index.php" class="button secondary">
                        Batal Edit
                    </a>
                <?php endif; ?>

            </div>

        </form>

    </section>

    <!-- Daftar pengguna -->

    <section class="panel">

        <h2>Daftar Pengguna</h2>

        <form class="search-form" method="GET" action="index.php">

            <input
                type="text"
                name="q"
                placeholder="Cari username, nama, atau role..."
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
                        <th>No.</th>
                        <th>Username</th>
                        <th>Nama Lengkap</th>
                        <th>Role</th>
                        <th>Tanggal Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($users)): ?>

                    <tr>
                        <td colspan="6" class="empty">
                            Tidak ada pengguna yang ditemukan.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($users as $index => $listedUser): ?>

                        <tr>

                            <td>
                                <?= e($index + 1) ?>
                            </td>

                            <td class="username">
                                <?= e($listedUser['username']) ?>
                            </td>

                            <td>
                                <?= e($listedUser['full_name']) ?>
                            </td>

                            <td>

                                <?php if ($listedUser['role'] === 'admin'): ?>

                                    <span class="role-badge role-admin">
                                        Admin
                                    </span>

                                <?php else: ?>

                                    <span class="role-badge role-staff">
                                        Staff
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?= e(date(
                                    'd-m-Y H:i',
                                    strtotime($listedUser['created_at'])
                                )) ?>
                            </td>

                            <td>

                                <div class="actions">

                                    <a
                                        class="button secondary"
                                        href="index.php?edit=<?= e($listedUser['id']) ?>"
                                    >
                                        Edit
                                    </a>

                                    <?php if (
                                        (int) $listedUser['id']
                                        !== $currentUserId
                                    ): ?>

                                        <form
                                            method="POST"
                                            action="index.php"
                                            onsubmit="return confirm('Yakin ingin menghapus akun pengguna ini?');"
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
                                                value="<?= e($listedUser['id']) ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="button danger"
                                            >
                                                Hapus
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span class="hint">
                                            Akun aktif
                                        </span>

                                    <?php endif; ?>

                                </div>

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
