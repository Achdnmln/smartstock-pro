<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. KONEKSI DATABASE
$host = 'localhost'; $port = '3306'; $db = 'smartstock_pro'; $user = 'root'; $pass = '';
try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (\PDOException $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}

// 2. TENTUKAN DATA LOG YANG AKAN DICETAK (BARANG / AUTH)
$tab_aktif = $_GET['tab'] ?? 'barang';

// Ambil Map data user terlebih dahulu untuk mencocokkan nama & role
$users_map = [];
try {
    $stmt_u = $pdo->query("SELECT id, username, role FROM users");
    while ($row_u = $stmt_u->fetch()) {
        $users_map[$row_u['id']] = ['username' => $row_u['username'], 'role' => $row_u['role']];
    }
} catch (PDOException $e) {}

// Ambil data log sesuai filter tab
$data_logs = [];
try {
    if ($tab_aktif === 'auth') {
        $judul_laporan = "LAPORAN AUDIT: AUTENTIKASI USER";
        $kategori_teks = "Log Login & Logout User";
        $stmt_log = $pdo->query("SELECT * FROM audit_logs WHERE action LIKE '%login%' OR action LIKE '%logout%' ORDER BY id DESC");
    } else {
        $judul_laporan = "LAPORAN AUDIT: MANAJEMEN BARANG";
        $kategori_teks = "Log Tambah & Hapus Stok Barang";
        $stmt_log = $pdo->query("SELECT * FROM audit_logs WHERE action LIKE '%Mendaftarkan%' OR action LIKE '%Menghapus%' ORDER BY id DESC");
    }
    $data_logs = $stmt_log->fetchAll();
} catch (PDOException $e) {
    die("Gagal mengambil data log untuk dicetak: " . $e->getMessage());
}

