<?php
// SET ZONA WAKTU INTERNASIONAL KE ASIA/JAKARTA (WIB)
date_default_timezone_set('Asia/Jakarta');

require_once 'auth.php';

$error = '';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

// JALUR AMAN: Inisialisasi ulang koneksi database jika belum ada
$host = 'localhost';
$db   = 'smartstock_pro';
$user = 'root';
$pass = '';

try {
    if (!isset($pdo) || $pdo === null) {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    $pdo->exec("SET time_zone = '+07:00'");
} catch (\PDOException $e) {
    die("Koneksi Gagal di Halaman Login: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error = 'Username dan Password wajib diisi!';
    } else {
        // Ambil data user berdasarkan username
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user_data = $stmt->fetch();

        if ($user_data) {
            // ---------------------------------------------------------
            // [OTOMATIS 1] DETEKSI NAMA KOLOM ID USER DI DATABASE
            // ---------------------------------------------------------
            $id_user_terdeteksi = null;
            if (isset($user_data['id'])) {
                $id_user_terdeteksi = $user_data['id'];
            } elseif (isset($user_data['id_user'])) {
                $id_user_terdeteksi = $user_data['id_user'];
            } elseif (isset($user_data['user_id'])) {
                $id_user_terdeteksi = $user_data['user_id'];
            } else {
                // Fallback: Ambil kolom pertama dari tabel users (biasanya ID)
                $user_primaries = array_values($user_data);
                $id_user_terdeteksi = $user_primaries[0];
            }

            // ---------------------------------------------------------
            // [OTOMATIS 2] DETEKSI NAMA KOLOM PASSWORD DI DATABASE
            // ---------------------------------------------------------
            $db_password_hash = '';
            if (isset($user_data['password'])) {
                $db_password_hash = $user_data['password'];
            } elseif (isset($user_data['pass'])) {
                $db_password_hash = $user_data['pass'];
            } elseif (isset($user_data['pwd'])) {
                $db_password_hash = $user_data['pwd'];
            } else {
                $user_values = array_values($user_data);
                $db_password_hash = $user_values[2]; 
            }

            // Verifikasi password
            if (password_verify($password, $db_password_hash)) {
                
                // Set Session Utama
                $_SESSION['user_id'] = $id_user_terdeteksi;
                $_SESSION['username'] = $user_data['username'];
                $_SESSION['role'] = isset($user_data['role']) ? $user_data['role'] : 'Staff';
                $_SESSION['terakhir_aktivitas'] = time();

                // =========================================================
                // FIXED REAL-TIME LOG LOGIN (DIKOREKSI TOTAL)
                // =========================================================
                try {
                    $pesan_log = "Pengguna '" . $user_data['username'] . "' berhasil masuk ke sistem.";
                    
                    // Kita tembak langsung menggunakan variabel ID yang sudah lolos deteksi otomatis
                    $stmt_log_login = $pdo->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
                    $stmt_log_login->execute([$id_user_terdeteksi, $pesan_log]);

                } catch (PDOException $e) {
                    // JIKA GAGAL: Langsung matikan program dan munculkan error aslinya agar ketahuan rusaknya apa!
                    die("Gagal mencatat log login! Error dari MySQL: " . $e->getMessage());
                }
                // =========================================================

                header("Location: dashboard.php");
                exit;
            } else {
                $error = "Password salah untuk username '" . htmlspecialchars($username) . "'.";
            }
        } else {
            $error = "Username '" . htmlspecialchars($username) . "' tidak ditemukan di database!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Login - SmartStock Pro</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background-color: #0f172a; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-card { background: #ffffff; padding: 40px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); width: 100%; max-width: 400px; box-sizing: border-box; }
        h2 { text-align: center; color: #1e293b; margin: 0 0 8px 0; font-weight: 700; }
        .sub { text-align: center; color: #64748b; font-size: 14px; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: #334155; font-weight: 600; font-size: 14px; }
        input { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; font-size: 14px; }
        .btn { width: 100%; padding: 12px; background: #2563eb; color: white; border: none; border-radius: 8px; font-weight: 600; font-size: 15px; cursor: pointer; margin-top: 10px; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 8px; font-size: 14px; margin-bottom: 20px; border: 1px solid #fca5a5; text-align: center; }
        .alert-timeout { background: #f59e0b; color: white; padding: 12px; border-radius: 8px; font-size: 14px; margin-bottom: 20px; text-align: center; font-weight: 600; }
    </style>
</head>
<body>

<div class="login-card">
    <h2>SmartStock Pro</h2>
    <div class="sub">Logistik & Manajemen Inventaris Multi-Gudang</div>

    <?php if (!empty($error)): ?>
        <div class="alert-error"><?= $error ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['pesan']) && $_GET['pesan'] == 'timeout'): ?>
        <div class="alert-timeout">⚠️ Sesi Anda telah berakhir. Silakan login kembali!</div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" placeholder="Masukkan username" required autocomplete="off">
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn">Masuk Ke Sistem →</button>
    </form>
</div>

</body>
</html>