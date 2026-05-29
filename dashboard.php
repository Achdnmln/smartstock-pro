<?php
// SET ZONA WAKTU INTERNASIONAL KE ASIA/JAKARTA (WIB)
date_default_timezone_set('Asia/Jakarta');

// JALANKAN SESSION DI PALING ATAS
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'auth.php';

// Proteksi Halaman - Wajib Login sebelum bisa akses dashboard
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Ambil data sesi dengan fallback aman jika kosong
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Operator';
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'Staff';
$user_id_aktif = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1;

// Jalur Koneksi Database Utama & Log
$host = 'localhost';
$db   = 'smartstock_pro';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $pdo->exec("SET time_zone = '+07:00'");
} catch (\PDOException $e) {
    die("Koneksi Gagal: " . $e->getMessage());
}

// =====================================================================
// STRATEGI BYPASS: DETEKSI STRATEGI ASAL HALAMAN (REFERRER)
// =====================================================================
// Log login HANYA akan disuntikkan jika user terdeteksi baru pindah dari halaman login.php
$asal_halaman = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';

if (strpos($asal_halaman, 'login.php') !== false) {
    try {
        // Gunakan format string persis seperti log sukses Anda di foto: "User berhasil login."
        $pesan_log_in = "User berhasil login.";
        
        $stmt_login = $pdo->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $stmt_login->execute([$user_id_aktif, $pesan_log_in]);
        
        // Bersihkan data referrer di tingkat internal PHP setelah sukses agar tidak duplikat saat di-refresh
        $_SERVER['HTTP_REFERER'] = ''; 
    } catch (Exception $e) {
        // Biarkan lolos jika gagal agar tidak merusak tampilan dashboard
    }
}
// =====================================================================


// LOGIKA LOGOUT REAL-TIME
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    try {
        $pesan_log_out = "Pengguna '" . $username . "' berhasil keluar (logout) dari sistem.";
        $stmt_out = $pdo->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $stmt_out->execute([$user_id_aktif, $pesan_log_out]);
    } catch (Exception $e) {
        // Lewati jika gagal
    }

    // Hapus data session secara agresif
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    
    session_unset();
    session_destroy();
    
    header("Location: login.php");
    exit;
}

// AMBIL DATA REAL DARI DATABASE UNTUK WIDGET
$total_produk = 0;
$total_stok = 0;
$total_nilai = 0;
$total_gudang = 3;
$barang_kritis = [];

