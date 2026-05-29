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
$user_id_aktif = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1;

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
    
    // Paksa MySQL menggunakan Zona Waktu Asia/Jakarta (+07:00)
    $pdo->exec("SET time_zone = '+07:00'");
} catch (\PDOException $e) {
    die("Koneksi Gagal: " . $e->getMessage());
}

// =====================================================================
// FIXED LOGIKA LOGOUT REAL-TIME (Disesuaikan tanpa kolom ip_address)
// =====================================================================
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    try {
        // Membuat pesan log dinamis berdasarkan user yang sedang logout
        $pesan_log_out = "User berhasil logout.";
        
        // Memasukkan log hanya ke kolom user_id dan action (Sesuai struktur baru)
        $stmt_out = $pdo->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $stmt_out->execute([$user_id_aktif, $pesan_log_out]);
    } catch (Exception $e) {
        // Tetap biarkan proses logout berjalan walaupun log eksternal gagal
    }

    // Bersihkan semua session aplikasi
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header("Location: login.php");
    exit;
}
// =====================================================================

$tab_aktif = $_GET['tab'] ?? 'barang';

// MAP DATA USER UNTUK FILTER LOG
$users_map = [];
try {
    $stmt_u = $pdo->query("SELECT id, username, role FROM users");
    while ($row_u = $stmt_u->fetch()) {
        $users_map[$row_u['id']] = ['username' => $row_u['username'], 'role' => $row_u['role']];
    }
} catch (PDOException $e) {}

// AMBIL DATA LOG BERDASARKAN SUB-TAB AKTIF
$data_logs = [];
try {
    if ($tab_aktif === 'auth') {
        $stmt_log = $pdo->query("SELECT * FROM audit_logs WHERE LOWER(action) LIKE '%login%' OR LOWER(action) LIKE '%logout%' ORDER BY id DESC");
    } else {
        $stmt_log = $pdo->query("SELECT * FROM audit_logs WHERE LOWER(action) LIKE '%mendaftarkan%' OR LOWER(action) LIKE '%menghapus%' OR LOWER(action) LIKE '%tambah%' OR LOWER(action) LIKE '%hapus%' ORDER BY id DESC");
    }
    $data_logs = $stmt_log->fetchAll();
} catch (PDOException $e) {
    die("Gagal mengambil data log: " . $e->getMessage());
}

