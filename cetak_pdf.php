<?php

// Letakkan ini di baris paling atas file cetak_pdf.php atau file koneksi/config Anda
date_default_timezone_set('Asia/Jakarta');

require_once 'auth.php';

// Proteksi Halaman - Wajib Login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Ambil data sesi pengguna untuk pencetak dokumen
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Operator';
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'Staff';

// Jalur Koneksi Database
$host = 'localhost';
$db   = 'smartstock_pro';
$user = 'root';
$pass = '';

// Fungsi Helper Transalsi Bulan Indonesia
function tanggal_indonesia($format_waktu) {
    $bulan = [
        'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret',
        'April' => 'April', 'May' => 'Mei', 'June' => 'Juni',
        'July' => 'Juli', 'August' => 'Agustus', 'September' => 'September',
        'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'
    ];
    $dateStr = date($format_waktu);
    return strtr($dateStr, $bulan);
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // DETEKSI OTOMATIS STRUKTUR TABEL BARANG (Agar Sinkron dengan barang.php)
    $kolom_kategori = 'kategori';
    $kolom_stok     = 'stok';
    $kolom_harga    = 'harga';
    $kolom_gudang   = 'gudang';

    $q_check = $pdo->query("DESCRIBE barang");
    $fields = $q_check->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('kategori_barang', $fields)) { $kolom_kategori = 'kategori_barang'; }
    if (in_array('stok_barang', $fields)) { $kolom_stok = 'stok_barang'; } elseif (in_array('jumlah', $fields)) { $kolom_stok = 'jumlah'; }
    if (in_array('harga_barang', $fields)) { $kolom_harga = 'harga_barang'; } elseif (in_array('nominal', $fields)) { $kolom_harga = 'nominal'; }
    if (in_array('lokasi_gudang', $fields)) { $kolom_gudang = 'lokasi_gudang'; } elseif (in_array('lokasi', $fields)) { $kolom_gudang = 'lokasi'; }

    // MENANGKAP PARAMETER PENCARIAN SINKRON
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    if ($search !== '') {
        $search_param = '%' . $search . '%';
        $stmt = $pdo->prepare("SELECT * FROM barang WHERE nama_barang LIKE ? OR kode_barang LIKE ? ORDER BY {$kolom_kategori} ASC, nama_barang ASC");
        $stmt->execute([$search_param, $search_param]);
        $daftar_barang = $stmt->fetchAll();

        // Hitung Ringkasan Kumulatif Terfilter
        $total_varian = count($daftar_barang);
        
        $stmt_stok = $pdo->prepare("SELECT SUM({$kolom_stok}) FROM barang WHERE nama_barang LIKE ? OR kode_barang LIKE ?");
        $stmt_stok->execute([$search_param, $search_param]);
        $total_stok = $stmt_stok->fetchColumn() ?? 0;
        
        $stmt_aset = $pdo->prepare("SELECT SUM({$kolom_stok} * {$kolom_harga}) FROM barang WHERE nama_barang LIKE ? OR kode_barang LIKE ?");
        $stmt_aset->execute([$search_param, $search_param]);
        $total_aset = $stmt_aset->fetchColumn() ?? 0;
    } else {
        // Ambil semua jika tanpa pencarian
        $stmt = $pdo->query("SELECT * FROM barang ORDER BY {$kolom_kategori} ASC, nama_barang ASC");
        $daftar_barang = $stmt->fetchAll();
        
        $total_varian = count($daftar_barang);
        $total_stok = $pdo->query("SELECT SUM({$kolom_stok}) FROM barang")->fetchColumn() ?? 0;
        $total_aset = $pdo->query("SELECT SUM({$kolom_stok} * {$kolom_harga}) FROM barang")->fetchColumn() ?? 0;
    }

} catch (\PDOException $e) {
    die("Gagal memuat sistem cetak laporan: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan_Inventaris_SmartStock_Pro</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; margin: 0; padding: 30px; font-size: 13px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 3px double #0f172a; padding-bottom: 15px; }
        .header h1 { margin: 0; font-size: 24px; color: #0f172a; text-transform: uppercase; }
        .header p { margin: 5px 0 0 0; color: #64748b; font-size: 12px; }
        
        .meta-info { display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 12px; }
        
        .summary-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 8px; margin-bottom: 25px; display: flex; justify-content: space-around; }
        .summary-item { text-align: center; }
        .summary-item span { display: block; font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: bold; }
        .summary-item strong { font-size: 16px; color: #2563eb; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #0f172a; color: white; padding: 10px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #cbd5e1; }
        tr:nth-child(even) td { background: #f8fafc; }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .sku { font-family: monospace; font-weight: bold; color: #0f172a; }
        
        .footer-sign { margin-top: 50px; display: flex; justify-content: flex-end; }
        .signature-space { text-align: center; width: 200px; }
        .signature-line { margin-top: 60px; border-top: 1px solid #333; padding-top: 5px; font-weight: bold; }

        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>SmartStock Pro - Manajemen Logistik</h1>
        <p>Gedung Logistik Terpadu, Komplek Pergudangan Modern Blok C, Jakarta</p>
        <p style="font-size: 11px; color: #94a3b8;">Email: support@smartstockpro.io | Telp: (021) 8849-2026</p>
    </div>

    <div class="meta-info">
        <div>
            <strong>Jenis Dokumen:</strong> Laporan Mutasi & Stok Fisik Global<br>
            <strong>Klasifikasi:</strong> Data Internal Perusahaan
        </div>
        <div style="text-align: right;">
            <strong>Tanggal Cetak:</strong> <?= tanggal_indonesia('d F Y / H:i') ?><br>
            <strong>Pencetak:</strong> <?= htmlspecialchars($username) ?> (<?= htmlspecialchars($role) ?>)
        </div>
    </div>

    <div class="summary-box">
        <div class="summary-item">
            <span>Total Varian Produk</span>
            <strong><?= number_format($total_varian) ?> Varian</strong>
        </div>
        <div class="summary-item">
            <span>Total Kuantitas Stok</span>
            <strong><?= number_format($total_stok) ?> Unit</strong>
        </div>
        <div class="summary-item">
            <span>Total Nilai Valuasi Aset</span>
            <strong style="color: #10b981;">Rp <?= number_format($total_aset, 0, ',', '.') ?></strong>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;" class="text-center">No</th>
                <th style="width: 15%;">Kode SKU</th>
                <th style="width: 25%;">Nama Komoditas</th>
                <th style="width: 15%;">Kategori</th>
                <th style="width: 10%;" class="text-center">Stok</th>
                <th style="width: 15%;" class="text-right">Harga Satuan</th>
                <th style="width: 15%;">Penempatan Gudang</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($total_varian === 0): ?>
                <tr>
                    <td colspan="7" class="text-center" style="padding: 30px; color: #64748b;">Tidak ada data sediaan komoditas yang terekam.</td>
                </tr>
            <?php else: ?>
                <?php $no = 1; foreach ($daftar_barang as $b): ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td class="sku"><?= htmlspecialchars($b['kode_barang'] ?? '') ?></td>
                        <td style="font-weight: 600;"><?= htmlspecialchars($b['nama_barang'] ?? '') ?></td>
                        <td><?= htmlspecialchars($b[$kolom_kategori] ?? 'Umum') ?></td>
                        <td class="text-center" style="font-weight: 600;"><?= number_format($b[$kolom_stok] ?? 0) ?></td>
                        <td class="text-right">Rp <?= number_format($b[$kolom_harga] ?? 0, 0, ',', '.') ?></td>
                        <td><?= htmlspecialchars($b[$kolom_gudang] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="footer-sign">
        <div class="signature-space">
            <p>Jakarta, <?= tanggal_indonesia('d F Y') ?></p>
            <p style="margin-top: 5px; color:#64748b; font-size:11px;">Penanggung Jawab Operasional,</p>
            <div class="signature-line"><?= htmlspecialchars($username) ?></div>
            <span style="font-size: 11px; color: #64748b;"><?= htmlspecialchars($role) ?></span>
        </div>
    </div>
</body>
</html>