<?php
require_once 'auth.php';

// Proteksi Halaman: Pastikan user sudah login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['username'];
$role = $_SESSION['role'];

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

// Generate CSRF Token untuk keamanan Form jika belum ada
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ---------------------------------------------------------
// PROSES TAMBAH BARANG
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'tambah') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Validasi Keamanan CSRF Gagal!");
    }

    if (in_array($role, ['Admin', 'Manajer Gudang', 'Staf Gudang'])) {
        $kode = trim($_POST['kode_barang']);
        $nama = trim($_POST['nama_barang']);
        $kategori = trim($_POST['kategori']);
        $stok = intval($_POST['stok']);
        $harga = isset($_POST['harga']) ? floatval($_POST['harga']) : 0;
        $gudang_id = !empty($_POST['lokasi_gudang']) ? intval($_POST['lokasi_gudang']) : null;

        if (!empty($kode) && !empty($nama)) {
            try {
                $stmt_check = $pdo->query("SHOW COLUMNS FROM barang");
                $columns = $stmt_check->fetchAll(PDO::FETCH_COLUMN);
                
                $insert_data = [];
                
                if (in_array('kode_barang', $columns)) $insert_data['kode_barang'] = $kode;
                elseif (in_array('kode', $columns)) $insert_data['kode'] = $kode;

                if (in_array('nama_barang', $columns)) $insert_data['nama_barang'] = $nama;
                elseif (in_array('nama', $columns)) $insert_data['nama'] = $nama;

                if (in_array('kategori', $columns)) $insert_data['kategori'] = $kategori;
                if (in_array('stok', $columns)) $insert_data['stok'] = $stok;

                if (in_array('harga_satuan', $columns)) $insert_data['harga_satuan'] = $harga;
                elseif (in_array('harga', $columns)) $insert_data['harga'] = $harga;

                if (in_array('gudang_id', $columns)) {
                    $insert_data['gudang_id'] = $gudang_id;
                }

                $fields = array_keys($insert_data);
                $placeholders = array_fill(0, count($fields), '?');
                
                $sql = "INSERT INTO barang (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_values($insert_data));
                
                // PROSES LOG AUDIT PINTAR
                $ip = $_SERVER['REMOTE_ADDR'];
                $pesan_log = "Mendaftarkan barang baru: $nama ($kode)";

                try {
                    $stmt_cols_log = $pdo->query("SHOW COLUMNS FROM audit_logs");
                    $columns_log = $stmt_cols_log->fetchAll(PDO::FETCH_COLUMN);
                    
                    $col_activity = in_array('aktivitas', $columns_log) ? 'aktivitas' : (in_array('activity', $columns_log) ? 'activity' : 'action');
                    $col_ip = in_array('ip_address', $columns_log) ? 'ip_address' : (in_array('ip', $columns_log) ? 'ip' : '');

                    if (!empty($col_ip)) {
                        $sql_log = "INSERT INTO audit_logs (user_id, $col_activity, $col_ip) VALUES (?, ?, ?)";
                        $stmt_log = $pdo->prepare($sql_log);
                        $stmt_log->execute([$_SESSION['user_id'], $pesan_log, $ip]);
                    } else {
                        $sql_log = "INSERT INTO audit_logs (user_id, $col_activity) VALUES (?, ?)";
                        $stmt_log = $pdo->prepare($sql_log);
                        $stmt_log->execute([$_SESSION['user_id'], $pesan_log]);
                    }
                } catch (PDOException $log_err) {}
                
                header("Location: barang.php?msg=success");
                exit;
            } catch (PDOException $e) {
                $error_msg = "Gagal simpan ke database: " . $e->getMessage();
            }
        } else {
            $error_msg = "Form input tidak boleh kosong!";
        }
    }
}

// ---------------------------------------------------------
// PROSES HAPUS BARANG
// ---------------------------------------------------------
if (isset($_GET['hapus'])) {
    if (in_array($role, ['Admin', 'Manajer Gudang'])) {
        $id_hapus = intval($_GET['hapus']);
        try {
            $stmt_info = $pdo->prepare("SELECT * FROM barang WHERE id = ?");
            $stmt_info->execute([id_hapus]);
            $barang_info = $stmt_info->fetch();

            if ($barang_info) {
                $nama_b = $barang_info['nama_barang'] ?? ($barang_info['nama'] ?? 'Item');
                $stmt = $pdo->prepare("DELETE FROM barang WHERE id = ?");
                $stmt->execute([$id_hapus]);

                $ip = $_SERVER['REMOTE_ADDR'];
                try {
                    $stmt_cols_log = $pdo->query("SHOW COLUMNS FROM audit_logs");
                    $columns_log = $stmt_cols_log->fetchAll(PDO::FETCH_COLUMN);
                    $col_activity = in_array('aktivitas', $columns_log) ? 'aktivitas' : (in_array('activity', $columns_log) ? 'activity' : 'action');
                    
                    $stmt_log = $pdo->prepare("INSERT INTO audit_logs (user_id, $col_activity, ip_address) VALUES (?, ?, ?)");
                    $stmt_log->execute([$_SESSION['user_id'], "Menghapus produk: " . $nama_b, $ip]);
                } catch (Exception $e) {}
                
                header("Location: barang.php?msg=deleted");
                exit;
            }
        } catch (PDOException $e) {
            $error_msg = "Gagal menghapus data: " . $e->getMessage();
        }
    }
}

