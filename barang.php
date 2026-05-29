<?php
// SET ZONA WAKTU INTERNASIONAL KE ASIA/JAKARTA (WIB)
date_default_timezone_set('Asia/Jakarta');

require_once 'auth.php';

// Proteksi Halaman - Wajib Login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Ambil data sesi dengan fallback aman jika kosong
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Operator';
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'Staff';

// PENGAMAN UTAMA: Pastikan user_id_aktif bernilai angka bulat positif dan tidak NULL
$user_id_aktif = (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) ? (int)$_SESSION['user_id'] : 1;

// Jalur Koneksi Database
$host = 'localhost';
$db   = 'smartstock_pro';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Paksa MySQL menggunakan Zona Waktu Asia/Jakarta (+07:00) agar sinkron dengan PHP
    $pdo->exec("SET time_zone = '+07:00'");
} catch (\PDOException $e) {
    die("Koneksi Gagal: " . $e->getMessage());
}

$success_msg = "";
$error_msg = "";

// Ambil status pesan dari URL (Redirect Session Handling)
if (isset($_GET['insert_success'])) {
    $success_msg = "Barang baru berhasil ditambahkan ke sistem inventaris!";
}
if (isset($_GET['delete_success'])) {
    $success_msg = "Data barang telah berhasil dihapus dari sistem.";
}
if (isset($_GET['error_sku'])) {
    $error_msg = "Gagal menyimpan: Kode SKU '" . htmlspecialchars($_GET['error_sku']) . "' sudah digunakan oleh barang lain!";
}
if (isset($_GET['error_log'])) {
    $error_msg = "Barang tersimpan, TAPI LOG GAGAL: " . htmlspecialchars($_GET['error_log']);
}

// DETEKSI OTOMATIS STRUKTUR TABEL BARANG
$kolom_kategori = 'kategori';
$kolom_stok     = 'stok';
$kolom_harga    = 'harga';
$kolom_gudang   = 'gudang';

try {
    $q_check = $pdo->query("DESCRIBE barang");
    $fields = $q_check->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('kategori_barang', $fields)) { $kolom_kategori = 'kategori_barang'; }
    if (in_array('stok_barang', $fields)) { $kolom_stok = 'stok_barang'; } elseif (in_array('jumlah', $fields)) { $kolom_stok = 'jumlah'; }
    if (in_array('harga_barang', $fields)) { $kolom_harga = 'harga_barang'; } elseif (in_array('nominal', $fields)) { $kolom_harga = 'nominal'; }
    if (in_array('lokasi_gudang', $fields)) { $kolom_gudang = 'lokasi_gudang'; } elseif (in_array('lokasi', $fields)) { $kolom_gudang = 'lokasi'; }
} catch (PDOException $e) { }

