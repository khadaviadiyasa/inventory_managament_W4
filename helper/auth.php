<?php

// Memulai session jika belum aktif.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Memeriksa apakah pengguna sudah login.
function isLoggedIn(): bool
{
    return isset($_SESSION['user'])
        && isset($_SESSION['user']['id'])
        && isset($_SESSION['user']['role']);
}

// Mengambil informasi pengguna yang login.
function currentUser(): ?array
{
    return isLoggedIn() ? $_SESSION['user'] : null;
}

// Memastikan pengguna sudah login.
function requireLogin(): void
{
    if (isLoggedIn()) {
        return;
    }

    header('Location: login.php');
    exit;
}

// Memeriksa hak akses berdasarkan role.
function requireRole(array $allowedRoles): void
{
    requireLogin();

    $user = currentUser();

    if (!in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        exit('403 Forbidden: Anda tidak memiliki hak akses.');
    }
}