// Ambil Semua Data Barang untuk Tabel
try {
    $stmt_b = $pdo->query("SELECT * FROM barang ORDER BY id DESC");
    $daftar_barang = $stmt_b->fetchAll();
} catch (PDOException $e) {
    die("Gagal mengambil data inventaris: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Histori Aktivitas Sistem (Forensik)</title>
    <style>
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; margin: 0; padding: 30px; background-color: #f1f5f9; display: flex; justify-content: center; }
        .container { background: white; padding: 35px; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.05); width: 100%; max-width: 1050px; box-sizing: border-box; }
        
        /* Font Judul disamakan persis dengan Halaman Dashboard Utama */
        .header-panel { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 2px solid #f1f5f9; padding-bottom: 20px; }
        .header-panel h1 { margin: 0; font-size: 24px; font-weight: 700; color: #0f172a; letter-spacing: -0.5px; }
        .user-badge { background: #e2e8f0; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; color: #334155; }
        .user-badge span { color: #2563eb; }

        /* Menu Navigasi Lengkap Dikembalikan Sesuai Desain Awal */
        .nav-container { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; width: 100%; }
        .nav-links { display: flex; gap: 10px; align-items: center; }
        .nav-links a { text-decoration: none; padding: 9px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; transition: all 0.2s; }
        .nav-links a:hover { background: #f1f5f9; }
        .nav-links a.active { background: #2563eb; color: white; border-color: #2563eb; }
        
        /* Tombol Keluar / Logout */
        .btn-logout { text-decoration: none; padding: 9px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; display: flex; align-items: center; gap: 6px; }
        .btn-logout:hover { background: #ffeeee; }

        .alert { padding: 12px 18px; border-radius: 8px; font-size: 13px; font-weight: 600; margin-bottom: 20px; }
        .alert-success { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background-color: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        /* Form Area */
        .form-section { background-color: #f8fafc; padding: 25px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 30px; }
        .form-section h3 { margin: 0 0 15px 0; font-size: 13px; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; }
        .grid-inputs { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .input-group { display: flex; flex-direction: column; gap: 6px; }
        .input-group label { font-size: 12px; font-weight: 700; color: #475569; }
        .input-group input, .input-group select { padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px; outline: none; background: white; }
        .btn-submit { background-color: #2563eb; color: white; border: none; padding: 12px; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; width: 100%; transition: background 0.2s; }
        .btn-submit:hover { background-color: #1d4ed8; }

        /* Tabel Area */
        .section-title { font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 15px; }
        .table-responsive { width: 100%; border-radius: 10px; border: 1px solid #e2e8f0; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; background: white; table-layout: fixed; }
        
        th:nth-child(1) { width: 12%; }
        th:nth-child(2) { width: 26%; }
        th:nth-child(3) { width: 15%; }
        th:nth-child(4) { width: 10%; }
        th:nth-child(5) { width: 15%; }
        th:nth-child(6) { width: 17%; }
        th:nth-child(7) { width: 10%; }

        th { background-color: #1e293b; color: #f8fafc; padding: 12px 15px; font-weight: 700; text-transform: uppercase; font-size: 11px; }
        td { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; color: #334155; word-wrap: break-word; }
        tr:hover { background-color: #f8fafc; }
        
        .badge-gudang { background: #e0f2fe; color: #0369a1; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; border: 1px solid #bae6fd; display: block; text-align: center; }
        .btn-delete { background-color: #fee2e2; color: #dc2626; text-decoration: none; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 700; border: 1px solid #fca5a5; display: inline-block; }
    </style>
</head>
<body>

<div class="container">
    
    <div class="header-panel">
        <h1>📦 Log Histori Aktivitas Sistem (Forensik)</h1>
        <div class="user-badge">Pengawas: <span><?= htmlspecialchars($username) ?></span></div>
    </div>

    <div class="nav-container">
        <div class="nav-links">
            <a href="dashboard.php">🏠 Dashboard Utama</a>
            <a href="barang.php" class="active">📦 Kelola Stok Barang</a>
            <?php if ($role === 'Admin' || $role === 'Manajer Gudang'): ?>
                <a href="audit_logs.php">📜 Log Aktivitas (Audit)</a>
            <?php endif; ?>
        </div>
        <a href="logout.php" class="btn-logout">Keluar (Logout) 🚪</a>
    </div>

    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'success'): ?>
        <div class="alert alert-success">✓ Produk baru berhasil didaftarkan ke sistem!</div>
    <?php endif; ?>
    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'deleted'): ?>
        <div class="alert alert-success">✓ Data barang berhasil dihapus.</div>
    <?php endif; ?>
    <?php if (isset($error_msg)): ?>
        <div class="alert alert-error">⚠ <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <?php if (in_array($role, ['Admin', 'Manajer Gudang', 'Staf Gudang'])): ?>
    <div class="form-section">
        <h3>+ DAFTARKAN ITEM BARANG BARU</h3>
        <form action="barang.php" method="POST">
            <input type="hidden" name="action" value="tambah">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="grid-inputs">
                <div class="input-group"><label>Kode Barang</label><input type="text" name="kode_barang" placeholder="Contoh: BRG-004" required></div>
                <div class="input-group"><label>Nama Produk / Barang</label><input type="text" name="nama_barang" placeholder="Contoh: iPad Pro M4" required></div>
                <div class="input-group">
                    <label>Kategori</label>
                    <select name="kategori">
                        <option value="Elektronik">Elektronik</option>
                        <option value="Peralatan">Peralatan</option>
                        <option value="Konsumsi">Konsumsi</option>
                    </select>
                </div>
                <div class="input-group"><label>Stok Awal</label><input type="number" name="stok" value="0" min="0" required></div>
                <div class="input-group"><label>Harga Satuan (Rp)</label><input type="number" name="harga" min="0" required></div>
                <div class="input-group">
                    <label>Alokasi Lokasi Gudang</label>
                    <select name="lokasi_gudang">
                        <option value="1">Gudang Utama Barat</option>
                        <option value="2">Gudang Regional Tengah</option>
                        <option value="3">Gudang Hub Timur</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn-submit">+ Daftarkan Barang Baru</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="section-title">Daftar Inventaris Aktif:</div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Barang</th>
                    <th>Kategori</th>
                    <th>Stok</th>
                    <th>Harga Satuan</th>
                    <th>Lokasi Gudang</th>
                    <th style="text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($daftar_barang)): ?>
                    <tr><td colspan="7" style="text-align: center; padding: 20px;">Tidak ada data barang saat ini.</td></tr>
                <?php else: ?>
                    <?php foreach ($daftar_barang as $b): ?>
                        <?php
                        $harga_satuan = isset($b['harga_satuan']) ? $b['harga_satuan'] : (isset($b['harga']) ? $b['harga'] : 0);
                        $kode_barang = isset($b['kode_barang']) ? $b['kode_barang'] : (isset($b['kode']) ? $b['kode'] : '-');
                        $nama_barang = isset($b['nama_barang']) ? $b['nama_barang'] : (isset($b['nama']) ? $b['nama'] : 'Tanpa Nama');
                        
                        $g_id = isset($b['gudang_id']) ? intval($b['gudang_id']) : 0;
                        if ($g_id === 3) {
                            $lokasi_gudang = 'Gudang Hub Timur';
                        } elseif ($g_id === 2) {
                            $lokasi_gudang = 'Gudang Regional Tengah';
                        } elseif ($g_id === 1) {
                            $lokasi_gudang = 'Gudang Utama Barat';
                        } else {
                            $lokasi_gudang = isset($b['lokasi_gudang']) ? $b['lokasi_gudang'] : 'Belum Ditentukan';
                        }
                        ?>
                        <tr>
                            <td><code><?= htmlspecialchars($kode_barang) ?></code></td>
                            <td><strong><?= htmlspecialchars($nama_barang) ?></strong></td>
                            <td><?= htmlspecialchars($b['kategori'] ?? '-') ?></td>
                            <td><strong><?= number_format($b['stok'] ?? 0) ?> Unit</strong></td>
                            <td>Rp <?= number_format($harga_satuan, 0, ',', '.') ?></td>
                            <td><span class="badge-gudang">🏢 <?= htmlspecialchars($lokasi_gudang) ?></span></td>
                            <td style="text-align: center;">
                                <?php if (in_array($role, ['Admin', 'Manajer Gudang'])): ?>
                                    <a href="barang.php?hapus=<?= $b['id'] ?>" class="btn-delete" onclick="return confirm('Apakah Anda yakin ingin menghapus produk ini?')">🗑 Hapus</a>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>