// ENDPOINT KHUSUS UNTUK MENYUPLAI DATA REALTIME KE AJAX
if (isset($_GET['api']) && $_GET['api'] === 'fetch') {
    header('Content-Type: text/html; charset=utf-8');
    if (count($data_logs) === 0): ?>
        <tr><td colspan="5" style="text-align:center; padding: 30px;">Tidak ada catatan riwayat log pada kategori ini.</td></tr>
    <?php else: 
        foreach ($data_logs as $log): 
            $val_waktu = $log['timestamp'] ?? '-';
            $uid = $log['user_id'] ?? null;
            
            if ($uid && isset($users_map[$uid])) {
                $val_user = $users_map[$uid]['username']; 
                $val_role = $users_map[$uid]['role'];
            } else {
                $val_user = 'Sistem'; 
                $val_role = 'Admin';
            }
            
            $val_aksi = $log['action'] ?? '-';
            $val_ip = (!empty($log['ip_address'])) ? $log['ip_address'] : '127.0.0.1';
            
            $aksi_lc = strtolower($val_aksi);
            if (strpos($aksi_lc, 'hapus') !== false || strpos($aksi_lc, 'logout') !== false) {
                $style_text = "color: #dc2626; font-weight: 600;";
            } elseif (strpos($aksi_lc, 'daftar') !== false || strpos($aksi_lc, 'tambah') !== false || strpos($aksi_lc, 'login') !== false) {
                $style_text = "color: #16a34a; font-weight: 600;";
            } else {
                $style_text = "color: var(--text-dark); font-weight: 500;";
            }
        ?>
            <tr>
                <td style="color: var(--text-muted); font-family: monospace; font-size:12.5px;"><?= htmlspecialchars($val_waktu) ?></td>
                <td><strong><?= htmlspecialchars($val_user) ?></strong></td>
                <td><span class="sku-box"><?= htmlspecialchars(strtoupper($val_role)) ?></span></td>
                <td style="text-align: left; padding-left: 20px; <?= $style_text ?>"><?= htmlspecialchars($val_aksi) ?></td>
                <td><span class="ip-box"><?= htmlspecialchars($val_ip) ?></span></td>
            </tr>
        <?php endforeach; 
    endif;
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Aktivitas - SmartStock Pro</title>
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
        }
        
        .action-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 15px; flex-wrap: wrap; }
        .sub-tab-container { display: flex; gap: 8px; background: #e2e8f0; padding: 6px; border-radius: 14px; width: fit-content; }
        .sub-tab-btn { text-decoration: none; padding: 8px 16px; border-radius: 10px; font-size: 13px; font-weight: 600; color: var(--text-dark); transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .sub-tab-btn.active { background: var(--card); color: var(--primary); box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        
        .btn-pdf { text-decoration: none; background: #e0f2fe; color: #0369a1; padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; border: 1px solid #bae6fd; font-family: 'Inter', sans-serif; }
        
        .table-card { background: var(--card); padding: 25px; border-radius: 20px; border: 1px solid #cbd5e1; overflow-x: auto; box-shadow: 0 4px 20px rgba(148, 163, 184, 0.08); }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        th { background: #f1f5f9; padding: 16px 14px; color: var(--primary); font-weight: 700; border-bottom: 2px solid #cbd5e1; text-transform: uppercase; font-size: 11px; text-align: center; }
        td { padding: 16px 14px; border-bottom: 1px solid #e2e8f0; color: var(--text-dark); vertical-align: middle; text-align: center; line-height: 1.5; }
        tr:hover { background-color: #f8fafc; }
        
        .sku-box { font-family: 'Inter', monospace; font-weight: 700; color: var(--accent); background: #eff6ff; padding: 5px 10px; border-radius: 6px; display: inline-block; border: 1px solid #dbeafe; font-size: 11px; }
        .ip-box { font-family: monospace; font-weight: 600; color: var(--text-muted); background: #f1f5f9; padding: 4px 8px; border-radius: 6px; border: 1px solid #e2e8f0; font-size: 12px; }
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
            <a href="barang.php" class="nav-item">📦 Kelola Stok Barang</a>
            <a href="audit_logs.php" class="nav-item active">📜 Log Aktivitas</a>
            <a href="audit_logs.php?action=logout" class="logout-btn">Keluar 🚪</a>
        </div>
    </nav>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
        <h1 class="section-title">Log Histori Aktivitas Sistem (WIB)</h1>
        <div class="role-badge">Operator: <strong><?= htmlspecialchars($username) ?></strong></div>
    </div>

    <div class="action-row">
        <div class="sub-tab-container">
            <a href="?tab=barang" class="sub-tab-btn <?= $tab_aktif === 'barang' ? 'active' : '' ?>">📦 Kelola Tambah & Hapus Barang</a>
            <a href="?tab=auth" class="sub-tab-btn <?= $tab_aktif === 'auth' ? 'active' : '' ?>">🔑 Log Login & Logout User</a>
        </div>
        
        <a href="export_logs_pdf.php?tab=<?= urlencode($tab_aktif) ?>" class="btn-pdf">
            <i class="fa-solid fa-print"></i> Cetak PDF Log <?= $tab_aktif === 'auth' ? 'Autentikasi' : 'Barang' ?>
        </a>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th style="width: 18%;">Waktu / Timestamp (WIB)</th>
                    <th style="width: 12%;">Username</th>
                    <th style="width: 13%;">Hak Akses / Role</th>
                    <th style="text-align: left; padding-left: 20px;">Aktivitas Terekam</th>
                    <th style="width: 12%;">Alamat IP</th>
                </tr>
            </thead>
            <tbody id="logTableBody">
                <?php
                // Tampilan awal saat halaman di-load pertama kali
                if (count($data_logs) === 0): ?>
                    <tr><td colspan="5" style="text-align:center; padding: 30px;">Tidak ada catatan riwayat log pada kategori ini.</td></tr>
                <?php else: ?>
                    <?php foreach ($data_logs as $log): 
                        $val_waktu = $log['timestamp'] ?? '-';
                        $uid = $log['user_id'] ?? null;
                        
                        if ($uid && isset($users_map[$uid])) {
                            $val_user = $users_map[$uid]['username']; 
                            $val_role = $users_map[$uid]['role'];
                        } else {
                            $val_user = 'Sistem'; 
                            $val_role = 'Admin';
                        }
                        
                        $val_aksi = $log['action'] ?? '-';
                        $val_ip = (!empty($log['ip_address'])) ? $log['ip_address'] : '127.0.0.1';
                        
                        $aksi_lc = strtolower($val_aksi);
                        if (strpos($aksi_lc, 'hapus') !== false || strpos($aksi_lc, 'logout') !== false) {
                            $style_text = "color: #dc2626; font-weight: 600;";
                        } elseif (strpos($aksi_lc, 'daftar') !== false || strpos($aksi_lc, 'tambah') !== false || strpos($aksi_lc, 'login') !== false) {
                            $style_text = "color: #16a34a; font-weight: 600;";
                        } else {
                            $style_text = "color: var(--text-dark); font-weight: 500;";
                        }
                    ?>
                        <tr>
                            <td style="color: var(--text-muted); font-family: monospace; font-size:12.5px;"><?= htmlspecialchars($val_waktu) ?></td>
                            <td><strong><?= htmlspecialchars($val_user) ?></strong></td>
                            <td><span class="sku-box"><?= htmlspecialchars(strtoupper($val_role)) ?></span></td>
                            <td style="text-align: left; padding-left: 20px; <?= $style_text ?>"><?= htmlspecialchars($val_aksi) ?></td>
                            <td><span class="ip-box"><?= htmlspecialchars($val_ip) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        const currentTab = "<?= htmlspecialchars($tab_aktif) ?>";
        const logTableBody = document.getElementById("logTableBody");

        function fetchRealtimeLogs() {
            fetch(`audit_logs.php?tab=${currentTab}&api=fetch`)
                .then(response => {
                    if (!response.ok) throw new Error("Koneksi bermasalah");
                    return response.text();
                })
                .then(htmlData => {
                    logTableBody.innerHTML = htmlData;
                })
                .catch(error => console.error("Sinkronisasi Log Bermasalah:", error));
        }

        // Jalankan polling asinkron setiap 3 detik
        setInterval(fetchRealtimeLogs, 3000);
    });
</script>

</body>
</html>