# FinTrack – Personal Expense Tracker

**FinTrack** adalah aplikasi web untuk membantu pengguna mengelola dan memantau keuangan pribadi. Aplikasi ini dikembangkan untuk mempermudah pencatatan pemasukan, pengeluaran, pengelolaan anggaran, serta pemantauan tabungan dalam satu tempat.

FinTrack menggunakan PHP dan SQLite sebagai teknologi utama, dengan antarmuka yang sederhana, responsif, dan mudah digunakan.

## Fitur Utama

### 1. Dashboard

- Menampilkan saldo saat ini.
- Ringkasan pemasukan dan pengeluaran bulan berjalan.
- Menampilkan jumlah transaksi.
- Grafik perbandingan pemasukan dan pengeluaran selama enam bulan terakhir.
- Grafik distribusi pengeluaran berdasarkan kategori.
- Menampilkan lima transaksi terbaru.
- Ringkasan penggunaan anggaran bulanan.

### 2. Manajemen Transaksi

- Mencatat pemasukan dan pengeluaran.
- Mengelola nominal, kategori, tanggal, deskripsi, dan metode pembayaran.
- Melihat daftar transaksi yang telah dicatat.

### 3. Transaksi Berulang

- Membuat transaksi dengan jadwal berulang.
- Mendukung frekuensi harian, mingguan, bulanan, dan tahunan.
- Mengelola jadwal transaksi dan konfirmasi pencatatannya.

### 4. Manajemen Anggaran

- Membuat anggaran berdasarkan kategori.
- Memantau jumlah anggaran yang telah digunakan.
- Melihat sisa anggaran.
- Menampilkan indikator penggunaan anggaran.

### 5. Manajemen Tabungan

- Membuat target tabungan.
- Menentukan nominal target dan tanggal pencapaian.
- Mencatat setoran dan penarikan tabungan.
- Memantau progres pencapaian target.

### 6. Manajemen Kategori

- Mengelola kategori pemasukan dan pengeluaran.
- Mengaktifkan atau menonaktifkan kategori.

### 7. Laporan Keuangan

- Melihat ringkasan keuangan berdasarkan periode.
- Menganalisis pemasukan dan pengeluaran.
- Melihat distribusi pengeluaran berdasarkan kategori.
- Mengekspor laporan keuangan.

### 8. Pengaturan dan Pengelolaan Database

- Mengatur saldo awal dan tanggal mulai pencatatan.
- Melakukan backup database.
- Memulihkan database dari file backup.

### 9. Tutorial

- Menyediakan panduan penggunaan fitur-fitur FinTrack.

## Teknologi yang Digunakan

| Teknologi  | Kegunaan                         |
| ---------- | -------------------------------- |
| PHP 8.3    | Backend dan pemrosesan aplikasi  |
| SQLite     | Penyimpanan database             |
| PDO        | Koneksi dan pengelolaan database |
| HTML5      | Struktur halaman                 |
| CSS3       | Tampilan dan desain responsif    |
| JavaScript | Interaksi dan visualisasi data   |
| Chart.js   | Pembuatan grafik keuangan        |

## Persyaratan Sistem

Sebelum menjalankan aplikasi, pastikan perangkat telah memiliki:

- PHP 8.3 atau versi yang kompatibel.
- Ekstensi PHP `pdo_sqlite` aktif.
- Web browser modern.
- Git (opsional, untuk mengunduh repositori).

## Instalasi dan Menjalankan Aplikasi

### 1. Clone Repository

```bash
git clone https://github.com/USERNAME/FinTrack.git
```

Masuk ke direktori proyek:

```bash
cd FinTrack
```

### 2. Periksa Konfigurasi PHP

Pastikan PHP sudah terpasang dan dapat dijalankan:

```bash
php -v
```

Pastikan ekstensi SQLite tersedia:

```bash
php -m
```

Periksa apakah `pdo_sqlite` muncul dalam daftar ekstensi.

### 3. Inisialisasi Database

Jalankan perintah berikut dari direktori utama proyek:

```bash
php scripts/init_database.php
```

Perintah tersebut akan menyiapkan struktur database dan kategori bawaan aplikasi.

### 4. Jalankan Aplikasi

Jalankan PHP Development Server:

```bash
php -S 127.0.0.1:8000
```

Buka browser dan akses:

```text
http://127.0.0.1:8000
```

## Struktur Direktori

```text
FinTrack/
│
├── assets/
│   ├── css/
│   │   └── style.css
│   └── js/
│       └── app.js
│
├── config/
│   └── database.php
│
├── database/
│   └── schema.sql
│
├── includes/
│   └── functions.php
│
├── scripts/
│   └── init_database.php
│
├── index.php
├── transactions.php
├── recurring.php
├── budgets.php
├── savings.php
├── categories.php
├── reports.php
├── settings.php
├── backup.php
├── restore.php
├── tutorial.php
└── README.md
```

## Konsep Pengelolaan Saldo

FinTrack menggunakan konsep saldo awal sebagai jumlah uang yang telah dimiliki pengguna sebelum periode pencatatan dimulai.

Perhitungan saldo tersedia menggunakan rumus:

```text
Saldo tersedia =
Saldo awal
+ Pemasukan
- Pengeluaran
- Alokasi bersih tabungan
```

Setoran dan penarikan tabungan dicatat sebagai aktivitas tabungan tersendiri, sehingga tidak mengubah laporan pemasukan dan pengeluaran.

## Database dan Backup

FinTrack menggunakan SQLite sebagai database lokal. Data aplikasi disimpan dalam file database sehingga tidak memerlukan server database terpisah.

Aplikasi juga menyediakan fitur backup dan restore untuk membantu pengguna menjaga serta memulihkan data keuangan.

## Status Pengembangan

FinTrack dikembangkan sebagai proyek pembelajaran dan portofolio di bidang pengembangan aplikasi web.

Fitur-fitur aplikasi dikembangkan secara bertahap dengan fokus pada pengelolaan keuangan pribadi, konsistensi data, dan pengalaman pengguna.

---

**FinTrack – Manage Your Money, Understand Your Future.**