// PROSES TAMBAH BARANG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah_barang'])) {
    $kode_barang   = isset($_POST['kode_barang']) ? trim($_POST['kode_barang']) : '';
    $nama_barang   = isset($_POST['nama_barang']) ? trim($_POST['nama_barang']) : '';
    $kategori      = isset($_POST['kategori']) ? $_POST['kategori'] : '';
    $stok          = isset($_POST['stok']) ? (int)$_POST['stok'] : 0;
    $harga         = isset($_POST['harga']) ? (float)$_POST['harga'] : 0.0;
    $gudang        = isset($_POST['gudang']) ? $_POST['gudang'] : '';
    $tanggal_masuk = !empty($_POST['tanggal_masuk']) ? $_POST['tanggal_masuk'] : date('Y-m-d');
    $status_kete   = isset($_POST['status_ketersediaan']) ? $_POST['status_ketersediaan'] : 'Tersedia';
    
    if (empty($kode_barang) || empty($nama_barang)) {
        $error_msg = "Gagal menyimpan: Kode SKU dan Nama Barang tidak boleh kosong!";
    } else {
        $stmt_cek = $pdo->prepare("SELECT COUNT(*) FROM barang WHERE kode_barang = ?");
        $stmt_cek->execute([$kode_barang]);
        
        if ($stmt_cek->fetchColumn() > 0) {
            header("Location: barang.php?error_sku=" . urlencode($kode_barang));
            exit;
        } else {
            $foto_name = "";
            if (isset($_FILES['foto_produk']) && $_FILES['foto_produk']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['foto_produk']['tmp_name'];
                $foto_name = time() . '_' . basename($_FILES['foto_produk']['name']);
                $target_dir = "uploads/";
                if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
                move_uploaded_file($file_tmp, $target_dir . $foto_name);
            }

            try {
                $query_sql = "INSERT INTO barang (kode_barang, nama_barang, {$kolom_kategori}, {$kolom_stok}, {$kolom_harga}, {$kolom_gudang}, foto, tanggal_masuk, status_ketersediaan) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_insert = $pdo->prepare($query_sql);
                $stmt_insert->execute([$kode_barang, $nama_barang, $kategori, $stok, $harga, $gudang, $foto_name, $tanggal_masuk, $status_kete]);
                
                // CATAT LOG TAMBAH BARANG (ip_address DIBUANG)
                try {
                    $aksi_pesan = "Mendaftarkan barang baru: " . $nama_barang . " (SKU: " . $kode_barang . ")";
                    
                    $stmt_log = $pdo->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
                    $stmt_log->execute([$user_id_aktif, $aksi_pesan]);
                    
                    header("Location: barang.php?insert_success=1");
                    exit;
                } catch (PDOException $log_error) {
                    // Jika gagal, lempar pesannya ke URL agar bisa ditangkap oleh SweetAlert
                    header("Location: barang.php?error_log=" . urlencode($log_error->getMessage()));
                    exit;
                }

            } catch (PDOException $e) {
                $error_msg = "Gagal menyimpan data ke MySQL: " . $e->getMessage();
            }
        }
    }
}

// PROSES HAPUS BARANG
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id_hapus = (int)$_GET['id'];
    try {
        $stmt_info = $pdo->prepare("SELECT id, kode_barang, nama_barang FROM barang WHERE id = ?");
        $stmt_info->execute([$id_hapus]);
        $info_barang = $stmt_info->fetch();

        if ($info_barang) {
            $stmt_del = $pdo->prepare("DELETE FROM barang WHERE id = ?");
            $stmt_del->execute([$id_hapus]);

            // CATAT LOG HAPUS BARANG (ip_address DIBUANG)
            try {
                $aksi_pesan = "Menghapus barang dari sistem dengan SKU: " . $info_barang['kode_barang'] . " (" . $info_barang['nama_barang'] . ")";
                
                $stmt_log = $pdo->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
                $stmt_log->execute([$user_id_aktif, $aksi_pesan]);
                
                header("Location: barang.php?delete_success=1");
                exit;
            } catch (PDOException $log_error) {
                header("Location: barang.php?error_log=" . urlencode($log_error->getMessage()));
                exit;
            }
        }
    } catch (PDOException $e) {
        $error_msg = "Gagal menghapus data barang.";
    }
}

