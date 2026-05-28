<?php
require_once 'auth.php';

// Proteksi Halaman: Pastikan hanya Admin dan Manajer Gudang yang bisa masuk
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'Manajer Gudang'])) {
    header("Location: dashboard.php");
    exit;
}

$username = $_SESSION['username'];
$role = $_SESSION['role'];

// Koneksi Database ke smartstock_pro
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

// Ambil data log histori aktivitas dikombinasikan dengan nama user pencatatnya
try {
    // Cek kolom asli di tabel audit_logs untuk menghindari salah nama kolom
    $stmt_cols = $pdo->query("SHOW COLUMNS FROM audit_logs");
    $columns = $stmt_cols->fetchAll(PDO::FETCH_COLUMN);
    
    // Tentukan nama kolom timestamp yang dipakai (waktu / dibuat_pada / timestamp)
    $col_time = in_array('waktu', $columns) ? 'waktu' : (in_array('created_at', $columns) ? 'created_at' : 'timestamp');

    $sql = "SELECT a.*, u.username, u.role 
            FROM audit_logs a 
            LEFT JOIN users u ON a.user_id = u.id 
            ORDER BY a.id DESC";
            
    $stmt_log = $pdo->query($sql);
    $logs = $stmt_log->fetchAll();
} catch (PDOException $e) {
    die("Gagal mengambil data log aktivitas: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Histori Aktivitas Sistem (Forensik Log)</title>
    <style>
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; margin: 0; padding: 30px; background-color: #f1f5f9; display: flex; justify-content: center; }
        .container { background: white; padding: 35px; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.05); width: 100%; max-width: 1100px; box-sizing: border-box; }
        
        /* Font Judul disamakan persis dengan Halaman Dashboard Utama */
        .header-panel { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 2px solid #f1f5f9; padding-bottom: 20px; }
        .header-panel h1 { margin: 0; font-size: 26px; font-weight: 700; color: #0f172a; letter-spacing: -0.5px; }
        .user-badge { background: #e0f2fe; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; color: #0369a1; border: 1px solid #bae6fd; }
        .user-badge span { color: #2563eb; font-weight: 700; }

        /* Menu Navigasi Lengkap Tanpa Ada yang Dihilangkan */
        .nav-container { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; width: 100%; }
        .nav-links { display: flex; gap: 10px; align-items: center; }
        .nav-links a { text-decoration: none; padding: 9px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; transition: all 0.2s; display: flex; align-items: center; gap: 6px; }
        .nav-links a:hover { background: #f1f5f9; }
        .nav-links a.active { background: #2563eb; color: white; border-color: #2563eb; }
        
        /* Tombol Keluar / Logout */
        .btn-logout { text-decoration: none; padding: 9px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; display: flex; align-items: center; gap: 6px; transition: background 0.2s; }
        .btn-logout:hover { background: #ffeeee; }

        /* Tabel Area Log */
        .table-responsive { width: 100%; border-radius: 10px; border: 1px solid #e2e8f0; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: left; background: white; table-layout: fixed; }
        
        th:nth-child(1) { width: 18%; } /* Waktu */
        th:nth-child(2) { width: 15%; } /* Username */
        th:nth-child(3) { width: 15%; } /* Hak Akses / Role */
        th:nth-child(4) { width: 40%; } /* Aktivitas Terrekam */
        th:nth-child(5) { width: 12%; } /* Alamat IP */

        th { background-color: #1e293b; color: #f8fafc; padding: 14px 18px; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        td { padding: 14px 18px; border-bottom: 1px solid #f1f5f9; color: #334155; word-wrap: break-word; line-height: 1.5; }
        tr:hover { background-color: #f8fafc; }
        
        .badge-role { background: #f1f5f9; color: #475569; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; border: 1px solid #cbd5e1; display: inline-block; }
        .badge-role.admin { background: #e0f2fe; color: #2563eb; border-color: #bfdbfe; }
        .badge-role.manajer { background: #fef3c7; color: #d97706; border-color: #fde68a; }
        
        .ip-text { background: #f8fafc; color: #64748b; font-family: monospace; padding: 2px 6px; border-radius: 4px; border: 1px solid #e2e8f0; font-size: 12px; }
        .time-text { font-weight: 600; color: #1e293b; }
    </style>
</head>
<body>

<div class="container">
    
    <div class="header-panel">
        <h1>📜 Log Histori Aktivitas Sistem (Forensik Log)</h1>
        <div class="user-badge">Pengawas Aktif: <span><?= htmlspecialchars($username) ?></span></div>
    </div>

    <div class="nav-container">
        <div class="nav-links">
            <a href="dashboard.php">🏠 Dashboard Utama</a>
            <a href="barang.php">📦 Kelola Stok Barang</a>
            <a href="audit_logs.php" class="active">📜 Log Aktivitas (Audit)</a>
        </div>
        <a href="logout.php" class="btn-logout">Keluar (Logout) 🚪</a>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Waktu / Timestamp</th>
                    <th>Username</th>
                    <th>Hak Akses / Role</th>
                    <th>Aktivitas Terrekam</th>
                    <th style="text-align: center;">Alamat IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="5" style="text-align: center; padding: 30px; color: #94a3b8;">Belum ada riwayat aktivitas sistem yang terekam.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <?php
                        // Solusi Pintar: Validasi nama key array pembacaan aktivitas log agar bebas dari error Undefined Index
                        $aktivitas = isset($l['aktivitas']) ? $l['aktivitas'] : (isset($l['activity']) ? $l['activity'] : (isset($l['action']) ? $l['action'] : 'Aktivitas tidak diketahui'));
                        $timestamp = isset($l['waktu']) ? $l['waktu'] : (isset($l['created_at']) ? $l['created_at'] : ($l['timestamp'] ?? '-'));
                        $ip_address = isset($l['ip_address']) ? $l['ip_address'] : ($l['ip'] ?? '127.0.0.1');
                        
                        $user_log = htmlspecialchars($l['username'] ?? 'Sistem / Anonim');
                        $role_log = htmlspecialchars($l['role'] ?? 'System');
                        
                        // Menentukan kelas warna badge berdasarkan role
                        $role_class = '';
                        if (strtoupper($role_log) === 'ADMIN') $role_class = 'admin';
                        elseif (strtoupper($role_log) === 'MANAJER GUDANG') $role_class = 'manajer';
                        ?>
                        <tr>
                            <td class="time-text"><?= htmlspecialchars($timestamp) ?></td>
                            <td><strong><?= $user_log ?></strong></td>
                            <td><span class="badge-role <?= $role_class ?>"><?= $role_log ?></span></td>
                            <td><?= htmlspecialchars($aktivitas) ?></td>
                            <td style="text-align: center;"><span class="ip-text"><?= htmlspecialchars($ip_address) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>