<?php

require_once __DIR__ . '/includes/functions.php';

$topics = [
    [
        'number' => '01',
        'title' => 'Mengenal FinTrack',
        'description' => 'FinTrack merupakan aplikasi untuk membantu mencatat dan memantau kondisi keuangan pribadi.',
        'steps' => [
            'Dashboard menampilkan saldo tersedia, pemasukan, pengeluaran, dan ringkasan anggaran.',
            'Menu Transaksi digunakan untuk mencatat uang masuk dan uang keluar.',
            'Menu Anggaran membantu membatasi pengeluaran berdasarkan kategori.',
            'Menu Tabungan digunakan untuk mengalokasikan uang ke target tabungan.',
            'Menu Laporan menampilkan ringkasan aktivitas keuangan.'
        ],
        'link' => 'index.php',
        'button' => 'Buka Dashboard'
    ],
    [
        'number' => '02',
        'title' => 'Mengatur Saldo Awal',
        'description' => 'Saldo awal adalah jumlah uang yang sudah kamu miliki sebelum mulai menggunakan FinTrack. Saldo ini bukan pemasukan baru.',
        'steps' => [
            'Buka menu Pengaturan.',
            'Masukkan jumlah uang yang sudah kamu miliki ketika mulai mencatat keuangan.',
            'Tentukan tanggal mulai pencatatan.',
            'Klik Simpan Pengaturan.',
            'Saldo awal akan menjadi dasar perhitungan saldo tersedia.'
        ],
        'note' => 'Contoh: Kamu sudah memiliki uang Rp1.000.000 sebelum menggunakan FinTrack. Masukkan Rp1.000.000 sebagai saldo awal, bukan sebagai transaksi pemasukan.',
        'link' => 'settings.php',
        'button' => 'Atur Saldo Awal'
    ],
    [
        'number' => '03',
        'title' => 'Mencatat Transaksi',
        'description' => 'Setiap pemasukan dan pengeluaran sebaiknya dicatat agar kondisi keuangan tetap terpantau.',
        'steps' => [
            'Buka menu Transaksi.',
            'Pilih jenis transaksi, yaitu Pemasukan atau Pengeluaran.',
            'Pilih kategori yang sesuai.',
            'Masukkan nominal transaksi.',
            'Isi tanggal dan deskripsi transaksi.',
            'Simpan transaksi.'
        ],
        'note' => 'Contoh pemasukan: menerima uang bulanan Rp2.000.000. Contoh pengeluaran: membeli makan Rp20.000.',
        'link' => 'transactions.php',
        'button' => 'Coba Catat Transaksi'
    ],
    [
        'number' => '04',
        'title' => 'Mengelola Kategori',
        'description' => 'Kategori membantu mengelompokkan transaksi sehingga pengeluaran dan pemasukan lebih mudah dianalisis.',
        'steps' => [
            'Buka menu Kategori.',
            'Pilih jenis kategori, pemasukan atau pengeluaran.',
            'Tambahkan nama kategori yang dibutuhkan.',
            'Gunakan kategori tersebut ketika mencatat transaksi.'
        ],
        'note' => 'Contoh kategori pengeluaran: Makanan, Transportasi, Belanja, Tagihan, dan Hiburan.',
        'link' => 'categories.php',
        'button' => 'Kelola Kategori'
    ],
    [
        'number' => '05',
        'title' => 'Membuat Anggaran',
        'description' => 'Anggaran digunakan untuk menentukan batas pengeluaran pada kategori tertentu dalam satu bulan.',
        'steps' => [
            'Buka menu Anggaran.',
            'Pilih kategori pengeluaran.',
            'Tentukan bulan anggaran.',
            'Masukkan batas nominal anggaran.',
            'Simpan anggaran dan pantau penggunaannya.'
        ],
        'note' => 'Contoh: Kamu menetapkan anggaran makanan sebesar Rp600.000 untuk bulan November. Jika pengeluaran makanan sudah mencapai Rp500.000, berarti 83,3% anggaran telah digunakan.',
        'link' => 'budgets.php',
        'button' => 'Atur Anggaran'
    ],
    [
        'number' => '06',
        'title' => 'Transaksi Berulang',
        'description' => 'Fitur ini membantu mengelola transaksi yang memiliki jadwal rutin sehingga kamu tidak perlu mengatur jadwalnya dari awal setiap kali.',
        'steps' => [
            'Buka menu Transaksi Berulang.',
            'Buat data transaksi berulang.',
            'Masukkan nama, kategori, jenis transaksi, dan nominal.',
            'Tentukan frekuensi dan tanggal mulai.',
            'Kelola transaksi sesuai jadwal yang tersedia pada aplikasi.'
        ],
        'note' => 'Contoh: pembayaran internet Rp250.000 setiap bulan atau pemasukan rutin setiap minggu. Perhatikan status jadwal sebelum mencatat atau melewati transaksi.',
        'link' => 'recurring.php',
        'button' => 'Kelola Transaksi Berulang'
    ],
    [
        'number' => '07',
        'title' => 'Mengelola Tabungan',
        'description' => 'Tabungan digunakan untuk memisahkan sebagian saldo tersedia ke dalam target tertentu.',
        'steps' => [
            'Buka menu Tabungan.',
            'Buat target tabungan dan masukkan nominal yang ingin dicapai.',
            'Tambahkan setoran ketika mengalokasikan uang ke target.',
            'Gunakan penarikan ketika mengambil kembali dana dari target.',
            'Pantau perkembangan saldo dan pencapaian target.'
        ],
        'note' => 'Contoh: Kamu membuat target tabungan Rp1.000.000. Ketika menyetor Rp200.000, saldo tersedia berkurang Rp200.000 dan saldo target tabungan bertambah Rp200.000. Penarikan bekerja sebaliknya.',
        'link' => 'savings.php',
        'button' => 'Buka Tabungan'
    ],
    [
        'number' => '08',
        'title' => 'Melihat Laporan Keuangan',
        'description' => 'Laporan membantu memahami pola pemasukan dan pengeluaran berdasarkan transaksi yang telah dicatat.',
        'steps' => [
            'Buka menu Laporan.',
            'Pilih periode laporan jika tersedia.',
            'Perhatikan perbandingan pemasukan dan pengeluaran.',
            'Analisis kategori yang paling banyak menghabiskan uang.',
            'Gunakan hasilnya sebagai bahan evaluasi pengelolaan keuangan.'
        ],
        'note' => 'Laporan hanya akan memberikan gambaran yang akurat apabila transaksi dicatat secara konsisten.',
        'link' => 'reports.php',
        'button' => 'Lihat Laporan'
    ],
    [
        'number' => '09',
        'title' => 'Backup dan Restore Database',
        'description' => 'Backup digunakan untuk menyimpan salinan data, sedangkan Restore digunakan untuk mengembalikan data dari salinan tersebut.',
        'steps' => [
            'Buka menu Pengaturan.',
            'Pilih Unduh Backup Database untuk menyimpan salinan data.',
            'Simpan file backup di lokasi yang aman.',
            'Untuk pemulihan, pilih file backup melalui bagian Restore Database.',
            'Jalankan proses restore setelah memastikan file yang dipilih benar.'
        ],
        'note' => 'Penting: Restore akan mengganti database aktif dengan isi file backup. Data yang dibuat setelah backup tidak akan ikut dipertahankan. Simpan backup terbaru sebelum melakukan pemulihan.',
        'link' => 'settings.php',
        'button' => 'Buka Pengaturan'
    ],
];

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tutorial | FinTrack</title>
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .tutorial-overview {
            margin-bottom: 24px;
        }

        .tutorial-overview p {
            color: #68736d;
            line-height: 1.7;
        }

        .tutorial-progress-info {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
            font-size: 14px;
            color: #68736d;
        }

        .tutorial-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .tutorial-item {
            border: 1px solid #e5eae6;
            border-radius: 12px;
            background: #ffffff;
            overflow: hidden;
        }

        .tutorial-item[open] {
            border-color: #9bb9a9;
        }

        .tutorial-summary {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 20px;
            cursor: pointer;
            list-style: none;
        }

        .tutorial-summary::-webkit-details-marker {
            display: none;
        }

        .tutorial-number {
            width: 40px;
            height: 40px;
            min-width: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: #edf4ef;
            color: #315e49;
            font-weight: 700;
        }

        .tutorial-summary-text {
            flex: 1;
        }

        .tutorial-summary-text strong {
            display: block;
            margin-bottom: 4px;
            color: #25352c;
        }

        .tutorial-summary-text small {
            color: #7a847e;
            line-height: 1.5;
        }

        .tutorial-arrow {
            color: #68736d;
            transition: transform 0.2s ease;
        }

        .tutorial-item[open] .tutorial-arrow {
            transform: rotate(180deg);
        }

        .tutorial-content {
            padding: 0 20px 22px 75px;
            color: #4f5b54;
            line-height: 1.7;
        }

        .tutorial-content ol {
            padding-left: 20px;
        }

        .tutorial-content li {
            margin-bottom: 8px;
        }

        .tutorial-example {
            margin: 16px 0;
            padding: 14px 16px;
            border-left: 3px solid #548b72;
            border-radius: 6px;
            background: #f2f6f3;
            font-size: 14px;
        }

        .tutorial-action {
            margin-top: 16px;
        }

        .tutorial-action .btn {
            display: inline-block;
            text-decoration: none;
        }

        .tutorial-note {
            margin-top: 20px;
            padding: 16px;
            border-radius: 10px;
            background: #f5f6f5;
            color: #59645d;
            line-height: 1.7;
        }

        @media (max-width: 600px) {
            .tutorial-summary {
                padding: 15px;
                gap: 10px;
            }

            .tutorial-content {
                padding: 0 15px 18px;
            }

            .tutorial-summary-text small {
                display: block;
            }
        }
    </style>