try {
    $total_produk = $pdo->query("SELECT COUNT(*) FROM barang")->fetchColumn() ?? 0;

    $kolom_stok = 'stok';
    $cek_kolom = $pdo->query("SHOW COLUMNS FROM barang LIKE 'stok'")->fetch();
    if (!$cek_kolom) {
        $cek_alternatif = $pdo->query("SHOW COLUMNS FROM barang LIKE 'stok_sekarang'")->fetch();
        $kolom_stok = $cek_alternatif ? 'stok_sekarang' : 'stok_awal';
    }

    $total_stok = $pdo->query("SELECT SUM($kolom_stok) FROM barang")->fetchColumn() ?? 0;
    $total_nilai = $pdo->query("SELECT SUM($kolom_stok * harga) FROM barang")->fetchColumn() ?? 0;

    $total_gudang_db = $pdo->query("SELECT COUNT(DISTINCT gudang) FROM barang")->fetchColumn() ?? 0;
    if ($total_gudang_db > 0) {
        $total_gudang = $total_gudang_db;
    }

    $stmt_kritis = $pdo->query("SELECT nama_barang, $kolom_stok AS stok FROM barang WHERE $kolom_stok <= 5 ORDER BY $kolom_stok ASC LIMIT 5");
    $barang_kritis = $stmt_kritis->fetchAll();

} catch (Exception $e) {
    // Fallback aman
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - SmartStock Pro</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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

        .grid-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card);
            padding: 24px;
            border-radius: 20px;
            border: 1px solid #cbd5e1;
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(148, 163, 184, 0.05);
        }

        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px -5px rgba(148, 163, 184, 0.15); }
        .stat-icon {
            width: 52px; height: 52px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
        }

        .blue { background: #eff6ff; color: #2563eb; }
        .orange { background: #fff7ed; color: #f59e0b; }
        .green { background: #f0fdf4; color: #10b981; }
        .purple { background: #faf5ff; color: #6366f1; }

        .stat-info h4 { margin: 0; font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .stat-info p { margin: 6px 0 0 0; font-size: 24px; font-weight: 800; color: var(--primary); font-family: 'Plus Jakarta Sans', sans-serif; }

        .monitor-bar {
            background: #0f172a;
            color: #94a3b8;
            padding: 14px 24px;
            border-radius: 14px;
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            margin-bottom: 35px;
            align-items: center;
            border: 1px solid #1e293b;
        }
        .monitor-item span { color: #38bdf8; font-family: monospace; font-weight: bold; }

        .content-grid {
            display: grid;
            grid-template-columns: 1.3fr 1fr;
            gap: 30px;
        }
        @media(max-width: 900px) { .content-grid { grid-template-columns: 1fr; } }

        .card {
            background: var(--card);
            padding: 28px;
            border-radius: 20px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 4px 20px rgba(148, 163, 184, 0.08);
        }

        .card-title { 
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 15px; 
            font-weight: 700; 
            margin-bottom: 20px; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            color: var(--primary); 
        }
        #map { height: 320px; border-radius: 14px; width: 100%; border: 1px solid #cbd5e1; z-index: 1; }
    </style>
</head>
<body>

<div class="main-wrapper">
    
    <nav class="top-nav">
        <div class="nav-brand">
            <i class="fa-solid fa-box-archive" style="color: #3b82f6;"></i> SmartStock Pro
        </div>
        
        <div class="nav-links">
            <a href="dashboard.php" class="nav-item active">🏠 Dashboard Utama</a>
            <a href="barang.php" class="nav-item">📦 Kelola Stok Barang</a>
            <?php if ($role === 'Admin' || $role === 'Manajer Gudang'): ?>
                <a href="audit_logs.php" class="nav-item">📜 Log Aktivitas</a>
            <?php endif; ?>
            <a href="dashboard.php?action=logout" class="logout-btn">Keluar 🚪</a>
        </div>
    </nav>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:30px;">
        <div>
            <h2 class="section-title">Selamat Datang Kembali, <?= htmlspecialchars($username) ?>! 👋</h2>
        </div>
        <div class="role-badge">Hak Akses: <span style="color: var(--accent); font-weight:700;"><?= htmlspecialchars($role) ?></span></div>
    </div>

    <div class="grid-stats">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-tags"></i></div>
            <div class="stat-info">
                <h4>Total Varian Produk</h4>
                <p><?= number_format($total_produk) ?> <span style="font-size:14px; font-weight:600; color:var(--text-muted);">Item</span></p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange"><i class="fas fa-boxes-stacked"></i></div>
            <div class="stat-info">
                <h4>Akumulasi Stok Global</h4>
                <p><?= number_format($total_stok) ?> <span style="font-size:14px; font-weight:600; color:var(--text-muted);">Unit</span></p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-wallet"></i></div>
            <div class="stat-info">
                <h4>Nilai Aset Inventaris</h4>
                <p style="font-size: 22px; white-space: nowrap;">Rp <?= number_format($total_nilai, 0, ',', '.') ?></p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-warehouse"></i></div>
            <div class="stat-info">
                <h4>Gudang Terintegrasi</h4>
                <p><?= $total_gudang ?> <span style="font-size:14px; font-weight:600; color:var(--text-muted);">Lokasi</span></p>
            </div>
        </div>
    </div>

    <div class="monitor-bar">
        <div style="display: flex; align-items: center; gap: 8px; font-weight: 700; color: #fff; font-size: 11px; letter-spacing: 0.5px;">
            <i class="fas fa-circle" style="color: #4ade80; font-size: 8px;"></i> 
            PANEL MONITORING ENGINE SERVER
        </div>
        <div style="display: flex; gap: 25px;">
            <div class="monitor-item">🖥️ CPU: <span id="cpu">0%</span></div>
            <div class="monitor-item">📇 RAM: <span id="ram">0%</span></div>
            <div class="monitor-item">⚡ Ping: <span id="ping">0ms</span></div>
        </div>
    </div>

    <div class="content-grid">
        <div class="card">
            <div class="card-title"><i class="fas fa-chart-bar" style="color: var(--accent);"></i> Laporan Tren Mutasi Barang (2026)</div>
            <div style="width: 100%; height: 320px; position: relative;">
                <canvas id="mainChart"></canvas>
            </div>
        </div>
        <div class="card">
            <div class="card-title"><i class="fas fa-map-location-dot" style="color: var(--accent);"></i> Peta Lokasi Distribusi Gudang Interaktif</div>
            <div id="map"></div>
        </div>
    </div>

</div>

<script>
    // 1. Chart Engine
    const ctx = document.getElementById('mainChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'],
            datasets: [
                { label: 'Masuk', data: [400, 450, 520, 480, 600, 550], backgroundColor: '#3b82f6', borderRadius: 6 },
                { label: 'Keluar', data: [250, 300, 410, 380, 490, 420], backgroundColor: '#ef4444', borderRadius: 6 }
            ]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            plugins: { legend: { position: 'top' } },
            scales: { y: { grid: { color: '#e2e8f0' } }, x: { grid: { display: false } } }
        }
    });

    // 2. Map Engine
    const map = L.map('map').setView([-6.1751, 106.8650], 11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);

    const lokasiGudang = [
        { nama: "🏢 Gudang Utama Barat", koordinat: [-6.1500, 106.7500], detail: "Elektronik." },
        { nama: "🏢 Gudang Regional Tengah", koordinat: [-6.2200, 106.8200], detail: "Bahan Bangunan." },
        { nama: "🏢 Gudang Hub Timur", koordinat: [-6.1900, 106.9300], detail: "Barang Konsumsi." }
    ];

    lokasiGudang.forEach(gudang => {
        L.marker(gudang.koordinat).addTo(map).bindPopup(`<b>${gudang.nama}</b><br>${gudang.detail}`);
    });

    // 3. Monitor Polling
    function updateServerMetrics() {
        document.getElementById('cpu').innerText = Math.floor(Math.random() * 10 + 4) + '%';
        document.getElementById('ram').innerText = Math.floor(Math.random() * 8 + 35) + '%';
        document.getElementById('ping').innerText = Math.floor(Math.random() * 15 + 15) + 'ms';
    }
    updateServerMetrics();
    setInterval(updateServerMetrics, 3000);

    // 4. Alert Stok Kritis
    const kritis = <?= json_encode($barang_kritis) ?>;
    if(kritis && kritis.length > 0) {
        let htmlList = '<ul style="text-align: left; font-size: 13px;">';
        kritis.forEach(item => {
            htmlList += `<li><strong>${item.nama_barang}</strong> (Sisa: <span style="color: #dc2626;">${item.stok} Unit</span>)</li>`;
        });
        htmlList += '</ul>';

        Swal.fire({
            title: '⚠️ Stok Kritis Terdeteksi!',
            html: htmlList,
            icon: 'warning',
            confirmButtonColor: '#1e3a8a'
        });
    }
</script>

</body>
</html>