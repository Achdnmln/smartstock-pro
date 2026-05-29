<?php
require_once 'auth.php';

// Proteksi Halaman: Hanya yang sudah login yang boleh unduh data
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Koneksi Database Aman ke smartstock_pro
$host = 'localhost';
$db   = 'smartstock_pro';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Koneksi Database Gagal: " . $e->getMessage());
}

// Ambil Seluruh Data Barang Inventaris dari Database
try {
    $stmt = $pdo->query("SELECT * FROM barang ORDER BY id DESC");
    $daftar_barang = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Gagal mengambil data untuk laporan: " . $e->getMessage());
}

// Set Header HTTP agar Browser mengenali output sebagai file Excel spreadsheet (.xls)
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=Laporan_Inventaris_SmartStock_" . date('Y-m-d') . ".xls");
header("Pragma: no-cache");
header("Expires: 0");
?>

<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <style>
        .title { font-family: 'Segoe UI', sans-serif; font-size: 16px; font-weight: bold; text-align: center; }
        .meta { font-family: 'Segoe UI', sans-serif; font-size: 11px; color: #555555; }
        table { border-collapse: collapse; width: 100%; font-family: 'Segoe UI', sans-serif; font-size: 11px; }
        th { background-color: #1e293b; color: #ffffff; border: 1px solid #cbd5e1; padding: 8px; font-weight: bold; text-transform: uppercase; }
        td { border: 1px solid #cbd5e1; padding: 8px; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
    </style>
</head>
<body>

    <div class="title">LAPORAN MANAJEMEN INVENTARIS BARANG GLOBAL</div>
    <div class="title">SMARTSTOCK PRO SYSTEM</div>
    <br>
    <div class="meta">Tanggal Unduh: <b><?= date('d F Y H:i:s') ?></b></div>
    <div class="meta">Dibuat Oleh: <b><?= htmlspecialchars($_SESSION['username'] . " (" . $_SESSION['role'] . ")") ?></b></div>
    <br>

    <table>
        <thead>
            <tr>
                <th style="width: 50px;">No</th>
                <th>Kode Barang</th>
                <th>Nama Produk / Barang</th>
                <th>Kategori</th>
                <th>Stok Gudang</th>
                <th>Harga Satuan (Rp)</th>
                <th>Alokasi Lokasi Gudang</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($daftar_barang)): ?>
                <tr>
                    <td colspan="7" class="text-center" style="color: #64748b; font-style: italic;">Tidak ada data inventaris di dalam database.</td>
                </tr>
            <?php else: ?>
                <?php 
                $no = 1; 
                $total_stok_global = 0;
                foreach ($daftar_barang as $b): 
                    // Penyesuaian nama kolom otomatis agar terhindar dari error key database
                    $harga_satuan = $b['harga_satuan'] ?? ($b['harga'] ?? 0);
                    $lokasi_gudang = $b['lokasi_gudang'] ?? ($b['lokasi'] ?? ($b['gudang'] ?? 'Gudang Utama'));
                    $kode_barang = $b['kode_barang'] ?? ($b['kode'] ?? '-');
                    
                    $total_stok_global += $b['stok'];
                ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td x:str class="text-center"><b><?= htmlspecialchars($kode_barang) ?></b></td>
                        <td><?= htmlspecialchars($b['nama_barang']) ?></td>
                        <td><?= htmlspecialchars($b['kategori']) ?></td>
                        <td class="text-center bold"><?= number_format($b['stok']) ?> Unit</td>
                        <td class="text-right">Rp <?= number_format($harga_satuan, 0, ',', '.') ?></td>
                        <td><?= htmlspecialchars($lokasi_gudang) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="background-color: #f8fafc;">
                    <td colspan="4" class="text-right bold" style="padding: 10px;">TOTAL AKUMULASI STOK GLOBAL:</td>
                    <td class="text-center bold" style="color: #2563eb; font-size: 12px;"><?= number_format($total_stok_global) ?> Unit</td>
                    <td colspan="2" style="background-color: #e2e8f0;"></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>