</head>

<body>

    <div class="app-layout">

        <aside class="sidebar">

            <a href="index.php" class="brand">
                <span class="brand-icon">F</span>
                <span>
                    <strong>FinTrack</strong>
                    <small>Personal Finance</small>
                </span>
            </a>

            <div class="nav-label">MENU UTAMA</div>

            <nav class="sidebar-nav">

                <a href="index.php" class="nav-item">
                    <span>▦</span> Dashboard
                </a>

                <a href="transactions.php" class="nav-item">
                    <span>↔</span> Transaksi
                </a>

                <a href="recurring.php" class="nav-item">
                    <span>⟳</span> Transaksi Berulang
                </a>

                <a href="budgets.php" class="nav-item">
                    <span>◷</span> Anggaran
                </a>

                <a href="savings.php" class="nav-item">
                    <span>◈</span> Tabungan
                </a>

                <a href="categories.php" class="nav-item">
                    <span>▤</span> Kategori
                </a>

                <a href="reports.php" class="nav-item">
                    <span>▥</span> Laporan
                </a>

                <a href="tutorial.php" class="nav-item active">
                    <span>ⓘ</span> Tutorial
                </a>

                <a href="settings.php" class="nav-item">
                    <span>⚙</span> Pengaturan
                </a>

            </nav>

            <div class="sidebar-footer">
                Personal Expense Tracker<br>
                <small>Versi pengembangan 1.0</small>
            </div>

        </aside>

        <main class="main-content">

            <header class="topbar">
                <div>
                    <span class="breadcrumb">FinTrack / Tutorial</span>
                    <h1>Tutorial</h1>
                </div>
            </header>

            <section class="page-intro tutorial-overview">
                <h2>Panduan Penggunaan FinTrack</h2>
                <p>
                    Pelajari cara menggunakan fitur FinTrack melalui panduan berikut.
                    Kamu tidak harus membaca semuanya sekaligus. Pilih bagian yang
                    ingin kamu pahami, lalu langsung coba fitur tersebut.
                </p>
            </section>

            <section class="card">

                <div class="section-heading">
                    <div>
                        <h2>Panduan Fitur</h2>
                        <p>
                            Pilih salah satu topik untuk melihat petunjuk penggunaannya.
                        </p>
                    </div>
                </div>

                <div class="tutorial-list">

                    <?php foreach ($topics as $topic): ?>

                        <details class="tutorial-item">

                            <summary class="tutorial-summary">

                                <span class="tutorial-number">
                                    <?= e($topic['number']) ?>
                                </span>

                                <span class="tutorial-summary-text">
                                    <strong><?= e($topic['title']) ?></strong>
                                    <small><?= e($topic['description']) ?></small>
                                </span>

                                <span class="tutorial-arrow">⌄</span>

                            </summary>

                            <div class="tutorial-content">

                                <ol>
                                    <?php foreach ($topic['steps'] as $step): ?>
                                        <li><?= e($step) ?></li>
                                    <?php endforeach; ?>
                                </ol>

                                <?php if (isset($topic['note'])): ?>
                                    <div class="tutorial-example">
                                        <strong>Contoh:</strong>
                                        <?= e($topic['note']) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="tutorial-action">
                                    <a href="<?= e($topic['link']) ?>" class="btn btn-primary">
                                        <?= e($topic['button']) ?> →
                                    </a>
                                </div>

                            </div>

                        </details>

                    <?php endforeach; ?>

                </div>

            </section>

            <section class="tutorial-note">
                <strong>Tips:</strong>
                Catat transaksi secara rutin dan periksa dashboard secara berkala.
                Data yang konsisten akan membantu FinTrack memberikan gambaran
                kondisi keuangan yang lebih akurat.
            </section>

            <footer class="page-footer">
                FinTrack · Personal Expense Tracker
            </footer>

        </main>

    </div>

</body>

</html>