// 3. SETTING WAKTU CETAK REAL-TIME (WIB)
date_default_timezone_set('Asia/Jakarta');
$waktu_cetak = date('d M Y - H:i') . " WIB";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>SmartStock Pro - Print Logs Engine</title>
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; background-color: #f1f5f9; margin: 0; padding: 0; color: #1e293b; }
        .top-nav { background-color: white; padding: 15px 40px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-bottom: 1px solid #e2e8f0; }
        .breadcrumbs { font-size: 13px; color: #64748b; }
        .breadcrumbs span { color: #0f172a; font-weight: 600; }
        .nav-buttons { display: flex; gap: 12px; }
        .btn-kembali { text-decoration: none; background-color: white; color: #334155; border: 1px solid #cbd5e1; padding: 8px 18px; border-radius: 8px; font-size: 13.5px; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; }
        .btn-unduh { text-decoration: none; background-color: #10b981; color: white; padding: 8px 18px; border-radius: 8px; font-size: 13.5px; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 12px rgba(16,185,129,0.2); }
        
        .document-container { display: flex; justify-content: center; padding: 40px 20px; }
        .paper-canvas { width: 100%; max-width: 950px; background-color: white; min-height: 1100px; padding: 50px; border-radius: 16px; box-shadow: 0 4px 20px rgba(15, 23, 42, 0.05); border: 1px solid #e2e8f0; box-sizing: border-box; }
        .doc-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #10b981; padding-bottom: 25px; margin-bottom: 30px; }
        .brand-section { display: flex; align-items: center; gap: 15px; }
        .brand-logo { background-color: #10b981; color: white; font-weight: 800; font-size: 22px; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; border-radius: 8px; }
        .brand-text h2 { margin: 0; font-size: 18px; color: #0f172a; font-weight: 700; }
        .brand-text p { margin: 2px 0 0 0; font-size: 12px; color: #64748b; }
        .doc-title-area { text-align: right; }
        .doc-title-area h1 { margin: 0; font-size: 18px; font-weight: 800; color: #0f172a; letter-spacing: 0.5px; }
        .time-badge { display: inline-block; background-color: #f1f5f9; color: #475569; font-size: 11.5px; padding: 5px 12px; border-radius: 6px; margin-top: 6px; font-weight: 500; }

        .info-card { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 20px; margin-bottom: 30px; }
        .info-card span { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        .info-card h3 { margin: 4px 0 0 0; font-size: 15px; color: #0f172a; font-weight: 600; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        th { background-color: #0f172a; color: white; text-transform: uppercase; font-size: 11px; font-weight: 700; padding: 12px 10px; letter-spacing: 0.3px; text-align: left; }
        td { padding: 12px 10px; border-bottom: 1px solid #e2e8f0; color: #334155; vertical-align: middle; word-wrap: break-word; }
        .timestamp-text { font-family: monospace; color: #475569; }
        .badge-role { padding: 3px 8px; border-radius: 4px; font-weight: 700; font-size: 10px; text-transform: uppercase; border: 1px solid #cbd5e1; background: #f1f5f9; color: #475569; }
        
        /* Pewarnaan Badge dinamis agar selaras dengan halaman audit_logs */
        .badge-role.admin { background: #eff6ff; color: #1d4ed8; border-color: #dbeafe; }
        .badge-role.viewer { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }
        .badge-role.staf-gudang { background: #e6f4ea; color: #137333; border-color: #ceead6; }
        
        .ip-code { background: #f8fafc; color: #64748b; padding: 2px 6px; border-radius: 4px; font-size: 11.5px; font-family: monospace; border: 1px solid #e2e8f0; }

        @media print {
            .top-nav { display: none; }
            body { background-color: white; padding: 0; }
            .paper-canvas { box-shadow: none; border: none; padding: 0; width: 100%; max-width: 100%; }
        }
    </style>
</head>
<body>

    <div class="top-nav">
        <div class="breadcrumbs">SmartStock Pro / Laporan / <span>Export Audit Logs</span></div>
        <div class="nav-buttons">
            <a href="audit_logs.php?tab=<?= urlencode($tab_aktif) ?>" class="btn-kembali">← Kembali</a>
            <a href="#" onclick="window.print(); return false;" class="btn-unduh">📥 Unduh PDF / Cetak</a>
        </div>
    </div>

    <div class="document-container">
        <div class="paper-canvas">
            
            <div class="doc-header">
                <div class="brand-section">
                    <div class="brand-logo">SS</div>
                    <div class="brand-text">
                        <h2>SmartStock Pro</h2>
                        <p>Sistem Forensik & Rekam Aktivitas Audit</p>
                    </div>
                </div>
                <div class="doc-title-area">
                    <h1><?= htmlspecialchars($judul_laporan) ?></h1>
                    <div class="time-badge">Dicetak: <?= htmlspecialchars($waktu_cetak) ?></div>
                </div>
            </div>

            <div class="info-card">
                <span>Kategori Dokumen Rekam Jejak</span>
                <h3><?= htmlspecialchars($kategori_teks) ?> (Tahun Buku 2026)</h3>
            </div>

            <div style="border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden;">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 22%;">Waktu / Timestamp</th>
                            <th style="width: 15%;">Username</th>
                            <th style="width: 15%;">Hak Akses</th>
                            <th style="width: 35%;">Aktivitas / Tindakan Terekam</th>
                            <th style="width: 13%; text-align: center;">Alamat IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($data_logs) === 0): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 30px; color: #64748b;">Tidak ada data aktivitas audit log dalam kategori ini.</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($data_logs as $log): 
                                $val_waktu = $log['timestamp'] ?? '-';
                                $uid = $log['user_id'] ?? null;
                                if ($uid && isset($users_map[$uid])) {
                                    $val_user = $users_map[$uid]['username']; 
                                    $val_role = $users_map[$uid]['role'];
                                } else {
                                    $val_user = 'Sistem'; 
                                    $val_role = 'ADMIN';
                                }
                                $val_aksi = $log['action'] ?? '-';
                                $val_ip = '127.0.0.1';

                                // Klasifikasi style badge berdasarkan role (agar sinkron dengan UI utama)
                                $role_lc = strtolower($val_role);
                                $badge_class = 'admin'; 
                                if (strpos($role_lc, 'view') !== false) { $badge_class = 'viewer'; } 
                                elseif (strpos($role_lc, 'staf') !== false || strpos($role_lc, 'gudang') !== false) { $badge_class = 'staf-gudang'; }

                                // Pewarnaan teks aksi secara dinamis
                                $aksi_lc = strtolower($val_aksi);
                                if (strpos($aksi_lc, 'hapus') !== false || strpos($aksi_lc, 'logout') !== false) { 
                                    $style_text = "color: #dc2626; font-weight: 600;"; 
                                } elseif (strpos($aksi_lc, 'daftar') !== false || strpos($aksi_lc, 'login') !== false) { 
                                    $style_text = "color: #16a34a; font-weight: 600;"; 
                                } else { 
                                    $style_text = "color: #0f172a; font-weight: 500;"; 
                                }
                            ?>
                            <tr>
                                <td><span class="timestamp-text"><?= htmlspecialchars($val_waktu) ?></span></td>
                                <td><strong><?= htmlspecialchars($val_user) ?></strong></td>
                                <td><span class="badge-role <?= $badge_class ?>"><?= htmlspecialchars(strtoupper($val_role)) ?></span></td>
                                <td><span style="<?= $style_text ?>"><?= htmlspecialchars($val_aksi) ?></span></td>
                                <td style="text-align: center;"><span class="ip-code"><?= htmlspecialchars($val_ip) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

</body>
</html>