// AMBIL DATA BARANG & PENCARIAN
$search_keyword = isset($_GET['search']) ? trim($_GET['search']) : '';
try {
    $search_param = '%' . $search_keyword . '%';
    $stmt_fetch = $pdo->prepare("SELECT * FROM barang WHERE nama_barang LIKE ? OR kode_barang LIKE ? ORDER BY id DESC");
    $stmt_fetch->execute([$search_param, $search_param]);
    $daftar_barang = $stmt_fetch->fetchAll();
} catch (PDOException $e) {
    die("Gagal memuat data barang: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Stok Barang - SmartStock Pro</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

    <style>
        :root { 
            --primary: #1e3a8a; 
            --accent: #3b82f6;  
            --bg: #f0f4f8;      
            --card: #ffffff; 
            --text-dark: #334155;
            --text-muted: #64748b;
        }
        
        body { 
            font-family: 'Inter', system-ui, sans-serif; 
            margin: 0; 
            padding: 40px 20px; 
            background-color: var(--bg); 
            display: flex; 
            justify-content: center; 
            color: var(--text-dark); 
            -webkit-font-smoothing: antialiased;
        }
        .main-wrapper { width: 100%; max-width: 1200px; }
        
        .top-nav { 
            background: var(--card); 
            padding: 14px 25px; 
            border-radius: 20px; 
            display: flex;
            justify-content: space-between;
            align-items: center; 
            box-shadow: 0 4px 25px rgba(148, 163, 184, 0.15); 
            margin-bottom: 35px; 
            border: 1px solid #e2e8f0; 
        }
        
        .nav-brand { 
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 800; 
            font-size: 20px; 
            color: var(--primary); 
            display: flex; 
            align-items: center; 
            gap: 8px; 
        }
        .nav-links { display: flex; gap: 12px; align-items: center; }
        
        .nav-item { 
            text-decoration: none; 
            padding: 10px 18px; 
            border-radius: 12px; 
            font-size: 14px; 
            font-weight: 600; 
            color: var(--text-dark); 
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease; 
            font-family: 'Inter', sans-serif;
        }
        .nav-item:hover { background: #e2e8f0; color: var(--primary); }
        .nav-item.active { background: #dbeafe; color: #1e40af !important; }
        
        .logout-btn { 
            text-decoration: none; 
            padding: 10px 18px; 
            color: #dc2626 !important; 
            background: #fee2e2; 
            border-radius: 12px; 
            font-size: 14px; 
            font-weight: 600; 
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease; 
            font-family: 'Inter', sans-serif;
        }
        .logout-btn:hover { background: #fca5a5; }

        .section-title { 
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 24px; 
            font-weight: 800; 
            color: var(--primary); 
            margin: 0; 
        }
        .role-badge { 
            background: #e2e8f0; 
            padding: 6px 14px; 
            border-radius: 20px; 
            font-size: 12px; 
            font-weight: 600; 
            color: var(--primary); 
            border: 1px solid #cbd5e1; 
            font-family: 'Inter', sans-serif;
        }
        
        .form-card { background: var(--card); padding: 25px; border-radius: 20px; border: 1px solid #cbd5e1; margin-bottom: 30px; box-shadow: 0 4px 20px rgba(148, 163, 184, 0.08); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .form-card  .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-card .form-group label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
        .form-control { padding: 11px 14px; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 14px; color: var(--text-dark); background-color: #f8fafc; font-family: 'Inter', sans-serif; }
        .form-control:focus { outline: none; border-color: var(--accent); background-color: #fff; }
        
        .btn { padding: 11px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; border: none; transition: background 0.2s; font-family: 'Inter', sans-serif; }
        .btn-primary { background: var(--accent); color: white; }
        .btn-primary:hover { background: #2563eb; }
        
        .btn-pdf { text-decoration: none; background: #e0f2fe; color: #0369a1; padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; border: 1px solid #bae6fd; cursor: pointer; font-family: 'Inter', sans-serif; }
        .btn-pdf:hover { background: #bae6fd; color: #0369a1; }
        
        .table-card { background: var(--card); padding: 25px; border-radius: 20px; border: 1px solid #cbd5e1; overflow-x: auto; box-shadow: 0 4px 20px rgba(148, 163, 184, 0.08); }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        th { background: #f1f5f9; padding: 16px 14px; color: var(--primary); font-weight: 700; border-bottom: 2px solid #cbd5e1; text-transform: uppercase; font-size: 11px; text-align: center; }
        td { padding: 18px 14px; border-bottom: 1px solid #e2e8f0; color: var(--text-dark); vertical-align: middle; text-align: center; line-height: 1.5; }
        tr:hover { background-color: #f8fafc; }
        
        .img-container { display: flex; align-items: center; justify-content: center; }
        .img-preview { width: 48px; height: 48px; border-radius: 10px; object-fit: cover; background: #f1f5f9; border: 1px solid #cbd5e1; }
        
        .sku-box { font-family: 'Inter', monospace; font-weight: 700; color: var(--accent); background: #eff6ff; padding: 6px 10px; border-radius: 6px; display: inline-block; letter-spacing: 0.5px; border: 1px solid #dbeafe; }
        .status-badge { padding: 5px 12px; border-radius: 30px; font-size: 11px; font-weight: 700; display: inline-block; text-align: center; }
        .status-tersedia { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .status-habis { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        
        .btn-delete { color: #dc2626; background: #fee2e2; padding: 6px 14px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-block; transition: all 0.2s ease; white-space: nowrap; border: 1px solid #fca5a5; font-family: 'Inter', sans-serif; }
        .btn-delete:hover { background: #fca5a5; }

        .search-container { margin-bottom: 20px; display: flex; gap: 10px; justify-content: flex-start; }
        .search-input { max-width: 300px; }
    </style>
</head>
<body>

<div class="main-wrapper">
    <nav class="top-nav">
        <div class="nav-brand">
            <i class="fa-solid fa-box-archive" style="color: #3b82f6;"></i> SmartStock Pro
        </div>
        
        <div class="nav-links">
            <a href="dashboard.php" class="nav-item">🏠 Dashboard Utama</a>
            <a href="barang.php" class="nav-item active">📦 Kelola Stok Barang</a>
            <a href="audit_logs.php" class="nav-item">📜 Log Aktivitas</a>
            <a href="audit_logs.php?action=logout" class="logout-btn">Keluar 🚪</a>
        </div>
    </nav>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
        <h1 class="section-title">Manajemen Sediaan Barang</h1>
        <div class="role-badge">Operator: <strong><?= htmlspecialchars($username) ?></strong></div>
    </div>

    <div class="form-card">
        <form method="POST" action="barang.php" enctype="multipart/form-data">
            <div class="form-grid">
                <div class="form-group"><label>Kode SKU</label><input type="text" name="kode_barang" class="form-control" placeholder="Contoh: BRG-001" required></div>
                <div class="form-group"><label>Nama Barang</label><input type="text" name="nama_barang" class="form-control" required></div>
                <div class="form-group">
                    <label>Kategori</label>
                    <select name="kategori" class="form-control">
                        <option value="Elektronik">Elektronik</option>
                        <option value="Pakaian">Pakaian</option>
                        <option value="Makanan">Makanan</option>
                    </select>
                </div>
                <div class="form-group"><label>Stok</label><input type="number" name="stok" class="form-control" value="0" required></div>
                <div class="form-group"><label>Harga (Rp)</label><input type="number" name="harga" class="form-control" required></div>
                <div class="form-group">
                    <label>Lokasi Gudang</label>
                    <select name="gudang" class="form-control" required>
                        <option value="Gudang Utama Barat">Gudang Utama Barat</option>
                        <option value="Gudang Regional Tengah">Gudang Regional Tengah</option>
                        <option value="Gudang Pusat">Gudang Pusat</option>
                    </select>
                </div>
                <div class="form-group"><label>Tanggal Masuk</label><input type="date" name="tanggal_masuk" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status_ketersediaan" class="form-control">
                        <option value="Tersedia">Tersedia</option>
                        <option value="Habis">Habis</option>
                    </select>
                </div>
                <div class="form-group"><label>Foto Produk</label><input type="file" name="foto_produk" class="form-control"></div>
            </div>
            <button type="submit" name="tambah_barang" class="btn btn-primary">Simpan Barang</button>
        </form>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 15px;">
        <form method="GET" action="barang.php" class="search-container">
            <input type="text" name="search" class="form-control search-input" placeholder="Cari nama atau SKU..." value="<?= htmlspecialchars($search_keyword) ?>">
            <button type="submit" class="btn btn-primary" style="padding: 10px 15px;"><i class="fa-solid fa-magnifying-glass"></i></button>
            <?php if($search_keyword !== ''): ?>
                <a href="barang.php" class="btn" style="background:#cbd5e1; color:#334155; text-decoration:none; padding:10px 15px;">Reset</a>
            <?php endif; ?>
        </form>
        
        <button type="button" onclick="cetakTanpaTabBaru()" class="btn-pdf">
            <i class="fa-solid fa-print"></i> Cetak PDF Laporan
        </button>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Gambar</th>
                    <th>SKU</th>
                    <th>Nama Barang</th>
                    <th>Kategori</th>
                    <th>Stok</th>
                    <th>Harga</th>
                    <th>Gudang</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($daftar_barang) === 0): ?>
                    <tr><td colspan="9" style="text-align:center; padding: 30px;">Tidak ada data barang ditemukan.</td></tr>
                <?php else: ?>
                    <?php foreach ($daftar_barang as $b): ?>
                        <tr>
                            <td>
                                <div class="img-container">
                                    <?php $foto_tampil = $b['foto'] ?? ($b['gambar'] ?? ''); ?>
                                    <?php if (!empty($foto_tampil) && file_exists("uploads/" . $foto_tampil)): ?>
                                        <img src="uploads/<?= htmlspecialchars($foto_tampil) ?>" class="img-preview">
                                    <?php else: ?>
                                        <div class="img-preview" style="background:#cbd5e1; display:flex; align-items:center; justify-content:center; color:#94a3b8;"><i class="fa-solid fa-image"></i></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><span class="sku-box"><?= htmlspecialchars($b['kode_barang'] ?? '') ?></span></td>
                            <td><strong><?= htmlspecialchars($b['nama_barang'] ?? '') ?></strong></td>
                            <td><?= htmlspecialchars($b[$kolom_kategori] ?? 'Umum') ?></td>
                            <td><?= number_format($b[$kolom_stok] ?? 0) ?> Pcs</td>
                            <td style="color:#059669; font-weight:700;">Rp <?= number_format($b[$kolom_harga] ?? 0, 0, ',', '.') ?></td>
                            <td><?= htmlspecialchars($b[$kolom_gudang] ?? 'Gudang Utama Barat') ?></td>
                            <td>
                                <?php $status = $b['status_ketersediaan'] ?? 'Tersedia'; ?>
                                <span class="status-badge <?= $status === 'Tersedia' ? 'status-tersedia' : 'status-habis' ?>"><?= $status ?></span>
                            </td>
                            <td><a href="javascript:void(0);" onclick="konfirmasiHapus(<?= $b['id'] ?>)" class="btn-delete">Hapus</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    // Penanganan SweetAlert dengan encoding JSON yang aman dari XSS
    <?php if ($success_msg !== ""): ?>
        Swal.fire({ icon: 'success', title: 'Berhasil!', text: <?= json_encode($success_msg) ?>, confirmButtonColor: '#3b82f6' });
    <?php endif; ?>
    <?php if ($error_msg !== ""): ?>
        // Jika log gagal, modal akan berwarna kuning/merah memperingatkan error database aslinya
        Swal.fire({ icon: 'warning', title: 'Perhatian!', text: <?= json_encode($error_msg) ?>, confirmButtonColor: '#ea580c' });
    <?php endif; ?>

    // Fungsi Cetak Sinkron dengan Query Filter Pencarian
    function cetakTanpaTabBaru() {
        let iframeLama = document.getElementById('iframeCetakLaporan');
        if (iframeLama) { iframeLama.remove(); }

        let keyword = <?= json_encode($search_keyword) ?>;
        let urlCetak = 'cetak_pdf.php';
        if(keyword !== '') {
            urlCetak += '?search=' + encodeURIComponent(keyword);
        }

        let iframe = document.createElement('iframe');
        iframe.id = 'iframeCetakLaporan';
        iframe.src = urlCetak;
        
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = 'none';

        document.body.appendChild(iframe);

        iframe.onload = function() {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        };
    }

    function konfirmasiHapus(id) {
        Swal.fire({
            title: 'Hapus Barang?',
            text: "Data akan dihapus dari sistem!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Hapus!'
        }).then((result) => {
            if (result.isConfirmed) { window.location.href = "barang.php?action=delete&id=" + id; }
        })
    }
</script>
</body>
</html>