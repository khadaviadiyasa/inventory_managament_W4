# Inventory Management System

## Tentang Project

Inventory Management System adalah aplikasi berbasis web yang dibuat untuk tugas Web 4, yaitu Relational Database dan Advanced PHP.

Aplikasi ini digunakan untuk mengelola data barang, kategori, supplier, stok, serta transaksi barang masuk dan keluar. Aplikasi dibuat menggunakan PHP dan MySQL dengan bantuan XAMPP.

## Fitur Aplikasi

Beberapa fitur yang tersedia dalam aplikasi ini:

- Login dan logout pengguna.
- Hak akses Admin dan Staff.
- Dashboard untuk melihat ringkasan inventory.
- Mengelola data kategori barang.
- Mengelola data produk.
- Mengelola data supplier.
- Menghubungkan produk dengan supplier.
- Mencatat transaksi barang masuk dan barang keluar.
- Menampilkan histori transaksi stok.
- Mencari produk berdasarkan nama atau SKU.
- Filter kategori, sorting, dan pagination pada daftar produk.
- Menampilkan laporan inventory.
- Mengelola akun pengguna melalui halaman Admin.

## Teknologi yang Digunakan

- PHP
- MySQL
- HTML
- CSS
- PDO untuk koneksi dan query database
- XAMPP sebagai server lokal

## Struktur Folder

```text
web4_inventory_pribadi/
├── config/
│   └── database.php
├── helpers/
│   └── auth.php
├── database/
│   └── schema.sql
└── public/
    ├── login.php
    ├── logout.php
    ├── dashboard.php
    ├── categories/
    │   └── index.php
    ├── products/
    │   └── index.php
    ├── suppliers/
    │   └── index.php
    ├── stock/
    │   └── index.php
    ├── reports/
    │   └── index.php
    └── users/
        └── index.php
```

## Cara Menjalankan Aplikasi

1. Instal dan buka XAMPP.
2. Jalankan Apache dan MySQL.
3. Letakkan folder project di dalam folder `htdocs`.
4. Buka phpMyAdmin melalui `http://localhost/phpmyadmin`.
5. Buat database dengan nama `web4_inventory_pribadi`.
6. Pilih database tersebut, lalu import file `database/schema.sql`.
7. Periksa pengaturan koneksi database pada file `config/database.php`.
8. Buka aplikasi melalui browser.

Jika folder project berada langsung di `htdocs` dengan nama `web4_inventory_pribadi`, alamat login adalah:

```text
http://localhost/web4_inventory_pribadi/public/login.php
```

Sesuaikan alamat tersebut jika folder project diletakkan di lokasi yang berbeda.

## Akun Pengguna

Pada database pengembangan, akun demo yang digunakan adalah:

| Role | Username | Password |
|---|---|---|
| Admin | `admin` | `admin123` |
| Staff | `staff` | `staff123` |

Password akun disimpan dalam bentuk hash di database. Jika menggunakan database baru, data akun harus dibuat atau diimpor terlebih dahulu karena file `schema.sql` hanya berisi struktur tabel.

## Struktur Database

Database aplikasi menggunakan enam tabel utama:

| Nama Tabel | Kegunaan |
|---|---|
| `users` | Menyimpan data akun pengguna |
| `categories` | Menyimpan kategori barang |
| `products` | Menyimpan data produk dan stok |
| `suppliers` | Menyimpan data pemasok |
| `product_suppliers` | Menghubungkan produk dengan supplier |
| `stock_movements` | Menyimpan histori barang masuk dan keluar |

Relasi kategori dengan produk menggunakan foreign key. Relasi produk dengan supplier menggunakan tabel penghubung `product_suppliers`.

## Aturan Bisnis

Beberapa aturan yang diterapkan pada aplikasi:

1. SKU produk harus unik.
2. Harga dan jumlah barang tidak boleh negatif.
3. Barang keluar tidak boleh melebihi stok yang tersedia.
4. Setiap transaksi stok harus dicatat dalam histori.
5. Produk atau supplier yang masih memiliki relasi tidak boleh dihapus sembarangan.
6. Halaman internal memerlukan login.
7. Hak akses pengguna dibedakan berdasarkan role.
8. Data dari pengguna divalidasi sebelum diproses.

Transaksi barang masuk dan keluar menggunakan transaksi database agar perubahan stok dan pencatatan histori dapat disimpan bersama atau dibatalkan jika terjadi kegagalan.

## Keamanan

Aplikasi menggunakan prepared statement melalui PDO, validasi input, HTML escaping, password hashing, dan CSRF token pada formulir yang menerapkannya.

Pemeriksaan hak akses dilakukan pada sisi server untuk membatasi fitur sesuai role pengguna.

## Catatan

File `schema.sql` digunakan untuk membuat struktur database. Untuk memindahkan aplikasi beserta akun, data kategori, produk, supplier, dan histori transaksi yang sudah dibuat, ekspor database dari phpMyAdmin dengan menyertakan **struktur dan data**.

Project ini dibuat sebagai latihan penerapan database relasional dan PHP dalam pengelolaan inventory.
