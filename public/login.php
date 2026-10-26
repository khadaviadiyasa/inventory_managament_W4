<?php



require_once __DIR__ . '/../config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool
    {
        return isset($_SESSION['user'])
            && isset($_SESSION['user']['id'])
            && isset($_SESSION['user']['role']);
    }
}

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

// Jika sudah login, langsung buka dashboard.
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

// Membuat token CSRF untuk formulir login.
if (empty($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['login_csrf_token'], $token)
    ) {
        $error = 'Permintaan tidak valid. Silakan muat ulang halaman.';
    } else {
        $usernameInput = $_POST['username'] ?? '';
        $passwordInput = $_POST['password'] ?? '';

        $username = is_string($usernameInput)
            ? trim($usernameInput)
            : '';

        $password = is_string($passwordInput)
            ? $passwordInput
            : '';

        if ($username === '' || $password === '') {
            $error = 'Username dan password wajib diisi.';
        } else {
            try {
                // Mencari akun dengan prepared statement.
                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        username,
                        password_hash,
                        full_name,
                        role
                    FROM users
                    WHERE username = :username
                    LIMIT 1
                ");

                $stmt->execute([
                    'username' => $username
                ]);

                $user = $stmt->fetch();

                // Memeriksa password terhadap hash di database.
                if (
                    $user &&
                    password_verify(
                        $password,
                        $user['password_hash']
                    ) &&
                    in_array(
                        $user['role'],
                        ['admin', 'staff'],
                        true
                    )
                ) {
                    // Mencegah session fixation.
                    session_regenerate_id(true);

                    // Menyimpan data akun ke session.
                    $_SESSION['user'] = [
                        'id' => (int) $user['id'],
                        'username' => $user['username'],
                        'full_name' => $user['full_name'],
                        'role' => $user['role']
                    ];

                    // Memperbarui token setelah login.
                    $_SESSION['login_csrf_token'] =
                        bin2hex(random_bytes(32));

                    $_SESSION['csrf_token'] =
                        bin2hex(random_bytes(32));

                    header('Location: dashboard.php');
                    exit;
                }

                $error = 'Username atau password tidak sesuai.';

            } catch (PDOException $ex) {
                error_log($ex->getMessage());

                $error = 'Login belum dapat diproses. Periksa koneksi database.';
            }
        }
    }

    // Mengganti token setelah formulir dikirim.
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
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

    <title>Login | Inventory Management System</title>

    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --ocean-dark: #164e63;
            --ocean-blue: #0284c7;
            --ocean-light: #dff4fc;
            --ocean-pale: #f0faff;
            --ocean-border: #cceaf5;
            --ocean-muted: #64748b;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--ocean-dark);
            background:
                radial-gradient(
                    circle at 10% 15%,
                    #c7f0ff 0,
                    transparent 35%
                ),
                radial-gradient(
                    circle at 90% 85%,
                    #bdf7eb 0,
                    transparent 35%
                ),
                var(--ocean-pale);
        }

        .login-wrapper {
            width: 100%;
            max-width: 420px;
        }

        .brand {
            margin-bottom: 25px;
            text-align: center;
        }

        .brand-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 66px;
            height: 66px;
            margin: 0 auto 15px;
            border-radius: 20px;
            background: linear-gradient(
                135deg,
                #7dd3fc,
                #99f6e4
            );
            color: #083344;
            font-size: 29px;
            font-weight: bold;
            box-shadow: 0 8px 22px rgba(14, 116, 144, 0.12);
        }

        .brand h1 {
            margin: 0;
            color: #075985;
            font-size: 23px;
            letter-spacing: 0.6px;
        }

        .brand p {
            margin: 7px 0 0;
            color: var(--ocean-muted);
            font-size: 13px;
        }

        .login-card {
            padding: 32px;
            border: 1px solid var(--ocean-border);
            border-radius: 19px;
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 18px 50px rgba(14, 116, 144, 0.09);
        }

        .login-heading {
            margin-bottom: 25px;
        }

        .login-heading h2 {
            margin: 0 0 8px;
            font-size: 23px;
            color: var(--ocean-dark);
        }

        .login-heading p {
            margin: 0;
            color: var(--ocean-muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 19px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: bold;
            color: #355d70;
        }

        input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #cbdde6;
            border-radius: 9px;
            outline: none;
            background: #fbfeff;
            color: var(--ocean-dark);
            font-family: inherit;
            font-size: 13px;
            transition: 0.2s;
        }

        input:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px #e0f7ff;
        }

        .login-button {
            width: 100%;
            margin-top: 5px;
            padding: 13px;
            border: none;
            border-radius: 9px;
            background: linear-gradient(
                110deg,
                #0284c7,
                #0891b2
            );
            color: white;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.2s;
        }

        .login-button:hover {
            background: linear-gradient(
                110deg,
                #0369a1,
                #0e7490
            );
            box-shadow: 0 6px 16px rgba(2, 132, 199, 0.2);
        }

        .error-message {
            margin-bottom: 20px;
            padding: 12px;
            border: 1px solid #fecaca;
            border-radius: 9px;
            background: #fef2f2;
            color: #991b1b;
            font-size: 12px;
            line-height: 1.6;
        }

        .login-note {
            margin-top: 22px;
            padding: 12px;
            border-radius: 9px;
            background: #effaff;
            color: #35748b;
            font-size: 11px;
            line-height: 1.7;
            text-align: center;
        }

        .footer {
            margin-top: 22px;
            color: #8196a3;
            font-size: 11px;
            text-align: center;
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 24px;
            }

            .brand h1 {
                font-size: 21px;
            }
        }
    </style>
</head>

<body>

<div class="login-wrapper">

    <header class="brand">
        <div class="brand-icon">I</div>

        <h1>INVENTORY SYSTEM</h1>

        <p>Relational Database + Advanced PHP</p>
    </header>

    <main class="login-card">

        <div class="login-heading">
            <h2>Selamat Datang!</h2>

            <p>
                Silakan login untuk mengakses
                Inventory Management System.
            </p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="error-message" role="alert">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['login_csrf_token']) ?>"
            >

            <div class="form-group">
                <label for="username">Username</label>

                <input
                    type="text"
                    id="username"
                    name="username"
                    maxlength="50"
                    placeholder="Masukkan username"
                    value="<?= e($username) ?>"
                    autocomplete="username"
                    required
                    autofocus
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Masukkan password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button type="submit" class="login-button">
                Login ke Dashboard
            </button>

        </form>

        <div class="login-note">
            Halaman ini hanya untuk pengguna yang memiliki
            akun Inventory Management System.
        </div>

    </main>

    <footer class="footer">
        &copy; <?= e(date('Y')) ?> Inventory Management System
    </footer>

</div>

</body>
</html>